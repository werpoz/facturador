<?php

namespace Tests\Unit;

use App\Enums\TipoAfectacionIgv;
use App\Services\Facturacion\CalculadoraTotales;
use PHPUnit\Framework\TestCase;

class CalculadoraTotalesTest extends TestCase
{
    private CalculadoraTotales $calc;

    protected function setUp(): void
    {
        $this->calc = new CalculadoraTotales('0.18');
    }

    public function test_item_gravado_separa_igv_del_precio(): void
    {
        $r = $this->calc->calcularItem(1, '118.00', TipoAfectacionIgv::Gravado);

        $this->assertSame('100.00', $r['valor_venta']);
        $this->assertSame('18.00', $r['igv']);
        $this->assertSame('118.00', $r['total']);
        $this->assertSame('100.0000', $r['valor_unitario']);
    }

    public function test_total_respeta_precio_al_publico_aunque_haya_redondeo(): void
    {
        // 3 x 59.90 = 179.70 → el cliente paga exactamente eso
        $r = $this->calc->calcularItem(3, '59.90', TipoAfectacionIgv::Gravado);

        $this->assertSame('179.70', $r['total']);
        $this->assertSame('152.29', $r['valor_venta']);
        $this->assertSame('27.41', $r['igv']);
    }

    public function test_item_exonerado_no_tiene_igv(): void
    {
        $r = $this->calc->calcularItem(2, '45', TipoAfectacionIgv::Exonerado);

        $this->assertSame('90.00', $r['valor_venta']);
        $this->assertSame('0.00', $r['igv']);
        $this->assertSame('90.00', $r['total']);
    }

    public function test_acepta_coma_decimal(): void
    {
        $r = $this->calc->calcularItem('1', '10,50', TipoAfectacionIgv::Inafecto);

        $this->assertSame('10.50', $r['total']);
    }

    public function test_totales_agrupan_por_tipo_y_excluyen_gratuitos(): void
    {
        $items = [];
        foreach ([
            [1, '118', TipoAfectacionIgv::Gravado],
            [1, '50', TipoAfectacionIgv::Exonerado],
            [1, '20', TipoAfectacionIgv::Inafecto],
            [1, '11.80', TipoAfectacionIgv::GravadoGratuito],
        ] as [$cant, $precio, $tipo]) {
            $items[] = ['tipo_afectacion_igv' => $tipo] + $this->calc->calcularItem($cant, $precio, $tipo);
        }

        $t = $this->calc->calcularTotales($items);

        $this->assertSame('100.00', $t['op_gravadas']);
        $this->assertSame('50.00', $t['op_exoneradas']);
        $this->assertSame('20.00', $t['op_inafectas']);
        $this->assertSame('10.00', $t['op_gratuitas']);
        $this->assertSame('18.00', $t['igv']);
        $this->assertSame('188.00', $t['total']);
    }
}
