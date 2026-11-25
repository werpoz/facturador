<?php

namespace Tests\Feature;

use App\Enums\EstadoComprobante;
use App\Enums\EstadoVenta;
use App\Enums\TipoComprobante;
use App\Exceptions\ComprobanteInmutable;
use App\Filament\Pages\PuntoDeVenta;
use App\Filament\Pages\Tenancy\EditarEmpresa;
use App\Filament\Resources\Comprobantes\Pages\CreateComprobante;
use App\Filament\Resources\Productos\Pages\ManageProductos;
use App\Jobs\EnviarComprobanteJob;
use App\Models\Comprobante;
use App\Models\Empresa;
use App\Models\LogEnvio;
use App\Models\Producto;
use App\Models\User;
use App\Services\Facturacion\CalculadoraTotales;
use App\Services\Facturacion\EmisionService;
use App\Services\Facturacion\PayloadBuilder;
use App\Services\Inventario\StockService;
use App\Support\NumeroALetras;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/**
 * Regresiones de la auditoría independiente: cada prueba reproduce un hallazgo
 * que antes fallaba, para que no vuelva a aparecer.
 */
class AuditoriaTest extends TestCase
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

    /** @param array<int, array{0:string, 1:int|float|string, 2?:string, 3?:string}> $lineas [código, cantidad, precio, afectación] */
    private function borrador(array $lineas, TipoComprobante $tipo = TipoComprobante::Boleta, string $serie = 'B001', array $extra = []): Comprobante
    {
        $doc = str_starts_with($serie, 'F') ? '20100070970' : '45678912';
        $c = $this->empresa->comprobantes()->create($extra + [
            'cliente_id' => $this->empresa->clientes()->where('numero_documento', $doc)->value('id'),
            'serie_id' => $this->empresa->series()->where('serie', $serie)->value('id'),
            'tipo_comprobante' => $tipo, 'serie' => $serie,
            'fecha_emision' => today(), 'estado' => EstadoComprobante::Borrador,
        ]);
        foreach ($lineas as $l) {
            $p = $this->producto($l[0]);
            $c->items()->create([
                'producto_id' => $p->id, 'descripcion' => $p->descripcion, 'unidad_medida' => $p->unidad_medida, 'cantidad' => $l[1],
                'precio_unitario' => $l[2] ?? $p->precio_unitario, 'tipo_afectacion_igv' => $l[3] ?? $p->tipo_afectacion_igv,
                'valor_unitario' => 0, 'valor_venta' => 0, 'igv' => 0, 'total' => 0,
            ]);
        }

        return $c;
    }

    private function vender(array $lineas, TipoComprobante $tipo = TipoComprobante::Boleta, string $serie = 'B001'): Comprobante
    {
        return $this->svc->emitir($this->borrador($lineas, $tipo, $serie))->refresh();
    }

    private function nota(Comprobante $ref, array $lineas, string $motivo = '07', TipoComprobante $tipo = TipoComprobante::NotaCredito): Comprobante
    {
        $serie = $ref->serie[0].($tipo === TipoComprobante::NotaCredito ? 'C01' : 'D01');

        return $this->borrador($lineas, $tipo, $serie, [
            'cliente_id' => $ref->cliente_id, 'comprobante_referencia_id' => $ref->id,
            'motivo_codigo' => $motivo, 'motivo_descripcion' => 'Motivo',
        ]);
    }

    private function assertNoEmite(string $campo, Comprobante $c, ?string $contiene = null): void
    {
        try {
            $this->svc->emitir($c);
            $this->fail("Debió bloquearse por [{$campo}]");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($campo, $e->errors(), json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
            if ($contiene) {
                $this->assertStringContainsString($contiene, implode(' ', $e->errors()[$campo]));
            }
        }
    }

    // ───────────────────────── Devoluciones atadas a lo vendido ─────────────────────────

    public function test_una_devolucion_no_puede_inventar_stock(): void
    {
        $boleta = $this->vender([['P002', 5]]);
        $this->assertSame('35', $this->stock('P002'));

        // 500 unidades a S/ 0.01: el monto cabe en el saldo, pero las unidades no se vendieron
        $this->assertNoEmite('items', $this->nota($boleta, [['P002', 500, '0.01']]), 'se vendieron 5');

        // Un producto que no estaba en la boleta
        $this->assertNoEmite('items', $this->nota($boleta, [['P001', 1, '0.01']]), 'no está en');

        $this->assertSame(['35', '5'], [$this->stock('P002'), $this->stock('P001')]);

        // Devolver 3 y luego 2 es válido; una tercera unidad ya no
        $this->svc->emitir($this->nota($boleta, [['P002', 3]]));
        $this->svc->emitir($this->nota($boleta, [['P002', 2]]));
        $this->assertSame('40', $this->stock('P002'));
        $this->assertNoEmite('total', $this->nota($boleta, [['P002', 1]]));
        $this->assertSame('40', $this->stock('P002'));
    }

    public function test_comprobante_gratuito_no_permite_devoluciones_ilimitadas(): void
    {
        $this->entrarComo('admin@demo.pe');
        $regalo = $this->vender([['P002', 10, '59.90', '11']]); // todo gratuito: total 0.00
        $this->assertSame(['0.00', '30'], [$regalo->total, $this->stock('P002')]);

        $this->svc->emitir($this->nota($regalo, [['P002', 10, '59.90', '11']]));
        $this->assertSame('40', $this->stock('P002'));

        $this->assertNoEmite('items', $this->nota($regalo, [['P002', 10, '59.90', '11']]), 'quedan 0');
        $this->assertSame('40', $this->stock('P002'));
    }

    public function test_cambiar_el_control_de_stock_no_descuadra_las_devoluciones(): void
    {
        // Se vendió SIN control de stock: no salió nada del almacén...
        $this->producto('P002')->update(['controla_stock' => false]);
        $boleta = $this->vender([['P002', 4]]);
        $this->assertSame('40', $this->stock('P002'));

        // ...así que la devolución tampoco debe sumar nada, aunque ahora sí se controle
        $this->producto('P002')->update(['controla_stock' => true]);
        $this->svc->emitir($this->nota($boleta, [['P002', 4]]));
        $this->assertSame('40', $this->stock('P002'));
    }

    // ───────────────────────── Emisión: doble clic, reintentos, edición tardía ─────────────────────────

    public function test_doble_emision_del_mismo_borrador_solo_emite_una_vez(): void
    {
        $borrador = $this->borrador([['P002', 1]]);
        $otraPestana = Comprobante::find($borrador->id); // la misma boleta cargada en otra pestaña

        $this->svc->emitir($borrador);

        try {
            $this->svc->emitir($otraPestana);
            $this->fail('El segundo clic debió ser rechazado');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('estado', $e->errors());
        }

        $c = $borrador->refresh();
        $this->assertSame(['B001-00000001', EstadoComprobante::Aceptado], [$c->numero, $c->estado]);
        $this->assertSame(1, LogEnvio::where('comprobante_id', $c->id)->count(), 'Se envió una sola vez');
        $this->assertSame(1, (int) $this->empresa->series()->where('serie', 'B001')->value('correlativo'), 'Se usó un solo número');
        $this->assertSame('39', $this->stock('P002'), 'El stock se descontó una sola vez');
    }

    public function test_una_copia_vieja_no_puede_editar_un_comprobante_ya_emitido(): void
    {
        $borrador = $this->borrador([['P002', 1]]);
        $copiaVieja = Comprobante::find($borrador->id);
        $this->svc->emitir($borrador);

        $this->expectException(ComprobanteInmutable::class);
        $copiaVieja->update(['observaciones' => 'cambio tardío', 'fecha_emision' => today()->subDay()]);
    }

    // ───────────────────────── Respuestas del proveedor ─────────────────────────

    public function test_un_error_del_proveedor_no_se_confunde_con_un_rechazo_de_sunat(): void
    {
        Queue::fake();
        $this->empresa->update(['pse_proveedor' => 'gior', 'pse_token' => 't']);
        config(['facturacion.gior.url_beta' => 'https://pse.test/api']);

        $respuestas = [
            'token vencido' => Http::response(['message' => 'Unauthenticated'], 401),
            'límite de peticiones' => Http::response(['message' => 'Too many'], 429),
            'respuesta que no es JSON' => Http::response('<html>Mantenimiento</html>', 200),
            'JSON sin veredicto' => Http::response(['ok' => true], 200),
        ];

        foreach ($respuestas as $caso => $respuesta) {
            Http::swap(new Factory);
            Http::fake(['pse.test/*' => $respuesta]);
            $c = $this->svc->emitir($this->borrador([['P002', 1]]));

            try {
                (new EnviarComprobanteJob($c->id))->handle($this->svc);
                $this->fail("[{$caso}] debió quedar en Error y lanzar excepción para reintentar");
            } catch (RuntimeException) {
            }

            $c->refresh();
            $this->assertSame(EstadoComprobante::Error, $c->estado, "[{$caso}] no es un rechazo de SUNAT");
            $this->assertSame(EstadoVenta::EnProceso, $c->venta->estado, "[{$caso}] la venta sigue en proceso");
        }

        $this->assertSame('36', $this->stock('P002'), 'El stock NO se devuelve por un error de comunicación');

        // Solo un rechazo explícito es definitivo
        Http::swap(new Factory);
        Http::fake(['pse.test/*' => Http::response(['aceptado' => false, 'codigo' => '2800', 'mensaje' => 'Dato no permitido'])]);
        $c = $this->svc->emitir($this->borrador([['P002', 1]]));
        (new EnviarComprobanteJob($c->id))->handle($this->svc);
        $this->assertSame(EstadoComprobante::Rechazado, $c->refresh()->estado);
        $this->assertSame('36', $this->stock('P002'), 'En el rechazo sí vuelve');
    }

    public function test_un_comprobante_de_prueba_nunca_se_envia_al_proveedor_real(): void
    {
        Queue::fake();
        $prueba = $this->svc->emitir($this->borrador([['P002', 1]])); // numerado con el simulador, aún en cola
        $this->assertTrue($prueba->simulado);

        // La empresa conecta su PSE real con ese comprobante todavía pendiente
        $this->empresa->update(['pse_proveedor' => 'gior', 'pse_token' => 't']);
        config(['facturacion.gior.url_beta' => 'https://pse.test/api']);
        Http::fake();

        (new EnviarComprobanteJob($prueba->id))->handle($this->svc);

        Http::assertNothingSent();
        $this->assertSame(EstadoComprobante::Pendiente, $prueba->refresh()->estado);

        // Y sigue contando como dato de prueba: bloquea la emisión real hasta borrarlo
        $this->assertTrue($this->svc->tieneDatosDePrueba($this->empresa));
        $this->assertNoEmite('pse', $this->borrador([['P002', 1]]));
    }

    // ───────────────────────── Datos del cliente congelados ─────────────────────────

    public function test_editar_un_cliente_no_cambia_los_comprobantes_ya_emitidos(): void
    {
        $c = $this->vender([['P002', 1]]);
        $c->cliente->update(['razon_social' => 'OTRO NOMBRE', 'numero_documento' => '99999999']);

        $this->actingAs(User::first())->get(route('comprobantes.imprimir', $c))
            ->assertSee('JUAN PÉREZ QUISPE')->assertSee('45678912')
            ->assertDontSee('OTRO NOMBRE')->assertDontSee('99999999');

        $payload = app(PayloadBuilder::class)->construir($c->refresh());
        $this->assertSame(['45678912', 'JUAN PÉREZ QUISPE'], [$payload['cliente']['numero_documento'], $payload['cliente']['razon_social']]);
        $this->assertNotNull($c->emitido_at);
    }

    // ───────────────────────── Precios y cantidades ─────────────────────────

    public function test_vendedor_no_puede_alterar_precio_ni_marcar_gratuito_en_el_formulario(): void
    {
        $this->entrarComo('vendedor@demo.pe');
        $laptop = $this->producto('P001');

        $lw = Livewire::test(CreateComprobante::class)
            ->fillForm(['tipo_comprobante' => '03', 'cliente_id' => $this->empresa->clientes()->where('numero_documento', '45678912')->value('id')]);
        $key = array_key_first($lw->get('data.items'));

        $lw->set("data.items.{$key}.producto_id", $laptop->id)
            ->set("data.items.{$key}.precio_unitario", '0.01')        // precio manipulado
            ->set("data.items.{$key}.tipo_afectacion_igv", '11')       // "gratuito"
            ->set("data.items.{$key}.descripcion", 'Otra cosa')
            ->call('guardarYEmitir')
            ->assertHasNoFormErrors();

        $c = Comprobante::sole();
        $this->assertSame(EstadoComprobante::Aceptado, $c->estado);
        $this->assertSame('2499.00', $c->total, 'Se facturó al precio del catálogo');
        $this->assertSame('Laptop 15" Core i5', $c->items->first()->descripcion);

        // Aunque el borrador llegue por otra vía con el precio cambiado, el servicio lo rechaza
        $this->assertNoEmite('items', $this->borrador([['P001', 1, '1.00']]), 'no coincide con el catálogo');
    }

    public function test_cantidades_fraccionadas_solo_en_lo_que_se_vende_por_peso_o_medida(): void
    {
        $this->entrarComo('vendedor@demo.pe');

        // 0.01 laptops: antes salía una boleta de S/ 24.99
        $this->assertNoEmite('items', $this->borrador([['P001', '0.01']]), 'número entero');

        // En el POS tampoco
        $laptop = $this->producto('P001');
        Livewire::test(PuntoDeVenta::class)
            ->call('agregar', $laptop->id)
            ->set('carrito.p'.$laptop->id.'.cantidad', '0.01')
            ->call('cobrar')
            ->assertNotified('No se pudo registrar la venta');
        $this->assertSame(0, Comprobante::whereNotNull('correlativo')->count());

        // Un servicio por horas sí admite media hora
        $c = $this->vender([['S001', '0.5']]);
        $this->assertSame('40.00', $c->total);
    }

    public function test_si_el_precio_cambia_mientras_se_arma_la_venta_no_se_cobra_otro_monto(): void
    {
        $this->entrarComo('vendedor@demo.pe');
        $mouse = $this->producto('P002');

        $pos = Livewire::test(PuntoDeVenta::class)->call('agregar', $mouse->id)->set('medio', 'yape');

        $mouse->update(['precio_unitario' => '65.00']); // el dueño sube el precio en ese momento

        $pos->call('cobrar')
            ->assertNotified('No se pudo registrar la venta')
            ->assertSee('65.00'); // el POS muestra el precio nuevo para que el cajero lo confirme
        $this->assertSame(0, Comprobante::count());

        $pos->call('cobrar')->assertSee('Venta registrada');
        $this->assertSame('65.00', Comprobante::sole()->total);
    }

    // ───────────────────────── Venta con nota de débito ─────────────────────────

    public function test_venta_con_nota_de_debito_no_figura_anulada_si_queda_saldo(): void
    {
        $this->entrarComo('admin@demo.pe');
        $boleta = $this->vender([['S001', 1]]);                                             // 80
        $this->svc->emitir($this->nota($boleta, [['S001', 1, '50']], '01', TipoComprobante::NotaDebito)); // +50
        $this->svc->emitir($this->nota($boleta, [['S001', 1]], '04'));                      // -80 (descuento)

        $venta = $boleta->venta->refresh();
        $this->assertSame('50.00', $venta->neto);
        $this->assertSame(EstadoVenta::ConDevolucion, $venta->estado, 'Todavía quedan S/ 50: no está anulada');
    }

    // ───────────────────────── Protección de datos ─────────────────────────

    public function test_un_producto_con_historial_no_se_puede_borrar(): void
    {
        $this->entrarComo('admin@demo.pe');
        $this->vender([['P002', 1]]);
        $mouse = $this->producto('P002');
        $movimientos = $mouse->movimientos()->count();

        Livewire::test(ManageProductos::class)
            ->assertTableActionHidden('delete', $mouse)
            ->assertTableActionVisible('delete', $this->producto('S001'));

        try {
            $mouse->delete();
            $this->fail('No debió poder borrarse');
        } catch (LogicException) {
        }

        $this->assertSame($movimientos, $mouse->movimientos()->count(), 'El kardex sigue intacto');
    }

    public function test_una_empresa_con_registros_no_se_puede_borrar(): void
    {
        $this->vender([['P002', 1]]);

        $this->expectException(LogicException::class);
        $this->empresa->delete();
    }

    public function test_el_logo_no_acepta_svg_y_el_seeder_no_corre_en_produccion(): void
    {
        $this->entrarComo('admin@demo.pe');

        Livewire::test(EditarEmpresa::class)
            ->fillForm(['logo' => [UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')]])
            ->call('save')
            ->assertHasFormErrors(['logo']);

        $this->app->detectEnvironment(fn () => 'production');
        $this->expectException(RuntimeException::class);
        (new DatabaseSeeder)->run();
    }

    // ───────────────────────── Números ─────────────────────────

    public function test_lectura_de_numeros_y_monto_en_letras(): void
    {
        $calc = new CalculadoraTotales('0.18');

        foreach (['1.234,50' => '1234.50', '1,234.50' => '1234.50', '1234,50' => '1234.50', '1234.5' => '1234.50', '0,5' => '0.50'] as $escrito => $esperado) {
            $this->assertSame($esperado, $calc->calcularItem(1, $escrito, '20')['total'], "«{$escrito}»");
        }

        $this->assertSame('SON: VEINTIÚN MILLONES CON 00/100 SOLES', NumeroALetras::convertir(21000000));
        $this->assertSame('SON: TREINTA Y UN MILLONES QUINIENTOS MIL CON 00/100 SOLES', NumeroALetras::convertir(31500000));

        // 100 000 unidades a S/ 0.01: valor unitario × cantidad debe coincidir con el valor de venta (tolerancia SUNAT ±1)
        $item = $calc->calcularItem(100000, '0.01', '10');
        $this->assertEqualsWithDelta((float) $item['valor_venta'], (float) $item['valor_unitario'] * 100000, 0.01);
    }
}
