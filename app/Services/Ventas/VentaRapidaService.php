<?php

namespace App\Services\Ventas;

use App\Enums\EstadoComprobante;
use App\Enums\MedioPago;
use App\Enums\Moneda;
use App\Enums\TipoComprobante;
use App\Models\Comprobante;
use App\Models\Empresa;
use App\Models\User;
use App\Services\Facturacion\CalculadoraTotales;
use App\Services\Facturacion\EmisionService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Venta del POS en un solo paso: crea el comprobante, lo emite y registra el pago,
 * todo dentro de una transacción. Si algo falla (regla SUNAT, permiso, monto insuficiente)
 * no queda nada a medias: ni borradores sueltos, ni números consumidos, ni pagos huérfanos.
 */
class VentaRapidaService
{
    public function __construct(
        private CalculadoraTotales $calculadora,
        private EmisionService $emision,
    ) {}

    /**
     * @param  array<int, array{producto_id:int, cantidad:string|int|float}>  $carrito
     */
    public function registrar(
        Empresa $empresa,
        TipoComprobante $tipo,
        ?int $clienteId,
        array $carrito,
        MedioPago $medio,
        string|int|float|null $recibido = null,
        ?User $usuario = null,
    ): Comprobante {
        if ($carrito === []) {
            throw ValidationException::withMessages(['carrito' => 'Agrega al menos un producto.']);
        }
        if ($tipo->esNota()) {
            throw ValidationException::withMessages(['tipo' => 'En el punto de venta solo se emiten boletas y facturas.']);
        }

        $cliente = $clienteId ? $empresa->clientes()->find($clienteId) : null;
        if (! $cliente) {
            throw ValidationException::withMessages(['cliente' => 'Selecciona un cliente.']);
        }

        $serie = $empresa->series()->where('tipo_comprobante', $tipo)->where('activo', true)->orderBy('serie')->first();
        if (! $serie) {
            throw ValidationException::withMessages(['serie' => "No hay una serie activa para {$tipo->getLabel()}."]);
        }

        // Descripción y PRECIO se toman siempre de la base de datos, nunca del navegador:
        // el vendedor no puede alterar precios desde el punto de venta.
        $productos = $empresa->productos()->where('activo', true)
            ->whereIn('id', array_column($carrito, 'producto_id'))->get()->keyBy('id');

        $items = [];
        foreach (array_values($carrito) as $orden => $linea) {
            $producto = $productos->get($linea['producto_id']);
            if (! $producto) {
                throw ValidationException::withMessages(['carrito' => 'Uno de los productos ya no está disponible. Quítalo y vuelve a intentar.']);
            }

            $cantidad = $this->numero($linea['cantidad']);
            if (BigDecimal::of($cantidad)->isLessThanOrEqualTo(0)) {
                throw ValidationException::withMessages(['carrito' => "La cantidad de \"{$producto->descripcion}\" debe ser mayor a cero."]);
            }

            $items[] = [
                'producto_id' => $producto->id,
                'codigo' => $producto->codigo,
                'descripcion' => $producto->descripcion,
                'unidad_medida' => $producto->unidad_medida,
                'tipo_afectacion_igv' => $producto->tipo_afectacion_igv,
                'cantidad' => $cantidad,
                'orden' => $orden,
            ] + $this->calculadora->calcularItem($cantidad, $producto->precio_unitario, $producto->tipo_afectacion_igv);
        }

        $total = BigDecimal::of($this->calculadora->calcularTotales($items)['total']);

        $montoRecibido = null;
        $vuelto = null;
        if ($medio->calculaVuelto()) {
            $montoRecibido = filled($recibido) ? BigDecimal::of($this->numero($recibido)) : $total;
            if ($montoRecibido->isLessThan($total)) {
                throw ValidationException::withMessages(['recibido' => 'El monto recibido es menor al total de la venta.']);
            }
            $vuelto = $montoRecibido->minus($total);
        }

        return DB::transaction(function () use ($empresa, $tipo, $cliente, $serie, $items, $total, $medio, $montoRecibido, $vuelto, $usuario) {
            $comprobante = $empresa->comprobantes()->create([
                'cliente_id' => $cliente->id,
                'serie_id' => $serie->id,
                'tipo_comprobante' => $tipo,
                'serie' => $serie->serie,
                'fecha_emision' => today(),
                'moneda' => Moneda::Soles,
                'forma_pago' => 'Contado',
                'estado' => EstadoComprobante::Borrador,
                'user_id' => $usuario?->id,
            ]);
            $comprobante->items()->createMany($items);

            // Aquí se aplican permisos y reglas SUNAT; si fallan, la excepción revierte todo
            $comprobante = $this->emision->emitir($comprobante);

            $comprobante->venta()->firstOrFail()->pagos()->create([
                'empresa_id' => $empresa->id,
                'medio' => $medio,
                'monto' => (string) $total->toScale(2),
                'recibido' => $montoRecibido ? (string) $montoRecibido->toScale(2) : null,
                'vuelto' => $vuelto ? (string) $vuelto->toScale(2) : null,
                'user_id' => $usuario?->id,
            ]);

            return $comprobante;
        });
    }

    private function numero(mixed $valor): string
    {
        $v = str_replace(',', '.', trim((string) $valor));

        return is_numeric($v) ? $v : '0';
    }
}
