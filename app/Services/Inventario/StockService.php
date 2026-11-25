<?php

namespace App\Services\Inventario;

use App\Enums\TipoComprobante;
use App\Enums\TipoMovimientoStock;
use App\Models\Comprobante;
use App\Models\MovimientoStock;
use App\Models\Producto;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Inventario. Regla de oro: el stock de un producto es la suma de sus movimientos (kardex).
 * Nada cambia el stock sin dejar un movimiento, y un movimiento nunca se edita ni se borra.
 *
 * ¿Cuándo se mueve?
 *  - Al EMITIR una factura o boleta: sale (el cliente ya se llevó el producto).
 *  - Al EMITIR una nota de crédito por devolución o anulación: vuelve a entrar.
 *  - Si SUNAT RECHAZA el comprobante: su movimiento se revierte solo.
 */
class StockService
{
    /** Motivos de nota de crédito (catálogo 09) en los que la mercadería regresa al almacén */
    public const MOTIVOS_QUE_DEVUELVEN = ['01', '02', '06', '07'];

    /** Aplica al inventario el efecto de un comprobante recién emitido. Idempotente. */
    public function aplicarComprobante(Comprobante $c): void
    {
        $tipo = $this->tipoDeMovimiento($c);

        if (! $tipo || MovimientoStock::query()->where('comprobante_id', $c->id)->exists()) {
            return;
        }

        if ($tipo === TipoMovimientoStock::Devolucion) {
            $this->aplicarDevolucion($c);

            return;
        }

        $exigir = (bool) $c->empresa->bloquear_venta_sin_stock;

        // Siempre en orden de producto: dos ventas simultáneas bloquean las filas en el mismo orden (sin deadlocks)
        foreach ($this->cantidadesPorProducto($c) as $productoId => $cantidad) {
            $this->mover($productoId, $cantidad->negated(), $tipo, $c, exigirDisponible: $exigir);
        }
    }

    /**
     * Devolución: regresa al almacén, como máximo, lo que el comprobante original REALMENTE sacó
     * y que todavía no fue devuelto por otra nota. Así una nota no puede "inventar" stock:
     * ni con cantidades mayores a las vendidas, ni con productos que no estaban en la venta,
     * ni cuando el producto no llevaba control de stock al venderse.
     */
    private function aplicarDevolucion(Comprobante $nota): void
    {
        if (! $nota->comprobante_referencia_id) {
            return;
        }

        $pendiente = $this->pendientePorDevolver($nota->comprobante_referencia_id, $nota->id);

        foreach ($this->cantidadesPorProducto($nota, soloConStock: false) as $productoId => $cantidad) {
            $devolver = BigDecimal::min($cantidad, $pendiente[$productoId] ?? BigDecimal::zero());

            if ($devolver->isPositive()) {
                $this->mover($productoId, $devolver, TipoMovimientoStock::Devolucion, $nota);
            }
        }
    }

    /**
     * Por producto: lo que sacó el comprobante original menos lo ya regresado por sus notas.
     *
     * @return array<int, BigDecimal>
     */
    private function pendientePorDevolver(int $referenciaId, int $excluirNotaId): array
    {
        $netoPorProducto = fn ($query) => $query
            ->selectRaw('producto_id, sum(cantidad) as neto')->groupBy('producto_id')->pluck('neto', 'producto_id');

        // Negativo = lo que salió con la venta (ya descontadas sus reversiones, si la rechazaron)
        $sacado = $netoPorProducto(MovimientoStock::query()->where('comprobante_id', $referenciaId));

        // Positivo = lo que ya regresaron otras notas de ese mismo comprobante
        $regresado = $netoPorProducto(MovimientoStock::query()->whereIn('comprobante_id', Comprobante::query()
            ->where('comprobante_referencia_id', $referenciaId)->whereKeyNot($excluirNotaId)->select('id')));

        $pendiente = [];
        foreach ($sacado as $productoId => $neto) {
            $resto = BigDecimal::of((string) $neto)->negated()->minus((string) ($regresado[$productoId] ?? 0));
            if ($resto->isPositive()) {
                $pendiente[(int) $productoId] = $resto;
            }
        }

        return $pendiente;
    }

    /** Deshace lo que un comprobante movió (rechazo de SUNAT o anulación). Idempotente. */
    public function revertirComprobante(Comprobante $c): void
    {
        $netos = MovimientoStock::query()
            ->where('comprobante_id', $c->id)
            ->selectRaw('producto_id, sum(cantidad) as neto')
            ->groupBy('producto_id')
            ->orderBy('producto_id')
            ->pluck('neto', 'producto_id');

        foreach ($netos as $productoId => $neto) {
            $neto = BigDecimal::of((string) $neto);
            if (! $neto->isZero()) {
                $this->mover((int) $productoId, $neto->negated(), TipoMovimientoStock::Reversion, $c, "Se revirtió {$c->numero}");
            }
        }
    }

    /**
     * Productos del comprobante que no alcanzan en el almacén.
     *
     * @return array<int, string> mensajes listos para mostrar
     */
    public function faltantes(Comprobante $c): array
    {
        if ($this->tipoDeMovimiento($c) !== TipoMovimientoStock::Venta) {
            return [];
        }

        $pedido = $this->cantidadesPorProducto($c);
        $faltan = [];

        foreach (Producto::query()->whereIn('id', array_keys($pedido))->get() as $p) {
            if ($pedido[$p->id]->isGreaterThan($p->stock)) {
                $faltan[] = "No hay stock suficiente de \"{$p->descripcion}\": pides ".self::fmt($pedido[$p->id]).' y quedan '.self::fmt($p->stock).'.';
            }
        }

        return $faltan;
    }

