<?php

namespace Tests\Feature;

use App\Enums\EstadoComprobante;
use App\Enums\TipoComprobante;
use App\Enums\TipoMovimientoStock;
use App\Filament\Pages\PuntoDeVenta;
use App\Filament\Resources\Kardex\Pages\ListKardex;
use App\Filament\Resources\Productos\Pages\ManageProductos;
use App\Models\Comprobante;
use App\Models\Empresa;
use App\Models\MovimientoStock;
use App\Models\Producto;
use App\Models\User;
use App\Services\Facturacion\EmisionService;
use App\Services\Inventario\StockService;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

/** Inventario: el stock es siempre la suma del kardex, y se mueve con las ventas y devoluciones. */
class StockTest extends TestCase
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
        $this->entrarComo('admin@demo.pe');
    }

    private function entrarComo(string $email): void
    {
        $this->actingAs(User::where('email', $email)->firstOrFail());
        Filament::setCurrentPanel('admin');
        Filament::setTenant($this->empresa);
        Filament::bootCurrentPanel();
    }

    private function producto(string $codigo): Producto
    {
        return $this->empresa->productos()->where('codigo', $codigo)->firstOrFail();
    }

    private function stock(string $codigo): string
    {
        return StockService::fmt($this->producto($codigo)->stock);
    }

    /** @param array<string, int|float> $lineas código => cantidad */
    private function borrador(array $lineas, TipoComprobante $tipo = TipoComprobante::Boleta, string $serie = 'B001', array $extra = []): Comprobante
    {
        $c = $this->empresa->comprobantes()->create($extra + [
            'cliente_id' => $this->empresa->clientes()->where('numero_documento', '45678912')->value('id'),
            'serie_id' => $this->empresa->series()->where('serie', $serie)->value('id'),
            'tipo_comprobante' => $tipo, 'serie' => $serie,
            'fecha_emision' => today(), 'estado' => EstadoComprobante::Borrador,
        ]);
        foreach ($lineas as $codigo => $cantidad) {
            $p = $this->producto($codigo);
            $c->items()->create([
                'producto_id' => $p->id, 'descripcion' => $p->descripcion, 'cantidad' => $cantidad,
                'precio_unitario' => $p->precio_unitario, 'tipo_afectacion_igv' => $p->tipo_afectacion_igv,
                'valor_unitario' => 0, 'valor_venta' => 0, 'igv' => 0, 'total' => 0,
            ]);
        }

        return $c;
    }

    private function vender(array $lineas): Comprobante
    {
        return $this->svc->emitir($this->borrador($lineas))->refresh();
    }

    private function assertKardexCuadra(): void
    {
        foreach ($this->empresa->productos as $p) {
            $suma = (float) MovimientoStock::where('producto_id', $p->id)->sum('cantidad');
            $this->assertEqualsWithDelta($suma, (float) $p->stock, 0.00001, "El stock de {$p->descripcion} no coincide con su kardex");
        }
    }

    // ───────────────────────── Ventas y devoluciones ─────────────────────────

    public function test_el_stock_inicial_queda_en_el_kardex(): void
    {
        $this->assertSame('40', $this->stock('P002'));
        $inicial = MovimientoStock::where('producto_id', $this->producto('P002')->id)->sole();
        $this->assertSame(TipoMovimientoStock::Inicial, $inicial->tipo);

        // Los servicios no llevan stock ni kardex
        $this->assertFalse($this->producto('S001')->controla_stock);
        $this->assertSame(0, MovimientoStock::where('producto_id', $this->producto('S001')->id)->count());
        $this->assertKardexCuadra();
    }

    public function test_un_borrador_no_mueve_stock_y_al_emitir_si(): void
    {
        $borrador = $this->borrador(['P002' => 3, 'S001' => 2]);
        $this->assertSame('40', $this->stock('P002'), 'Un borrador todavía no es una venta');

        $this->svc->emitir($borrador);
        $this->assertSame('37', $this->stock('P002'));

        $mov = MovimientoStock::where('comprobante_id', $borrador->id)->sole(); // el servicio no genera movimiento
        $this->assertSame(TipoMovimientoStock::Venta, $mov->tipo);
        $this->assertSame(['-3.0000', '37.0000'], [$mov->cantidad, $mov->stock_resultante]);
        $this->assertKardexCuadra();
    }

    public function test_devolucion_regresa_el_stock_y_un_descuento_no(): void
    {
        $boleta = $this->vender(['P002' => 5]);
        $this->assertSame('35', $this->stock('P002'));

        $nota = fn (string $motivo, int $cantidad) => $this->svc->emitir($this->borrador(['P002' => $cantidad], TipoComprobante::NotaCredito, 'BC01', [
            'comprobante_referencia_id' => $boleta->id, 'motivo_codigo' => $motivo, 'motivo_descripcion' => 'x',
        ]));

        $nota('07', 2); // devolución de 2 unidades
        $this->assertSame('37', $this->stock('P002'));
        $this->assertSame(TipoMovimientoStock::Devolucion, MovimientoStock::latest('id')->first()->tipo);

        $nota('04', 1); // descuento: es dinero, no mercadería
        $this->assertSame('37', $this->stock('P002'));
        $this->assertKardexCuadra();
    }

    public function test_si_sunat_rechaza_el_stock_vuelve_y_al_corregir_sale_de_nuevo(): void
    {
        $this->empresa->update(['pse_proveedor' => 'gior', 'pse_token' => 't']);
        config(['facturacion.gior.url_beta' => 'https://pse.test/api']);
        Http::fakeSequence('pse.test/*')
            ->push(['aceptado' => false, 'codigo' => '2800', 'mensaje' => 'Rechazado'])
            ->push(['aceptado' => true, 'codigo' => '0', 'mensaje' => 'Aceptada']);

        $rechazada = $this->vender(['P002' => 4]);
        $this->assertSame(EstadoComprobante::Rechazado, $rechazada->estado);
        $this->assertSame('40', $this->stock('P002'), 'El rechazo devuelve la mercadería al stock');
        $this->assertSame(TipoMovimientoStock::Reversion, MovimientoStock::latest('id')->first()->tipo);

        $corregida = $this->svc->emitir($this->svc->duplicar($rechazada))->refresh();
        $this->assertSame(EstadoComprobante::Aceptado, $corregida->estado);
        $this->assertSame('36', $this->stock('P002'));
        $this->assertKardexCuadra();
    }

    public function test_reprocesar_un_comprobante_no_descuenta_dos_veces(): void
    {
        $c = $this->vender(['P002' => 1]);
        $servicio = app(StockService::class);

        $servicio->aplicarComprobante($c);
        $servicio->aplicarComprobante($c);
        $this->assertSame('39', $this->stock('P002'));

        $c->update(['estado' => EstadoComprobante::Rechazado]);
        $servicio->revertirComprobante($c);
        $servicio->revertirComprobante($c);
        $this->assertSame('40', $this->stock('P002'));
    }

    // ───────────────────────── Vender sin stock ─────────────────────────

    public function test_por_defecto_se_puede_vender_sin_stock_y_queda_en_negativo(): void
    {
        $this->vender(['L001' => 5]); // solo había 3
        $this->assertSame('-2', $this->stock('L001'));
        $this->assertKardexCuadra();
    }

    public function test_si_la_empresa_lo_exige_no_se_vende_sin_stock(): void
    {
        $this->empresa->update(['bloquear_venta_sin_stock' => true]);
        $borrador = $this->borrador(['L001' => 5, 'P002' => 1]);

        try {
            $this->svc->emitir($borrador);
            $this->fail('Debió bloquear la venta');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('No hay stock suficiente de "Libro', $e->errors()['items'][0]);
        }

        // Nada se movió ni se consumió número
        $this->assertNull($borrador->refresh()->correlativo);
        $this->assertSame(['3', '40'], [$this->stock('L001'), $this->stock('P002')]);

        // Con la cantidad exacta sí pasa, y deja el stock en cero
        $this->vender(['L001' => 3]);
        $this->assertSame('0', $this->stock('L001'));
    }

    // ───────────────────────── Movimientos manuales ─────────────────────────

    public function test_ingreso_merma_y_ajuste_desde_la_pantalla_de_productos(): void
    {
        $mouse = $this->producto('P002');
        $mover = fn (array $datos) => Livewire::test(ManageProductos::class)->callTableAction('stock', $mouse, $datos);

        $mover(['tipo' => 'ingreso', 'cantidad' => 24, 'motivo' => 'Guía 001-55'])->assertHasNoTableActionErrors();
        $this->assertSame('64', $this->stock('P002'));

        $mover(['tipo' => 'merma', 'cantidad' => 4, 'motivo' => 'Cajas mojadas'])->assertHasNoTableActionErrors();
        $this->assertSame('60', $this->stock('P002'));

        // Ajuste: se escribe lo que se contó, y el kardex guarda la diferencia
        $mover(['tipo' => 'ajuste', 'cantidad' => 57, 'motivo' => 'Inventario de fin de mes'])->assertHasNoTableActionErrors();
        $this->assertSame('57', $this->stock('P002'));
        $ajuste = MovimientoStock::latest('id')->first();
        $this->assertSame(['-3.0000', 'Inventario de fin de mes', auth()->id()], [$ajuste->cantidad, $ajuste->motivo, $ajuste->user_id]);

        // La merma y el ajuste exigen motivo
        $mover(['tipo' => 'merma', 'cantidad' => 1])->assertHasTableActionErrors(['motivo']);
        $this->assertKardexCuadra();
    }

    public function test_el_stock_no_se_puede_cambiar_por_fuera_del_kardex(): void
    {
        $mouse = $this->producto('P002');
        $mouse->update(['stock' => 9999, 'descripcion' => 'Mouse']); // intento de editar el stock directamente

        $this->assertSame('40', $this->stock('P002'));
        $this->assertSame('Mouse', $mouse->refresh()->descripcion);

        $this->expectException(LogicException::class);
        MovimientoStock::first()->update(['cantidad' => 1]);
    }

    public function test_vendedor_ve_el_stock_pero_no_puede_ajustarlo(): void
    {
        $this->entrarComo('vendedor@demo.pe');

        Livewire::test(ManageProductos::class)
            ->assertSee('Mouse inalámbrico')
            ->assertTableActionHidden('stock', $this->producto('P002'));
    }

    public function test_crear_producto_con_stock_inicial(): void
    {
        Livewire::test(ManageProductos::class)
            ->callAction('create', [
                'descripcion' => 'Martillo', 'unidad_medida' => 'NIU', 'precio_unitario' => 25,
                'tipo_afectacion_igv' => '10', 'controla_stock' => true, 'stock' => 12, 'stock_minimo' => 3,
            ])
            ->assertHasNoActionErrors();

        $martillo = $this->empresa->productos()->where('descripcion', 'Martillo')->firstOrFail();
        $this->assertSame('12', StockService::fmt($martillo->stock));
        $this->assertSame(TipoMovimientoStock::Inicial, $martillo->movimientos()->sole()->tipo);
    }

    // ───────────────────────── Pantallas ─────────────────────────

    public function test_kardex_y_productos_por_reponer(): void
    {
        $this->vender(['P002' => 2]);

        Livewire::test(ListKardex::class)->assertSee('Venta')->assertSee('Stock inicial')->assertSee('B001-00000001');
        $this->get('/admin/'.$this->empresa->ruc.'/kardex')->assertOk();

        // El libro (3 de 5 mínimo) aparece por reponer; el mouse (38 de 10) no
        Livewire::test(\App\Filament\Widgets\StockBajo::class)->assertSee('Libro')->assertSee('Stock bajo')->assertDontSee('Mouse');
        Livewire::test(ManageProductos::class)
            ->filterTable('stock', 'reponer')
            ->assertCanSeeTableRecords([$this->producto('L001')])
            ->assertCanNotSeeTableRecords([$this->producto('P002')]);
    }

    public function test_el_pos_muestra_existencias_y_respeta_el_bloqueo(): void
    {
        $libro = $this->producto('L001');

        // Sin bloqueo: avisa pero deja vender
        Livewire::test(PuntoDeVenta::class)
            ->assertSee('Quedan 3')
            ->assertSee('Quedan 40')
            ->call('agregar', $libro->id)
            ->set('carrito.p'.$libro->id.'.cantidad', '5')
            ->assertSee('Solo quedan 3')
            ->call('cobrar')
            ->assertSee('Venta registrada');
        $this->assertSame('-2', $this->stock('L001'));

        // Con bloqueo: un producto agotado no se puede agregar
        $this->empresa->update(['bloquear_venta_sin_stock' => true]);
        Livewire::test(PuntoDeVenta::class)
            ->assertSee('Agotado')
            ->call('agregar', $libro->id)
            ->assertSet('carrito', [])
            ->assertNotified();

        // ...ni pasarse de lo que hay, aunque se escriba la cantidad a mano
        $mouse = $this->producto('P002');
        Livewire::test(PuntoDeVenta::class)
            ->call('agregar', $mouse->id)
            ->set('carrito.p'.$mouse->id.'.cantidad', '41')
            ->call('cobrar')
            ->assertNotified('No se pudo registrar la venta');
        $this->assertSame('40', $this->stock('P002'));
        $this->assertKardexCuadra();
    }

    public function test_borrar_datos_de_prueba_devuelve_el_stock(): void
    {
        $this->vender(['P002' => 6]);
        $this->assertSame('34', $this->stock('P002'));

        $this->svc->borrarDatosDePrueba($this->empresa);

        $this->assertSame('40', $this->stock('P002'));
        $this->assertKardexCuadra();
    }
}
