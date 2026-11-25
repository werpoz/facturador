<?php

namespace Tests\Feature;

use App\Enums\EstadoComprobante;
use App\Enums\EstadoVenta;
use App\Enums\MedioPago;
use App\Enums\RegimenTributario;
use App\Enums\TipoComprobante;
use App\Filament\Pages\PuntoDeVenta;
use App\Models\Comprobante;
use App\Models\Empresa;
use App\Models\Pago;
use App\Models\Producto;
use App\Models\User;
use App\Models\Venta;
use App\Services\Ventas\VentaRapidaService;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** Punto de venta: vender, cobrar y emitir en un solo paso. */
class PosTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->empresa = Empresa::first();
        $this->entrarComo('vendedor@demo.pe'); // el POS es la herramienta del vendedor
    }

    private function entrarComo(string $email): void
    {
        auth()->logout();
        $this->actingAs(User::where('email', $email)->firstOrFail());
        Filament::setCurrentPanel('admin');
        Filament::setTenant($this->empresa);
        Filament::bootCurrentPanel();
    }

    private function producto(string $codigo): Producto
    {
        return $this->empresa->productos()->where('codigo', $codigo)->firstOrFail();
    }

    private function nadaGuardado(): void
    {
        $this->assertSame(0, Comprobante::count(), 'No debe quedar ningún comprobante');
        $this->assertSame(0, Venta::count(), 'No debe quedar ninguna venta');
        $this->assertSame(0, Pago::count(), 'No debe quedar ningún pago');
        $this->assertSame(0, (int) $this->empresa->series()->sum('correlativo'), 'No debe consumirse ningún número');
    }

    // ───────────────────────── Venta completa ─────────────────────────

    public function test_venta_en_efectivo_con_vuelto(): void
    {
        $mouse = $this->producto('P002');   // S/ 59.90
        $libro = $this->producto('L001');   // S/ 45.00 exonerado

        $pos = Livewire::test(PuntoDeVenta::class)
            ->assertSee('Mouse inalámbrico')
            ->call('agregar', $mouse->id)
            ->call('agregar', $mouse->id)   // tocar dos veces = 2 unidades
            ->call('agregar', $libro->id)
            ->assertSet('carrito.p'.$mouse->id.'.cantidad', '2')
            ->assertSee('164.80')
            ->set('recibido', '200')
            ->assertSee('Vuelto')
            ->call('cobrar')
            ->assertDispatched('venta-registrada')
            ->assertSee('Venta registrada')
            ->assertSee('B001-00000001');

        $c = Comprobante::sole();
        $this->assertSame(TipoComprobante::Boleta, $c->tipo_comprobante);
        $this->assertSame(EstadoComprobante::Aceptado, $c->estado);
        $this->assertSame('164.80', $c->total);
        $this->assertSame('CLIENTES VARIOS', $c->cliente->razon_social);
        $this->assertSame(auth()->id(), $c->user_id);
        $this->assertCount(2, $c->items);

        $venta = $c->venta;
        $this->assertSame(EstadoVenta::Confirmada, $venta->estado);

        $pago = $venta->pagos()->sole();
        $this->assertSame(MedioPago::Efectivo, $pago->medio);
        $this->assertSame(['164.80', '200.00', '35.20'], [$pago->monto, $pago->recibido, $pago->vuelto]);

        // "Nueva venta" deja todo limpio para el siguiente cliente
        $pos->call('nuevaVenta')
            ->assertSet('carrito', [])
            ->assertSet('recibido', null)
            ->assertSet('ultimaVentaId', null)
            ->assertDontSee('Venta registrada');
    }

    public function test_venta_con_yape_no_pide_vuelto(): void
    {
        Livewire::test(PuntoDeVenta::class)
            ->call('agregar', $this->producto('P002')->id)
            ->set('medio', 'yape')
            ->assertDontSee('Recibido')
            ->call('cobrar')
            ->assertSee('Venta registrada');

        $pago = Pago::sole();
        $this->assertSame(MedioPago::Yape, $pago->medio);
        $this->assertSame('59.90', $pago->monto);
        $this->assertNull($pago->recibido);
        $this->assertNull($pago->vuelto);
    }

    public function test_efectivo_sin_indicar_recibido_asume_pago_exacto(): void
    {
        Livewire::test(PuntoDeVenta::class)->call('agregar', $this->producto('P002')->id)->call('cobrar');

        $this->assertSame(['59.90', '0.00'], [Pago::sole()->recibido, Pago::sole()->vuelto]);
    }

    // ───────────────────────── Si algo falla, no queda nada a medias ─────────────────────────

    public function test_recibido_insuficiente_no_registra_nada(): void
    {
        Livewire::test(PuntoDeVenta::class)
            ->call('agregar', $this->producto('P002')->id)
            ->set('recibido', '50')
            ->assertSee('Falta')
            ->call('cobrar')
            ->assertNotDispatched('venta-registrada')
            ->assertNotified('No se pudo registrar la venta');

        $this->nadaGuardado();
    }

    public function test_factura_sin_ruc_no_registra_nada(): void
    {
        Livewire::test(PuntoDeVenta::class)
            ->call('agregar', $this->producto('P002')->id)
            ->set('tipo', '01')
            ->assertSee('La factura necesita un cliente con RUC')
            ->call('cobrar')
            ->assertNotified('No se pudo registrar la venta');

        $this->nadaGuardado();
    }

    public function test_cantidad_invalida_o_carrito_vacio_no_registra_nada(): void
    {
        Livewire::test(PuntoDeVenta::class)->call('cobrar')->assertNotified('No se pudo registrar la venta');

        $mouse = $this->producto('P002');
        Livewire::test(PuntoDeVenta::class)
            ->call('agregar', $mouse->id)
            ->set('carrito.p'.$mouse->id.'.cantidad', 'abc')
            ->call('cobrar')
            ->assertNotified('No se pudo registrar la venta');

        $this->nadaGuardado();
    }

    // ───────────────────────── Cliente y factura ─────────────────────────

    public function test_elegir_cliente_con_ruc_cambia_a_factura(): void
    {
        $ruc = $this->empresa->clientes()->where('numero_documento', '20100070970')->first();

        Livewire::test(PuntoDeVenta::class)
            ->call('agregar', $this->producto('S001')->id)
            ->set('buscarCliente', '2010')
            ->assertSee('CLIENTE CORPORATIVO S.A.')
            ->call('seleccionarCliente', $ruc->id)
            ->assertSet('tipo', '01')
            ->set('medio', 'transferencia')
            ->call('cobrar')
            ->assertSee('F001-00000001');

        $this->assertSame(TipoComprobante::Factura, Comprobante::sole()->tipo_comprobante);

        // Volver a "Clientes varios" regresa a boleta
        Livewire::test(PuntoDeVenta::class)
            ->call('seleccionarCliente', $ruc->id)
            ->call('usarClientesVarios')
            ->assertSet('tipo', '03');
    }

    public function test_registrar_cliente_nuevo_sin_salir_del_pos(): void
    {
        Livewire::test(PuntoDeVenta::class)
            ->set('buscarCliente', '70123456')
            ->assertSee('DNI nuevo')
            ->set('nuevoClienteNombre', 'maría lópez')
            ->call('registrarCliente')
            ->assertSet('buscarCliente', '')
            ->assertSee('MARÍA LÓPEZ');

        $this->assertDatabaseHas('clientes', ['numero_documento' => '70123456', 'razon_social' => 'MARÍA LÓPEZ', 'empresa_id' => $this->empresa->id]);

        // Un documento que ya existe no se ofrece como nuevo
        Livewire::test(PuntoDeVenta::class)->set('buscarCliente', '70123456')->assertDontSee('DNI nuevo');
    }

    public function test_no_se_puede_usar_un_cliente_o_producto_de_otra_empresa(): void
    {
        Filament::setTenant(null);
        $otra = Empresa::create(['ruc' => '20555555551', 'razon_social' => 'OTRA']);
        $clienteAjeno = $otra->clientes()->create(['tipo_documento' => '1', 'numero_documento' => '11112222', 'razon_social' => 'AJENO']);
        $productoAjeno = $otra->productos()->create(['descripcion' => 'Producto ajeno', 'precio_unitario' => 1]);
        Filament::setTenant($this->empresa);

        Livewire::test(PuntoDeVenta::class)
            ->assertDontSee('Producto ajeno')
            ->call('agregar', $productoAjeno->id)
            ->assertSet('carrito', [])
            ->call('seleccionarCliente', $clienteAjeno->id)
            ->assertDontSee('AJENO');
    }

    // ───────────────────────── Seguridad ─────────────────────────

    public function test_el_precio_siempre_sale_de_la_base_de_datos(): void
    {
        $mouse = $this->producto('P002');

        Livewire::test(PuntoDeVenta::class)
            ->call('agregar', $mouse->id)
            ->set('carrito.p'.$mouse->id.'.precio', '0.10') // intento de manipular el precio desde el navegador
            ->call('cobrar')
            ->assertNotified('No se pudo registrar la venta') // no se cobra un monto distinto al real
            ->assertSet('carrito.p'.$mouse->id.'.precio', '59.90') // y el precio vuelve al del catálogo
            ->call('cobrar')
            ->assertSee('Venta registrada');

        $this->assertSame('59.90', Comprobante::sole()->total);
    }

    public function test_el_vendedor_entra_al_pos(): void
    {
        $url = '/admin/'.$this->empresa->ruc.'/pos';

        $this->get($url)->assertOk()->assertSee('Buscar producto');
    }

    public function test_el_contador_no_puede_entrar_al_pos(): void
    {
        $this->entrarComo('contador@demo.pe');

        $this->get('/admin/'.$this->empresa->ruc.'/pos')->assertForbidden();
    }

    public function test_el_pos_no_emite_notas(): void
    {
        $this->expectException(ValidationException::class);

        app(VentaRapidaService::class)->registrar(
            $this->empresa, TipoComprobante::NotaCredito, $this->empresa->clientes()->value('id'),
            [['producto_id' => $this->producto('P002')->id, 'cantidad' => 1]], MedioPago::Efectivo,
        );
    }

    public function test_nuevo_rus_solo_ofrece_boleta(): void
    {
        $this->empresa->update(['regimen_tributario' => RegimenTributario::NuevoRus]);
        $ruc = $this->empresa->clientes()->where('numero_documento', '20100070970')->first();

        Livewire::test(PuntoDeVenta::class)
            ->assertDontSee('Factura')
            ->call('agregar', $this->producto('P002')->id)
            ->call('seleccionarCliente', $ruc->id)   // aunque tenga RUC, sigue siendo boleta
            ->assertSet('tipo', '03')
            ->set('tipo', '01')                       // ni forzándolo desde el navegador
            ->call('cobrar');

        $this->assertSame(TipoComprobante::Boleta, Comprobante::sole()->tipo_comprobante);
    }

    // ───────────────────────── Uso diario ─────────────────────────

    public function test_buscar_por_codigo_y_enter_agrega_el_producto(): void
    {
        Livewire::test(PuntoDeVenta::class)
            ->set('busqueda', 'S001')
            ->assertSee('Servicio de soporte')
            ->assertDontSee('Mouse inalámbrico')
            ->call('agregarPrimero')
            ->assertSet('busqueda', '')
            ->assertSet('carrito.p'.$this->producto('S001')->id.'.cantidad', '1');
    }

    public function test_sumar_restar_y_cantidades_decimales(): void
    {
        $mouse = $this->producto('P002');
        $clave = 'p'.$mouse->id;

        Livewire::test(PuntoDeVenta::class)
            ->call('agregar', $mouse->id)
            ->call('incrementar', $clave)
            ->assertSet("carrito.{$clave}.cantidad", '2')
            ->set("carrito.{$clave}.cantidad", '2,5')   // coma decimal, como se escribe en Perú
            ->assertSee('149.75')
            ->call('decrementar', $clave)
            ->assertSet("carrito.{$clave}.cantidad", '1.5')
            ->call('decrementar', $clave)
            ->call('decrementar', $clave)               // al llegar a cero se quita de la venta
            ->assertSet('carrito', []);
    }
}
