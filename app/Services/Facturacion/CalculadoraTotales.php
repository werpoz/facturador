<?php

namespace App\Services\Facturacion;

use App\Enums\TipoAfectacionIgv;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Cálculo de montos con precisión decimal exacta (nunca float).
 *
 * Criterio: el usuario ingresa el PRECIO CON IGV (lo que paga el cliente).
 * Del total de la línea se obtiene el valor de venta, y el IGV es la diferencia.
 * Así el total que ve el cliente nunca cambia por redondeos.
 */
class CalculadoraTotales
{
    private BigDecimal $tasaIgv;

    public function __construct(?string $tasaIgv = null)
    {
        $this->tasaIgv = BigDecimal::of($tasaIgv ?? config('facturacion.igv', '0.18'));
    }

    /**
     * @param  string|int|float  $cantidad
     * @param  string|int|float  $precioUnitario  precio con IGV (o valor referencial si es gratuito)
     * @return array{valor_unitario:string, valor_referencial:?string, precio_unitario:string, valor_venta:string, igv:string, total:string}
     */
    public function calcularItem($cantidad, $precioUnitario, TipoAfectacionIgv|string $afectacion): array
    {
        $afectacion = $afectacion instanceof TipoAfectacionIgv ? $afectacion : TipoAfectacionIgv::from($afectacion);

        $cantidad = BigDecimal::of($this->normalizar($cantidad));
        $precio = BigDecimal::of($this->normalizar($precioUnitario));
        $factor = BigDecimal::one()->plus($this->tasaIgv);

        $valorReferencial = null;

        if ($afectacion->esGratuito()) {
            // Transferencia gratuita: el cliente no paga nada (precio y total 0), pero se informa
            // cuánto vale lo regalado. El precio ingresado es el de mercado CON IGV; de ahí salen el valor
            // referencial unitario (sin IGV), la base (valor de venta) y el IGV, que se declaran aparte.
            $valorMercado = $cantidad->multipliedBy($precio)->toScale(2, RoundingMode::HalfUp);
            $valorVenta = $valorMercado->dividedBy($factor, 2, RoundingMode::HalfUp);
            $igv = $valorMercado->minus($valorVenta);
            $valorReferencial = $precio->dividedBy($factor, 10, RoundingMode::HalfUp);
            $valorUnitario = BigDecimal::zero()->toScale(10);
            $total = BigDecimal::zero()->toScale(2);
        } elseif ($afectacion->pagaIgv()) {
            $total = $cantidad->multipliedBy($precio)->toScale(2, RoundingMode::HalfUp);
            $valorVenta = $total->dividedBy($factor, 2, RoundingMode::HalfUp);
            $igv = $total->minus($valorVenta);
            $valorUnitario = $precio->dividedBy($factor, 10, RoundingMode::HalfUp);
        } else {
            $total = $cantidad->multipliedBy($precio)->toScale(2, RoundingMode::HalfUp);
            $valorVenta = $total;
            $igv = BigDecimal::zero()->toScale(2);
            $valorUnitario = $precio->toScale(10, RoundingMode::HalfUp);
        }

        return [
            'valor_unitario' => (string) $valorUnitario,
            'valor_referencial' => $valorReferencial ? (string) $valorReferencial : null,
            'precio_unitario' => (string) $precio->toScale(4, RoundingMode::HalfUp),
            'valor_venta' => (string) $valorVenta,
            'igv' => (string) $igv->toScale(2),
            'total' => (string) $total,
        ];
    }

    /**
     * @param  array<int, array{tipo_afectacion_igv: TipoAfectacionIgv|string, valor_venta: string, igv: string, total: string}>  $items
     * @return array{op_gravadas:string, op_exoneradas:string, op_inafectas:string, op_gratuitas:string, igv_gratuitas:string, igv:string, total:string}
     */
    public function calcularTotales(array $items): array
    {
        $suma = fn () => BigDecimal::zero()->toScale(2);
        $t = [
            'op_gravadas' => $suma(),
            'op_exoneradas' => $suma(),
            'op_inafectas' => $suma(),
            'op_gratuitas' => $suma(),
            'igv_gratuitas' => $suma(),
            'igv' => $suma(),
            'total' => $suma(),
        ];

        foreach ($items as $item) {
            $afectacion = $item['tipo_afectacion_igv'] instanceof TipoAfectacionIgv
                ? $item['tipo_afectacion_igv']
                : TipoAfectacionIgv::from($item['tipo_afectacion_igv']);

            $valorVenta = BigDecimal::of($item['valor_venta']);

            // Las operaciones gratuitas se informan, pero no se cobran ni suman al IGV a pagar
            if ($afectacion->esGratuito()) {
                $t['op_gratuitas'] = $t['op_gratuitas']->plus($valorVenta);
                $t['igv_gratuitas'] = $t['igv_gratuitas']->plus(BigDecimal::of($item['igv']));

                continue;
            }

            match ($afectacion) {
                TipoAfectacionIgv::Gravado => $t['op_gravadas'] = $t['op_gravadas']->plus($valorVenta),
                TipoAfectacionIgv::Exonerado => $t['op_exoneradas'] = $t['op_exoneradas']->plus($valorVenta),
                TipoAfectacionIgv::Inafecto => $t['op_inafectas'] = $t['op_inafectas']->plus($valorVenta),
            };

            $t['igv'] = $t['igv']->plus(BigDecimal::of($item['igv']));
            $t['total'] = $t['total']->plus(BigDecimal::of($item['total']));
        }

        return array_map(fn (BigDecimal $v) => (string) $v, $t);
    }

    private function normalizar(mixed $valor): string
    {
        if ($valor === null || $valor === '') {
            return '0';
        }

        // El último separador que aparece es el decimal; los anteriores son de miles:
        //   "1,234.50" → 1234.50    "1.234,50" → 1234.50    "1234,50" → 1234.50    "1234.5" → 1234.5
        $valor = str_replace(' ', '', trim((string) $valor));
        $coma = strrpos($valor, ',');
        $punto = strrpos($valor, '.');

        if ($coma !== false && ($punto === false || $coma > $punto)) {
            return str_replace(',', '.', str_replace('.', '', $valor));
        }

        return str_replace(',', '', $valor);
    }
}
