<?php

namespace Tests\Feature;

use App\Enums\EstadoComprobante;
use App\Enums\EstadoVenta;
use App\Enums\RegimenTributario;
use App\Enums\TipoComprobante;
use App\Enums\TipoDocumentoIdentidad;
use App\Events\ComprobanteAceptado;
use App\Filament\Pages\Tenancy\RegistrarEmpresa;
use App\Filament\Resources\Comprobantes\Pages\CreateComprobante;
use App\Filament\Resources\Ventas\Pages\ListVentas;
use App\Filament\Resources\Ventas\Pages\ViewVenta;
use App\Filament\Resources\Ventas\VentaResource;
use App\Jobs\EnviarComprobanteJob;
use App\Models\Cliente;
use App\Models\Comprobante;
use App\Models\Empresa;
use App\Models\User;
use App\Models\Venta;
use App\Services\Facturacion\EmisionService;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** La venta (hecho comercial) y su relación con los comprobantes (documentos SUNAT). */
class VentasTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    private EmisionService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->empresa = Empresa::first();
        $this->svc = app(EmisionService::class);
    }

    private function cliente(TipoDocumentoIdentidad $doc): Cliente
    {
        return $this->empresa->clientes()->where('tipo_documento', $doc)->firstOrFail();
    }

    private function borrador(TipoComprobante $tipo, string $serie, string $precio = '100', array $extra = []): Comprobante
    {
        $cliente = $this->cliente(str_starts_with($serie, 'F') ? TipoDocumentoIdentidad::Ruc : TipoDocumentoIdentidad::Dni);

        $c = $this->empresa->comprobantes()->create($extra + [
            'cliente_id' => $cliente->id,
            'serie_id' => $this->empresa->series()->where('serie', $serie)->value('id'),
            'tipo_comprobante' => $tipo,
            'serie' => $serie,
            'fecha_emision' => today(),
            'estado' => EstadoComprobante::Borrador,
        ]);
        $c->items()->create([
            'descripcion' => 'Producto', 'cantidad' => 1, 'precio_unitario' => $precio, 'tipo_afectacion_igv' => '10',
            'valor_unitario' => 0, 'valor_venta' => 0, 'igv' => 0, 'total' => 0,
        ]);

        return $c;
    }

    private function nota(Comprobante $ref, TipoComprobante $tipo, string $precio): Comprobante
    {
        $serie = $ref->serie[0].($tipo === TipoComprobante::NotaCredito ? 'C01' : 'D01');

        return $this->svc->emitir($this->borrador($tipo, $serie, $precio, [
            'cliente_id' => $ref->cliente_id,
            'comprobante_referencia_id' => $ref->id,
            'motivo_codigo' => '07',
            'motivo_descripcion' => 'Ajuste',
        ]))->refresh();
    }

    // ───────────────────────── Ciclo de vida ─────────────────────────

    public function test_una_boleta_crea_su_venta_y_al_emitirse_queda_confirmada(): void
    {
        $boleta = $this->borrador(TipoComprobante::Boleta, 'B001');
        $venta = $boleta->refresh()->venta;

        $this->assertNotNull($venta);
        $this->assertSame('V-000001', $venta->codigo);
        $this->assertSame(EstadoVenta::Borrador, $venta->estado);

        $this->svc->emitir($boleta);
        $venta->refresh();

        $this->assertSame(EstadoVenta::Confirmada, $venta->estado);
        $this->assertSame('100.00', $venta->total);
        $this->assertSame('100.00', $venta->neto);
        $this->assertSame($boleta->id, $venta->comprobante_principal_id);
        $this->assertSame($boleta->cliente_id, $venta->cliente_id);
    }

    public function test_cada_venta_tiene_su_numero_interno_consecutivo(): void
    {
        $a = $this->borrador(TipoComprobante::Boleta, 'B001')->refresh()->venta;
        $b = $this->borrador(TipoComprobante::Factura, 'F001')->refresh()->venta;

        $this->assertSame([1, 2], [$a->numero, $b->numero]);
    }

    public function test_devolucion_parcial_y_luego_total(): void
    {
        $factura = $this->svc->emitir($this->borrador(TipoComprobante::Factura, 'F001', '100'))->refresh();
        $venta = $factura->venta;

        // Devolución parcial: la nota pertenece a la MISMA venta
        $nc1 = $this->nota($factura, TipoComprobante::NotaCredito, '30');
        $venta->refresh();
        $this->assertSame($venta->id, $nc1->venta_id);
        $this->assertSame(EstadoVenta::ConDevolucion, $venta->estado);
        $this->assertSame(['100.00', '30.00', '70.00'], [$venta->total, $venta->acreditado, $venta->neto]);

        // Se devuelve el resto: la venta queda anulada
        $this->nota($factura, TipoComprobante::NotaCredito, '70');
        $venta->refresh();
        $this->assertSame(EstadoVenta::Anulada, $venta->estado);
        $this->assertSame('0.00', $venta->neto);

        $this->assertSame(1, Venta::count(), 'Las notas no deben crear ventas nuevas');
        $this->assertCount(3, $venta->comprobantes);
    }

    public function test_nota_de_debito_aumenta_el_neto(): void
    {
        $factura = $this->svc->emitir($this->borrador(TipoComprobante::Factura, 'F001', '100'))->refresh();
        $this->nota($factura, TipoComprobante::NotaDebito, '15');

        $venta = $factura->venta->refresh();
        $this->assertSame(['15.00', '115.00'], [$venta->debitado, $venta->neto]);
        $this->assertSame(EstadoVenta::Confirmada, $venta->estado);
    }

    public function test_una_nota_en_borrador_o_rechazada_no_afecta_la_venta(): void
    {
        $factura = $this->svc->emitir($this->borrador(TipoComprobante::Factura, 'F001', '100'))->refresh();

        $nc = $this->borrador(TipoComprobante::NotaCredito, 'FC01', '40', [
            'comprobante_referencia_id' => $factura->id, 'motivo_codigo' => '07', 'motivo_descripcion' => 'x',
        ]);
        $this->assertSame('100.00', $factura->venta->refresh()->neto, 'Borrador: aún no cuenta');

        $nc->update(['estado' => EstadoComprobante::Rechazado]);
        $this->assertSame(EstadoVenta::Confirmada, $factura->venta->refresh()->estado, 'Rechazada: no cuenta');

        $nc->update(['estado' => EstadoComprobante::Aceptado, 'total' => '40.00']);
        $this->assertSame('60.00', $factura->venta->refresh()->neto);
    }

    public function test_pse_caido_deja_la_venta_en_proceso(): void
    {
        Queue::fake();
        $boleta = $this->svc->emitir($this->borrador(TipoComprobante::Boleta, 'B001'));
        $this->assertSame(EstadoVenta::EnProceso, $boleta->venta->refresh()->estado);

        (new EnviarComprobanteJob($boleta->id))->handle($this->svc);
        $this->assertSame(EstadoVenta::Confirmada, $boleta->venta->refresh()->estado);
    }

    public function test_rechazo_de_sunat_y_correccion_se_mantienen_en_la_misma_venta(): void
    {
        $original = $this->svc->emitir($this->borrador(TipoComprobante::Factura, 'F001', '100'))->refresh();
        $original->update(['estado' => EstadoComprobante::Rechazado]);
        $venta = $original->venta->refresh();
        $this->assertSame(EstadoVenta::Rechazada, $venta->estado);

        // "Corregir y volver a emitir": el comprobante nuevo sigue siendo de la misma venta
        $corregido = $this->svc->duplicar($original);
        $this->assertSame($venta->id, $corregido->venta_id);
        $this->assertSame(EstadoVenta::Borrador, $venta->refresh()->estado);

        $this->svc->emitir($corregido);
        $venta->refresh();
        $this->assertSame(EstadoVenta::Confirmada, $venta->estado);
        $this->assertSame($corregido->id, $venta->comprobante_principal_id);
        $this->assertSame(1, Venta::count());
    }

    public function test_eliminar_el_borrador_elimina_la_venta(): void
    {
        $boleta = $this->borrador(TipoComprobante::Boleta, 'B001');
        $this->assertSame(1, Venta::count());

        $boleta->delete();
        $this->assertSame(0, Venta::count());
    }

    public function test_el_evento_de_aceptacion_se_dispara_una_sola_vez_y_solo_si_sunat_acepta(): void
    {
        Event::fake([ComprobanteAceptado::class]);

        $boleta = $this->svc->emitir($this->borrador(TipoComprobante::Boleta, 'B001'));
        Event::assertDispatchedTimes(ComprobanteAceptado::class, 1);

        // Reprocesar el job no vuelve a disparar efectos (no se movería el stock dos veces)
        (new EnviarComprobanteJob($boleta->id))->handle($this->svc);
        Event::assertDispatchedTimes(ComprobanteAceptado::class, 1);
    }

    // ───────────────────────── Régimen tributario ─────────────────────────

    public function test_nuevo_rus_no_puede_emitir_facturas_pero_si_boletas(): void
    {
        $this->empresa->update(['regimen_tributario' => RegimenTributario::NuevoRus]);

        try {
            $this->svc->emitir($this->borrador(TipoComprobante::Factura, 'F001'));
            $this->fail('El Nuevo RUS no debe poder emitir facturas');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('tipo_comprobante', $e->errors());
        }

        $boleta = $this->svc->emitir($this->borrador(TipoComprobante::Boleta, 'B001'))->refresh();
        $this->assertSame(EstadoComprobante::Aceptado, $boleta->estado);
    }

    public function test_nuevo_rus_en_la_interfaz_no_ofrece_facturas(): void
    {
        $this->actingAs(User::first());
        Filament::setCurrentPanel('admin');
        Filament::setTenant(null);
        Filament::bootCurrentPanel();

        // Al registrarse solo recibe series de boleta
        Livewire::test(RegistrarEmpresa::class)
            ->fillForm(['ruc' => '10456789123', 'razon_social' => 'PUESTO DE ROPA', 'regimen_tributario' => 'nrus'])
            ->call('register')
            ->assertHasNoFormErrors();

        $puesto = Empresa::where('ruc', '10456789123')->firstOrFail();
        $this->assertEqualsCanonicalizing(['B001', 'BC01', 'BD01'], $puesto->series()->pluck('serie')->all());

        // El formulario no muestra "Factura" y los accesos rápidos no muestran "F"
        Filament::setTenant($puesto);
        Livewire::test(CreateComprobante::class)->assertDontSee('Factura')->assertSee('Boleta de venta');
        $this->get('/admin/10456789123')->assertOk()->assertDontSee('title="Factura"', false)->assertSee('title="Boleta"', false);

        // La empresa con régimen MYPE sí la ve
        Filament::setTenant($this->empresa);
        Livewire::test(CreateComprobante::class)->assertSee('Factura');
    }

    // ───────────────────────── Pantallas ─────────────────────────

    public function test_pantalla_de_ventas_y_acciones_en_lenguaje_simple(): void
    {
        $this->actingAs(User::first());
        Filament::setCurrentPanel('admin');
        Filament::setTenant($this->empresa);
        Filament::bootCurrentPanel();

        $factura = $this->svc->emitir($this->borrador(TipoComprobante::Factura, 'F001', '100'))->refresh();
        $venta = $factura->venta;

        Livewire::test(ListVentas::class)->assertCanSeeTableRecords([$venta])->assertSee('V-000001');
        $this->get(VentaResource::getUrl('index'))->assertOk();

        Livewire::test(ViewVenta::class, ['record' => $venta->getRouteKey()])
            ->assertSee('F001-00000001')
            ->assertActionVisible('devolver')
            ->assertActionVisible('anular')
            ->assertActionHidden('corregir');

        // "Anular venta" abre la nota de crédito ya armada: motivo 01 y sustento escritos
        Livewire::withQueryParams(['referencia' => $factura->id, 'motivo' => '01'])->test(CreateComprobante::class)
            ->assertSet('data.motivo_codigo', '01')
            ->assertSet('data.motivo_descripcion', 'Anulación de la operación')
            ->call('guardarYEmitir')
            ->assertHasNoFormErrors();

        $venta->refresh();
        $this->assertSame(EstadoVenta::Anulada, $venta->estado);

        // Una venta anulada ya no ofrece devolver ni anular
        Livewire::test(ViewVenta::class, ['record' => $venta->getRouteKey()])
            ->assertActionHidden('devolver')
            ->assertActionHidden('anular');
    }

    public function test_las_ventas_de_otra_empresa_no_son_visibles(): void
    {
        $factura = $this->svc->emitir($this->borrador(TipoComprobante::Factura, 'F001'))->refresh();

        $otra = Empresa::create(['ruc' => '20555555551', 'razon_social' => 'OTRA EMPRESA SAC']);
        $intruso = User::factory()->create();
        $intruso->empresas()->attach($otra);

        $this->actingAs($intruso)->get('/admin/20555555551/ventas/'.$factura->venta_id)->assertNotFound();
        $this->actingAs($intruso)->get('/admin/20555555551/ventas')->assertOk()->assertDontSee('V-000001');
    }
}
