<?php

namespace App\Services\Ventas;

use App\Enums\EstadoComprobante;
use App\Enums\EstadoVenta;
use App\Enums\TipoComprobante;
use App\Models\Comprobante;
use App\Models\Venta;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Mantiene la VENTA (hecho comercial) sincronizada con sus COMPROBANTES (documentos SUNAT).
 *
 *  - Una factura o boleta crea su venta.
 *  - Una nota de crédito/débito pertenece a la venta del comprobante que modifica.
 *  - El estado y los montos de la venta se calculan siempre a partir de sus comprobantes:
 *    nunca se editan a mano, así no pueden quedar desfasados.
 */
class VentaService
{
    private const ACEPTADOS = [EstadoComprobante::Aceptado, EstadoComprobante::Observado];

    /** Se llama cada vez que un comprobante se guarda. */
    public function sincronizar(Comprobante $c): void
    {
        $anterior = $c->venta_id;

        $ventaId = $c->tipo_comprobante->esNota()
            ? $c->referencia()->value('venta_id')              // la nota va a la venta de su referencia
            : ($this->ventaPropia($c) ?? $this->crear($c)->id); // factura/boleta: su propia venta

        if ($ventaId !== $anterior) {
            $c->venta_id = $ventaId;
            $c->saveQuietly();
        }

        if ($ventaId) {
            $this->recalcular(Venta::find($ventaId));
        }

        // Si el comprobante cambió de venta (p. ej. un borrador que pasó de boleta a nota), se ordena la anterior
        if ($anterior && $anterior !== $ventaId && ($vieja = Venta::find($anterior))) {
            $this->recalcular($vieja);
        }
    }

    /** Se llama cuando se elimina un comprobante (solo los borradores se pueden eliminar). */
    public function alEliminar(Comprobante $c): void
    {
        if ($c->venta_id && ($venta = Venta::find($c->venta_id))) {
            $this->recalcular($venta);
        }
    }

    /** Recalcula montos y estado de la venta. Si se quedó sin comprobantes, se elimina. */
    public function recalcular(?Venta $venta): ?Venta
    {
        if (! $venta) {
            return null;
        }

        $comprobantes = Comprobante::query()->where('venta_id', $venta->id)->orderBy('id')->get();

        if ($comprobantes->isEmpty()) {
            $venta->delete();

            return null;
        }

        // Comprobante principal: la última factura/boleta que no esté rechazada
        // (si SUNAT rechazó la primera y se emitió una corregida, manda la corregida)
        $originales = $comprobantes->filter(fn (Comprobante $c) => ! $c->tipo_comprobante->esNota());
        $principal = $originales->where('estado', '!==', EstadoComprobante::Rechazado)->last() ?? $originales->last();

        if (! $principal) {
            return $venta;
        }

        $sumar = fn (TipoComprobante $tipo) => $comprobantes
            ->filter(fn (Comprobante $c) => $c->tipo_comprobante === $tipo
                && $c->comprobante_referencia_id === $principal->id
                && in_array($c->estado, self::ACEPTADOS, true))
            ->reduce(fn (BigDecimal $suma, Comprobante $c) => $suma->plus($c->total), BigDecimal::zero());

        $total = BigDecimal::of($principal->total);
        $acreditado = $sumar(TipoComprobante::NotaCredito);
        $debitado = $sumar(TipoComprobante::NotaDebito);

        $venta->forceFill([
            'comprobante_principal_id' => $principal->id,
            'cliente_id' => $principal->cliente_id,
            'fecha' => $principal->fecha_emision,
            'moneda' => $principal->moneda,
            'total' => (string) $total->toScale(2),
            'acreditado' => (string) $acreditado->toScale(2),
            'debitado' => (string) $debitado->toScale(2),
            'neto' => (string) $total->minus($acreditado)->plus($debitado)->toScale(2),
            'estado' => $this->estado($principal, $total, $acreditado, $total->minus($acreditado)->plus($debitado)),
        ])->save();

        return $venta;
    }

    private function estado(Comprobante $principal, BigDecimal $total, BigDecimal $acreditado, BigDecimal $neto): EstadoVenta
    {
        return match ($principal->estado) {
            EstadoComprobante::Borrador => EstadoVenta::Borrador,
            EstadoComprobante::Pendiente, EstadoComprobante::Error => EstadoVenta::EnProceso,
            EstadoComprobante::Rechazado => EstadoVenta::Rechazada,
            EstadoComprobante::Anulado => EstadoVenta::Anulada,
            EstadoComprobante::Aceptado, EstadoComprobante::Observado => match (true) {
                // Con una nota de débito de por medio puede quedar saldo aunque se acredite el total original
                $total->isPositive() && $neto->isLessThanOrEqualTo(0) => EstadoVenta::Anulada,
                $acreditado->isPositive() => EstadoVenta::ConDevolucion,
                default => EstadoVenta::Confirmada,
            },
        };
    }

    private function ventaPropia(Comprobante $c): ?int
    {
        // Un borrador que antes era nota conserva el venta_id de otra venta: en ese caso necesita la suya
        if (! $c->venta_id) {
            return null;
        }

        $esDeOtro = Comprobante::query()
            ->where('venta_id', $c->venta_id)
            ->whereKeyNot($c->id)
            ->whereIn('tipo_comprobante', [TipoComprobante::Factura, TipoComprobante::Boleta])
            ->where('estado', '!=', EstadoComprobante::Rechazado)
            ->exists();

        return $esDeOtro ? null : $c->venta_id;
    }

    private function crear(Comprobante $c): Venta
    {
        return DB::transaction(function () use ($c) {
            $numero = $this->siguienteNumero($c->empresa_id);

            $venta = new Venta;
            $venta->forceFill([
                'empresa_id' => $c->empresa_id,
                'cliente_id' => $c->cliente_id,
                'numero' => $numero,
                'fecha' => $c->fecha_emision,
                'moneda' => $c->moneda ?? 'PEN',
                'estado' => EstadoVenta::Borrador,
                'user_id' => $c->user_id,
            ])->save();

            return $venta;
        }, attempts: 3);
    }

    /**
     * Siguiente número interno de venta de la empresa. Debe llamarse dentro de una transacción.
     * El UPDATE bloquea solo la fila del contador: dos ventas simultáneas no toman el mismo número
     * y, como el contador nunca retrocede, un número no se reutiliza aunque se elimine su borrador.
     */
    private function siguienteNumero(int $empresaId): int
    {
        $contador = DB::table('secuencias')->where('empresa_id', $empresaId)->where('nombre', 'venta');

        if ($contador->increment('valor') === 0) {
            // Empresa sin contador todavía: se crea (si otro proceso lo creó a la vez, se ignora) y se incrementa
            DB::table('secuencias')->insertOrIgnore(['empresa_id' => $empresaId, 'nombre' => 'venta', 'valor' => 0]);
            $contador->increment('valor');
        }

        return (int) $contador->value('valor');
    }
}
