<?php

namespace App\Services\Facturacion;

use App\Enums\EstadoComprobante;
use App\Enums\Permiso;
use App\Enums\TipoComprobante;
use App\Enums\TipoDocumentoIdentidad;
use App\Events\ComprobanteAceptado;
use App\Jobs\EnviarComprobanteJob;
use App\Models\Comprobante;
use App\Models\Empresa;
use App\Models\LogEnvio;
use App\Models\Bitacora;
use App\Models\Venta;
use App\Services\Ventas\VentaService;
use App\Support\Permisos;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmisionService
{
    public function __construct(private CalculadoraTotales $calculadora) {}

    /** Recalcula en el servidor los montos de cada ítem y del comprobante (nunca confiar en el navegador). */
    public function recalcular(Comprobante $comprobante): Comprobante
    {
        return DB::transaction(function () use ($comprobante) {
            $items = [];

            foreach ($comprobante->items()->get() as $item) {
                $calculo = $this->calculadora->calcularItem(
                    $item->cantidad,
                    $item->precio_unitario,
                    $item->tipo_afectacion_igv,
                );
                $item->update($calculo);
                $items[] = ['tipo_afectacion_igv' => $item->tipo_afectacion_igv] + $calculo;
            }

            $comprobante->update($this->calculadora->calcularTotales($items));

            return $comprobante->refresh();
        });
    }

    /** Valida reglas SUNAT, asigna correlativo y manda el comprobante a la cola de envío. */
    public function emitir(Comprobante $comprobante): Comprobante
    {
        if (! $comprobante->estado->puedeEnviarse()) {
            throw ValidationException::withMessages([
                'estado' => "El comprobante está en estado {$comprobante->estado->getLabel()} y no puede enviarse.",
            ]);
        }

        // Quién puede emitir qué (las notas de crédito/débito son la vía típica de fraude)
        Permisos::exigir(
            $comprobante->tipo_comprobante->esNota() ? Permiso::EmitirNotas : Permiso::EmitirVentas,
            $comprobante->empresa,
            $comprobante->tipo_comprobante->esNota()
                ? 'Solo un administrador puede emitir notas de crédito o débito (anulaciones, devoluciones y descuentos).'
                : 'Tu usuario no tiene permiso para emitir comprobantes.',
        );

        if (! $comprobante->correlativo) {
            $this->recalcular($comprobante); // en un reintento ya está congelado: no se recalcula
        }
        $this->validar($comprobante);
        $this->validarConexion($comprobante);

        DB::transaction(function () use ($comprobante) {
            if (! $comprobante->correlativo) {
                $comprobante->correlativo = $comprobante->serieRelacion->siguienteCorrelativo();
            }
            $comprobante->estado = EstadoComprobante::Pendiente;
            $comprobante->save();
        }, attempts: 3);

        Bitacora::registrar('emitió', 'Emitió '.$comprobante->etiquetaBitacora().' por '.$comprobante->total, $comprobante);

        EnviarComprobanteJob::dispatch($comprobante->id)->afterCommit();

        return $comprobante->refresh();
    }

    /** Ejecuta el envío real (lo llama el Job). */
    public function enviar(Comprobante $comprobante): RespuestaPse
    {
        $provider = $this->provider($comprobante->empresa);
        $comprobante->increment('intentos_envio');

        try {
            $respuesta = $provider->enviar($comprobante);
        } catch (\Throwable $e) {
            LogEnvio::create([
                'comprobante_id' => $comprobante->id,
                'accion' => 'enviar',
                'proveedor' => $provider->nombre(),
                'response' => ['error' => $e->getMessage()],
                'exitoso' => false,
            ]);
            $comprobante->update([
                'estado' => EstadoComprobante::Error,
                'sunat_mensaje' => 'Error de comunicación: '.$e->getMessage(),
            ]);

            throw $e;
        }

        LogEnvio::create([
            'comprobante_id' => $comprobante->id,
            'accion' => 'enviar',
            'proveedor' => $provider->nombre(),
            'http_status' => $respuesta->httpStatus,
            'request' => $respuesta->request,
            'response' => $respuesta->response,
            'exitoso' => $respuesta->exitoso(),
        ]);

        $comprobante->update([
            'estado' => $respuesta->estado,
            'simulado' => $provider->nombre() === 'fake',
            'sunat_codigo' => $respuesta->codigo,
            'sunat_mensaje' => $respuesta->mensaje,
            'hash' => $respuesta->hash,
            'enlace_pdf' => $respuesta->enlacePdf,
            'enlace_xml' => $respuesta->enlaceXml,
            'enlace_cdr' => $respuesta->enlaceCdr,
            'enviado_at' => now(),
        ]);

        if ($respuesta->exitoso()) {
            ComprobanteAceptado::dispatch($comprobante);
        }

        return $respuesta;
    }

    public function provider(Empresa $empresa): PseProvider
    {
        $nombre = $empresa->pse_proveedor ?: config('facturacion.proveedor_defecto');
        $clase = config("facturacion.proveedores.{$nombre}")
            ?? throw new \InvalidArgumentException("Proveedor PSE desconocido: {$nombre}");

        return app($clase);
    }

    /**
     * Protecciones sobre el proveedor de pruebas ("simulado"), que NO envía nada a SUNAT:
     *  - no se puede usar en un servidor de producción;
     *  - no se puede mezclar con comprobantes reales: antes de emitir de verdad
     *    hay que borrar los datos de prueba (así la numeración real empieza limpia).
     */
    public function validarConexion(Comprobante $c): void
    {
        $empresa = $c->empresa;

        if ($empresa->usaSimulador()) {
            if (! config('facturacion.permitir_simulador')) {
                throw ValidationException::withMessages([
                    'pse' => 'La empresa no tiene configurada la conexión con el PSE. Configúrala en "Datos de la empresa" antes de emitir.',
                ]);
            }

            return;
        }

        if ($this->tieneDatosDePrueba($empresa)) {
            throw ValidationException::withMessages([
                'pse' => 'Esta empresa todavía tiene comprobantes de prueba. Bórralos en "Datos de la empresa" antes de emitir comprobantes reales.',
            ]);
        }
    }

    public function tieneDatosDePrueba(Empresa $empresa): bool
    {
        return Comprobante::query()->where('empresa_id', $empresa->id)->where('simulado', true)->exists();
    }

    /**
     * Elimina todo lo emitido con el simulador y devuelve cada serie al último número REAL.
     * Es la única operación que puede borrar comprobantes con número, y queda en la bitácora.
     */
    public function borrarDatosDePrueba(Empresa $empresa): int
    {
        Permisos::exigir(Permiso::GestionarEmpresa, $empresa);

        return DB::transaction(function () use ($empresa) {
            $cantidad = Comprobante::sinProteccion(function () use ($empresa) {
                $simulados = Comprobante::query()->where('empresa_id', $empresa->id)->where('simulado', true);
                $ids = $simulados->pluck('id');

                // Notas reales nunca pueden colgar de un comprobante de prueba, pero por seguridad:
                Comprobante::query()->whereIn('comprobante_referencia_id', $ids)->where('simulado', false)
                    ->update(['comprobante_referencia_id' => null]);

                $ventas = Comprobante::query()->whereIn('id', $ids)->pluck('venta_id')->filter()->unique();
                Comprobante::query()->whereIn('id', $ids)->delete(); // los ítems y logs caen en cascada

                foreach (Venta::query()->whereIn('id', $ventas)->get() as $venta) {
                    app(VentaService::class)->recalcular($venta);
                }

                return $ids->count();
            });

            foreach ($empresa->series as $serie) {
                $ultimoReal = (int) Comprobante::query()->where('serie_id', $serie->id)->max('correlativo');
                $serie->forceFill(['correlativo' => $ultimoReal])->saveQuietly();
            }

            Bitacora::registrar('eliminó', "Borró {$cantidad} comprobantes de prueba y reinició la numeración", $empresa);

            return $cantidad;
        });
    }

    /** Reglas de negocio y de SUNAT que se revisan antes de enviar. */
    public function validar(Comprobante $c): void
    {
        $c->loadMissing(['empresa', 'cliente', 'items', 'serieRelacion', 'referencia']);
        $errores = [];
        $tipo = $c->tipo_comprobante;

        // ── Pertenencia: todo debe ser de la misma empresa ──
        if ($c->cliente->empresa_id !== $c->empresa_id) {
            $errores['cliente_id'] = 'El cliente no pertenece a esta empresa.';
        }
        if ($c->serieRelacion->empresa_id !== $c->empresa_id) {
            $errores['serie_id'] = 'La serie no pertenece a esta empresa.';
        }
        if ($c->referencia && $c->referencia->empresa_id !== $c->empresa_id) {
            $errores['comprobante_referencia_id'] = 'El comprobante de referencia no pertenece a esta empresa.';
        }

        // ── Régimen tributario: el Nuevo RUS solo emite boletas (y notas de boletas) ──
        if (! $c->empresa->permiteFacturas()
            && ($tipo === TipoComprobante::Factura || str_starts_with($c->serie, 'F'))) {
            $errores['tipo_comprobante'] = 'Tu empresa está en el Nuevo RUS: solo puede emitir boletas de venta, no facturas.';
        }

        // ── Serie ──
        if ($c->serieRelacion->tipo_comprobante !== $tipo) {
            $errores['serie_id'] = 'La serie no corresponde al tipo de comprobante.';
        } elseif (! $c->serieRelacion->activo && ! $c->correlativo) {
            $errores['serie_id'] = "La serie {$c->serie} está desactivada.";
        }

        // ── Fechas ──
        $dias = (int) config('facturacion.dias_maximos_envio', 3);
        if ($c->fecha_emision->isAfter(today())) {
            $errores['fecha_emision'] = 'La fecha de emisión no puede ser futura.';
        } elseif (! $c->correlativo && $c->fecha_emision->lt(today()->subDays($dias))) {
            // Solo para borradores: si se guardó hace días hay que actualizar la fecha antes de emitir
            $errores['fecha_emision'] = "La fecha de emisión no puede tener más de {$dias} días de antigüedad. Actualízala antes de emitir.";
        }

        if ($c->forma_pago === 'Credito') {
            if (! $c->fecha_vencimiento) {
                $errores['fecha_vencimiento'] = 'Las ventas al crédito requieren fecha de vencimiento.';
            } elseif (! $c->fecha_vencimiento->isAfter($c->fecha_emision)) {
                $errores['fecha_vencimiento'] = 'La fecha de vencimiento debe ser posterior a la de emisión.';
            }
        }

        // ── Ítems ──
        if ($c->items->isEmpty()) {
            $errores['items'] = 'El comprobante debe tener al menos un ítem.';
        }
        foreach ($c->items as $item) {
            if (BigDecimal::of($item->cantidad)->isLessThanOrEqualTo(0)) {
                $errores['items'] = "La cantidad de \"{$item->descripcion}\" debe ser mayor a cero.";
            } elseif (! $item->tipo_afectacion_igv->esGratuito() && BigDecimal::of($item->precio_unitario)->isLessThanOrEqualTo(0)) {
                $errores['items'] = "\"{$item->descripcion}\" tiene precio cero. Si es un regalo, márcalo como Gratuito.";
            }
        }

        // ── Cliente según tipo de comprobante ──
        $doc = $c->cliente->tipo_documento;
        $esDeFactura = $tipo === TipoComprobante::Factura
            || ($tipo->esNota() && str_starts_with($c->serie, 'F'));

        if ($esDeFactura && $doc !== TipoDocumentoIdentidad::Ruc) {
            $errores['cliente_id'] = $tipo === TipoComprobante::Factura
                ? 'Una factura solo puede emitirse a un cliente con RUC.'
                : 'Una nota de una factura solo puede emitirse a un cliente con RUC.';
        }

        if ($tipo === TipoComprobante::Boleta
            && $doc === TipoDocumentoIdentidad::SinDocumento
            && BigDecimal::of($c->total)->isGreaterThan(config('facturacion.boleta_monto_identificacion'))) {
            $errores['cliente_id'] = 'Las boletas mayores a S/ 700 deben identificar al cliente (DNI u otro documento).';
        }

        // ── Notas de crédito / débito ──
        if ($tipo->esNota()) {
            $ref = $c->referencia;

            if (! $ref) {
                $errores['comprobante_referencia_id'] = 'La nota debe referenciar un comprobante.';
            } elseif (! in_array($ref->tipo_comprobante, [TipoComprobante::Factura, TipoComprobante::Boleta], true)) {
                $errores['comprobante_referencia_id'] = 'Solo se pueden emitir notas sobre facturas o boletas.';
            } elseif (! in_array($ref->estado, [EstadoComprobante::Aceptado, EstadoComprobante::Observado], true)) {
                $errores['comprobante_referencia_id'] = 'El comprobante de referencia debe estar aceptado por SUNAT.';
            } else {
                if ($ref->serie[0] !== $c->serie[0]) {
                    $errores['serie_id'] = 'La serie de la nota debe empezar con la misma letra que el comprobante de referencia ('.$ref->serie[0].').';
                }
                if ($ref->cliente_id !== $c->cliente_id) {
                    $errores['cliente_id'] = 'La nota debe emitirse al mismo cliente del comprobante de referencia.';
                }
                if ($ref->moneda !== $c->moneda) {
                    $errores['moneda'] = 'La nota debe estar en la misma moneda que el comprobante de referencia.';
                }
                if ($tipo === TipoComprobante::NotaCredito) {
                    $saldo = $this->saldoAcreditable($ref, excluir: $c);
                    if (BigDecimal::of($c->total)->isGreaterThan($saldo)) {
                        $errores['total'] = "El total de la nota ({$c->total}) supera el saldo que se puede acreditar de {$ref->numero} ({$saldo}).";
                    }
                }
            }

            if (! $c->motivo_codigo) {
                $errores['motivo_codigo'] = 'Indica el motivo de la nota.';
            }
            if (blank($c->motivo_descripcion)) {
                $errores['motivo_descripcion'] = 'Escribe el sustento de la nota.';
            }
        }

        // ── Total ──
        if (BigDecimal::of($c->total)->isLessThanOrEqualTo(0) && BigDecimal::of($c->op_gratuitas)->isZero()) {
            $errores['total'] = 'El total del comprobante debe ser mayor a cero.';
        }

        if ($errores) {
            throw ValidationException::withMessages($errores);
        }
    }

    /**
     * Cuánto se puede acreditar todavía sobre un comprobante:
     * su total menos las notas de crédito ya emitidas o en proceso (no rechazadas).
     */
    public function saldoAcreditable(Comprobante $referencia, ?Comprobante $excluir = null): BigDecimal
    {
        $acreditado = Comprobante::query()
            ->where('comprobante_referencia_id', $referencia->id)
            ->where('tipo_comprobante', TipoComprobante::NotaCredito)
            ->whereIn('estado', [EstadoComprobante::Pendiente, EstadoComprobante::Aceptado, EstadoComprobante::Observado, EstadoComprobante::Error])
            ->when($excluir?->id, fn ($q, $id) => $q->whereKeyNot($id))
            ->sum('total');

        return BigDecimal::of($referencia->total)->minus(BigDecimal::of((string) $acreditado))->toScale(2);
    }

    /** Crea un borrador nuevo copiando un comprobante (útil cuando SUNAT rechazó el original). */
    public function duplicar(Comprobante $original, ?int $userId = null): Comprobante
    {
        return DB::transaction(function () use ($original, $userId) {
            $copia = $original->replicate([
                'correlativo', 'estado', 'sunat_codigo', 'sunat_mensaje', 'hash',
                'enlace_pdf', 'enlace_xml', 'enlace_cdr', 'intentos_envio', 'enviado_at',
            ]);
            $copia->fill([
                'estado' => EstadoComprobante::Borrador,
                'fecha_emision' => today(),
                'intentos_envio' => 0,
                'user_id' => $userId,
            ]);
            $copia->save();

            foreach ($original->items as $item) {
                $copia->items()->create($item->replicate(['comprobante_id'])->toArray());
            }

            return $this->recalcular($copia);
        });
    }
}
