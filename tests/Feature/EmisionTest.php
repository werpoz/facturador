<?php

namespace Tests\Feature;

use App\Enums\EstadoComprobante;
use App\Enums\TipoComprobante;
use App\Enums\TipoDocumentoIdentidad;
use App\Models\Comprobante;
use App\Models\Empresa;
use App\Models\User;
use App\Services\Facturacion\EmisionService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EmisionTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->empresa = Empresa::first();
    }

    private function borrador(TipoComprobante $tipo, string $serie, TipoDocumentoIdentidad $doc, string $precio = '118'): Comprobante
    {
        $c = $this->empresa->comprobantes()->create([
            'cliente_id' => $this->empresa->clientes()->where('tipo_documento', $doc)->value('id'),
            'serie_id' => $this->empresa->series()->where('serie', $serie)->value('id'),
            'tipo_comprobante' => $tipo,
            'serie' => $serie,
            'fecha_emision' => today(),
            'estado' => EstadoComprobante::Borrador,
        ]);
        $c->items()->create([
            'descripcion' => 'Producto', 'cantidad' => 2, 'precio_unitario' => $precio,
            'valor_unitario' => 0, 'tipo_afectacion_igv' => '10', 'valor_venta' => 0, 'igv' => 0, 'total' => 0,
        ]);

        return $c;
    }

    public function test_emite_factura_asigna_correlativo_y_queda_aceptada(): void
    {
        $c = app(EmisionService::class)->emitir($this->borrador(TipoComprobante::Factura, 'F001', TipoDocumentoIdentidad::Ruc));
        $c->refresh();

        $this->assertSame(1, $c->correlativo);
        $this->assertSame('F001-00000001', $c->numero);
        $this->assertSame(EstadoComprobante::Aceptado, $c->estado); // la cola es "sync" en tests
        $this->assertSame('236.00', $c->total);
        $this->assertSame('36.00', $c->igv);
        $this->assertNotNull($c->hash);
        $this->assertSame(1, $c->logs()->count());

        $c2 = app(EmisionService::class)->emitir($this->borrador(TipoComprobante::Factura, 'F001', TipoDocumentoIdentidad::Ruc));
        $this->assertSame(2, $c2->refresh()->correlativo);
    }

    public function test_factura_a_cliente_con_dni_es_rechazada_antes_de_enviar(): void
    {
        $this->expectException(ValidationException::class);
        app(EmisionService::class)->emitir($this->borrador(TipoComprobante::Factura, 'F001', TipoDocumentoIdentidad::Dni));
    }

    public function test_boleta_mayor_a_700_sin_documento_es_rechazada(): void
    {
        $c = $this->borrador(TipoComprobante::Boleta, 'B001', TipoDocumentoIdentidad::SinDocumento, '400');

        try {
            app(EmisionService::class)->emitir($c);
            $this->fail('Debió lanzar ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('cliente_id', $e->errors());
            $this->assertNull($c->refresh()->correlativo, 'No debe consumir correlativo si falla la validación');
        }
    }

    public function test_no_se_puede_emitir_dos_veces(): void
    {
        $c = app(EmisionService::class)->emitir($this->borrador(TipoComprobante::Boleta, 'B001', TipoDocumentoIdentidad::Dni));

        $this->expectException(ValidationException::class);
        app(EmisionService::class)->emitir($c->refresh());
    }

    public function test_imprimir_requiere_acceso_a_la_empresa(): void
    {
        $c = app(EmisionService::class)->emitir($this->borrador(TipoComprobante::Boleta, 'B001', TipoDocumentoIdentidad::Dni));

        $this->actingAs(User::first())
            ->get(route('comprobantes.imprimir', $c))
            ->assertOk()
            ->assertSee('B001-00000001')
            ->assertSee('SON: DOSCIENTOS TREINTA Y SEIS CON 00/100 SOLES');

        $this->actingAs(User::factory()->create())
            ->get(route('comprobantes.imprimir', $c))
            ->assertForbidden();
    }
}