    /** Movimiento manual: ingreso de mercadería, merma, o ajuste al conteo físico. */
    public function registrarManual(Producto $producto, TipoMovimientoStock $tipo, string|int|float $cantidad, ?string $motivo = null): MovimientoStock
    {
        if (! in_array($tipo, TipoMovimientoStock::manuales(), true)) {
            throw ValidationException::withMessages(['tipo' => 'Ese tipo de movimiento no se registra a mano.']);
        }
        if (! $producto->controla_stock) {
            throw ValidationException::withMessages(['producto' => 'Este producto no lleva control de stock.']);
        }

        $cantidad = BigDecimal::of((string) $cantidad);

        if ($tipo === TipoMovimientoStock::Ajuste) {
            // En un ajuste se escribe cuánto hay DE VERDAD; el movimiento es la diferencia
            if ($cantidad->isNegative()) {
                throw ValidationException::withMessages(['cantidad' => 'El stock contado no puede ser negativo.']);
            }
            $diferencia = $cantidad->minus($producto->fresh()->stock);
            if ($diferencia->isZero()) {
                throw ValidationException::withMessages(['cantidad' => 'El stock ya es ese: no hay nada que ajustar.']);
            }

            return $this->mover($producto->id, $diferencia, $tipo, motivo: $motivo);
        }

        if (! $cantidad->isPositive()) {
            throw ValidationException::withMessages(['cantidad' => 'La cantidad debe ser mayor a cero.']);
        }

        return $this->mover($producto->id, $tipo === TipoMovimientoStock::Merma ? $cantidad->negated() : $cantidad, $tipo, motivo: $motivo);
    }

    /** Corrige el stock de un producto para que vuelva a ser la suma de su kardex. */
    public function recalcular(int $productoId): void
    {
        $suma = MovimientoStock::query()->where('producto_id', $productoId)->sum('cantidad');
        DB::table('productos')->where('id', $productoId)->update(['stock' => $suma]);
    }

    /** Elimina del kardex lo que movieron ciertos comprobantes (solo al borrar datos de prueba). */
    public function olvidarComprobantes(iterable $comprobanteIds): void
    {
        $ids = collect($comprobanteIds)->all();
        $productos = DB::table('movimientos_stock')->whereIn('comprobante_id', $ids)->distinct()->pluck('producto_id');

        DB::table('movimientos_stock')->whereIn('comprobante_id', $ids)->delete();
        $productos->each(fn ($id) => $this->recalcular((int) $id));
    }

    /**
     * El único lugar donde cambia el stock. Actualiza el producto y escribe el kardex en la misma
     * transacción; el UPDATE bloquea la fila, así dos ventas a la vez no se pisan.
     */
    public function mover(
        int $productoId,
        BigDecimal $cantidad,
        TipoMovimientoStock $tipo,
        ?Comprobante $comprobante = null,
        ?string $motivo = null,
        bool $exigirDisponible = false,
    ): MovimientoStock {
        return DB::transaction(function () use ($productoId, $cantidad, $tipo, $comprobante, $motivo, $exigirDisponible) {
            $delta = (string) $cantidad->toScale(4, RoundingMode::HalfUp);

            $sql = 'update productos set stock = stock + ? where id = ?';
            $parametros = [$delta, $productoId];
            if ($exigirDisponible) {
                $sql .= ' and stock + ? >= 0'; // condición atómica: imposible vender lo que otro ya se llevó
                $parametros[] = $delta;
            }

            if (DB::update($sql, $parametros) === 0) {
                $p = Producto::query()->find($productoId);
                throw ValidationException::withMessages([
                    'items' => $p
                        ? "No hay stock suficiente de \"{$p->descripcion}\" (quedan ".self::fmt($p->stock).').'
                        : 'Uno de los productos ya no existe.',
                ]);
            }

            $producto = DB::table('productos')->where('id', $productoId)->first(['empresa_id', 'stock']);

            return MovimientoStock::create([
                'empresa_id' => $producto->empresa_id,
                'producto_id' => $productoId,
                'tipo' => $tipo,
                'cantidad' => $delta,
                'stock_resultante' => $producto->stock,
                'comprobante_id' => $comprobante?->id,
                'motivo' => $motivo,
                'user_id' => auth()->id(),
            ]);
        });
    }

    private function tipoDeMovimiento(Comprobante $c): ?TipoMovimientoStock
    {
        return match (true) {
            in_array($c->tipo_comprobante, [TipoComprobante::Factura, TipoComprobante::Boleta], true) => TipoMovimientoStock::Venta,
            $c->tipo_comprobante === TipoComprobante::NotaCredito
                && in_array($c->motivo_codigo, self::MOTIVOS_QUE_DEVUELVEN, true) => TipoMovimientoStock::Devolucion,
            default => null, // descuentos, correcciones de texto, notas de débito: no mueven mercadería
        };
    }

    /** @return array<int, BigDecimal> cantidad total por producto (solo los que llevan stock), ordenado por id */
    private function cantidadesPorProducto(Comprobante $c, bool $soloConStock = true): array
    {
        $items = $c->items()->whereNotNull('producto_id')->get(['producto_id', 'cantidad']);
        $conStock = Producto::query()->whereIn('id', $items->pluck('producto_id'))
            ->when($soloConStock, fn ($q) => $q->where('controla_stock', true))->pluck('id')->flip();

        $totales = [];
        foreach ($items as $item) {
            if ($conStock->has($item->producto_id)) {
                $totales[$item->producto_id] = ($totales[$item->producto_id] ?? BigDecimal::zero())->plus($item->cantidad);
            }
        }
        ksort($totales);

        return $totales;
    }

    public static function fmt(mixed $n): string
    {
        return rtrim(rtrim(number_format((float) (string) $n, 4, '.', ''), '0'), '.') ?: '0';
    }
}
