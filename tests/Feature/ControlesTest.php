<?php

namespace Tests\Feature;

use App\Enums\EstadoComprobante;
use App\Enums\RolEmpresa;
use App\Enums\TipoComprobante;
use App\Enums\TipoDocumentoIdentidad;
use App\Exceptions\ComprobanteInmutable;
use App\Filament\Pages\Tenancy\EditarEmpresa;
use App\Filament\Resources\Bitacoras\Pages\ListBitacora;
use App\Filament\Resources\Comprobantes\ComprobanteResource;
use App\Filament\Resources\Comprobantes\Pages\CreateComprobante;
use App\Filament\Resources\Productos\ProductoResource;
use App\Filament\Resources\Usuarios\Pages\ManageUsuarios;
use App\Jobs\EnviarComprobanteJob;
use App\Models\Bitacora;
use App\Models\Comprobante;
use App\Models\Empresa;
use App\Models\Membresia;
use App\Models\User;
use App\Models\Venta;
use App\Notifications\InvitacionUsuario;
use App\Services\Facturacion\EmisionService;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

/** Controles que pediría un auditor o SUNAT: impresión, simulador, inmutabilidad, roles, bitácora y plazos. */
class ControlesTest extends TestCase
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

    private function usuario(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function entrarComo(string $email): User
    {
        $user = $this->usuario($email);
        auth()->logout();
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($this->empresa);
        Filament::bootCurrentPanel();

        return $user;
    }

    private function borrador(TipoComprobante $tipo = TipoComprobante::Boleta, string $serie = 'B001', array $extra = []): Comprobante
    {
        $doc = str_starts_with($serie, 'F') ? TipoDocumentoIdentidad::Ruc : TipoDocumentoIdentidad::Dni;

        $c = $this->empresa->comprobantes()->create($extra + [
            'cliente_id' => $this->empresa->clientes()->where('tipo_documento', $doc)->value('id'),
            'serie_id' => $this->empresa->series()->where('serie', $serie)->value('id'),
            'tipo_comprobante' => $tipo, 'serie' => $serie,
            'fecha_emision' => today(), 'estado' => EstadoComprobante::Borrador,
        ]);
        $c->items()->create([
            'descripcion' => 'Producto', 'cantidad' => 1, 'precio_unitario' => '100', 'tipo_afectacion_igv' => '10',
            'valor_unitario' => 0, 'valor_venta' => 0, 'igv' => 0, 'total' => 0,
        ]);

        return $c;
    }

    private function usarGior(): void
    {
        $this->empresa->update(['pse_proveedor' => 'gior', 'pse_token' => 'token']);
        config(['facturacion.gior.url_beta' => 'https://pse.test/api']);
        Http::fake(['pse.test/*' => Http::response(['aceptado' => true, 'codigo' => '0', 'mensaje' => 'Aceptada', 'hash' => 'HASHREAL=='])]);
    }

    private function assertNoEmite(string $campo, Comprobante $c): void
    {
        try {
            $this->svc->emitir($c);
            $this->fail("Debió bloquearse por [{$campo}]");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($campo, $e->errors(), json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
        }
    }

    // ───────────────────────── 1. Impresión segura ─────────────────────────

    public function test_comprobante_simulado_se_imprime_sin_qr_y_con_marca_sin_valor(): void
    {
        $c = $this->svc->emitir($this->borrador())->refresh();
        $this->assertTrue($c->simulado);

        $this->actingAs($this->usuario('admin@demo.pe'))->get(route('comprobantes.imprimir', $c))
            ->assertOk()
            ->assertSee('DOCUMENTO DE PRUEBA · SIN VALOR TRIBUTARIO')
            ->assertDontSee('<svg', false)
            ->assertDontSee('Hash:')
            ->assertDontSee('Representación impresa');
    }

    public function test_solo_un_comprobante_real_y_aceptado_lleva_qr(): void
    {
        $this->usarGior();
        $c = $this->svc->emitir($this->borrador())->refresh();
        $this->assertFalse($c->simulado);

        $this->actingAs($this->usuario('admin@demo.pe'))->get(route('comprobantes.imprimir', $c))
            ->assertOk()
            ->assertSee('<svg', false)
            ->assertSee('Hash: HASHREAL==')
            ->assertSee('Representación impresa')
            ->assertDontSee('SIN VALOR');
    }

    public function test_comprobante_pendiente_o_rechazado_se_imprime_con_advertencia(): void
    {
        $this->usarGior();
        Queue::fake();
        $c = $this->svc->emitir($this->borrador());
        $admin = $this->usuario('admin@demo.pe');

        $this->actingAs($admin)->get(route('comprobantes.imprimir', $c))
            ->assertSee('PENDIENTE DE ENVÍO A SUNAT')->assertDontSee('<svg', false);

        $c->update(['estado' => EstadoComprobante::Rechazado]);
        $this->actingAs($admin)->get(route('comprobantes.imprimir', $c))
            ->assertSee('RECHAZADO POR SUNAT')->assertDontSee('<svg', false);
    }

    // ───────────────────────── 2. Simulador ─────────────────────────

    public function test_el_simulador_no_funciona_en_produccion(): void
    {
        config(['facturacion.permitir_simulador' => false]);
        $c = $this->borrador();

        $this->assertNoEmite('pse', $c);
        $this->assertNull($c->refresh()->correlativo, 'No debe consumir número');
    }

    public function test_no_se_mezclan_comprobantes_de_prueba_con_reales(): void
    {
        // La empresa hizo pruebas con el simulador...
        $this->svc->emitir($this->borrador());
        $this->svc->emitir($this->borrador(TipoComprobante::Factura, 'F001'));
        $this->assertSame(2, Venta::count());

        // ...y ahora conecta su PSE real: no puede emitir hasta borrar las pruebas
        $this->usarGior();
        $this->assertNoEmite('pse', $this->borrador());

        $this->actingAs($this->usuario('admin@demo.pe'));
        $this->assertSame(2, $this->svc->borrarDatosDePrueba($this->empresa));

        $this->assertSame(0, Comprobante::where('simulado', true)->count());
        $this->assertSame(0, (int) $this->empresa->series()->where('serie', 'B001')->value('correlativo'));
        $this->assertTrue(Bitacora::where('descripcion', 'like', 'Borró 2 comprobantes de prueba%')->exists());

        // La numeración real empieza limpia desde 1 (el borrador pendiente se conserva)
        $real = $this->svc->emitir(Comprobante::where('estado', EstadoComprobante::Borrador)->first())->refresh();
        $this->assertSame('B001-00000001', $real->numero);
        $this->assertFalse($real->simulado);
    }

    public function test_formulario_de_empresa_no_permite_simulador_en_produccion(): void
    {
        $this->entrarComo('admin@demo.pe');

        Livewire::test(EditarEmpresa::class)
            ->fillForm(['pse_proveedor' => 'fake', 'pse_entorno' => 'produccion'])
            ->call('save')
            ->assertHasFormErrors(['pse_entorno']);
    }

    // ───────────────────────── 3. Inmutabilidad ─────────────────────────

    public function test_un_comprobante_emitido_no_se_puede_alterar_ni_borrar_ni_por_codigo(): void
    {
        $c = $this->svc->emitir($this->borrador())->refresh();
        $otroCliente = $this->empresa->clientes()->where('tipo_documento', TipoDocumentoIdentidad::Ruc)->value('id');

        foreach ([
            fn () => $c->update(['cliente_id' => $otroCliente]),
            fn () => $c->update(['total' => '1.00']),
            fn () => $c->update(['fecha_emision' => today()->subDay()]),
            fn () => $c->update(['correlativo' => 999]),
            fn () => $c->delete(),
            fn () => $c->items()->first()->update(['precio_unitario' => '1']),
            fn () => $c->items()->first()->delete(),
            fn () => $c->items()->create(['descripcion' => 'Extra', 'cantidad' => 1, 'precio_unitario' => 1, 'valor_unitario' => 1, 'valor_venta' => 1, 'igv' => 0, 'total' => 1]),
        ] as $i => $intento) {
            try {
                $intento();
                $this->fail("El intento #{$i} debió ser bloqueado");
            } catch (ComprobanteInmutable) {
                $c->refresh();
            }
        }

        $this->assertSame('100.00', $c->total);
        $this->assertCount(1, $c->items);

        // Lo único que sí puede cambiar es el resultado del envío
        $c->update(['estado' => EstadoComprobante::Rechazado, 'sunat_mensaje' => 'x']);
        $this->assertSame(EstadoComprobante::Rechazado, $c->refresh()->estado);
    }

    public function test_un_borrador_si_se_puede_editar_y_borrar(): void
    {
        $c = $this->borrador();
        $c->update(['observaciones' => 'cambio']);
        $c->items()->first()->update(['precio_unitario' => '50']);
        $c->delete();

        $this->assertModelMissing($c);
    }

    // ───────────────────────── 4. Roles ─────────────────────────

    public function test_vendedor_emite_ventas_pero_no_anula_ni_devuelve(): void
    {
        $this->entrarComo('vendedor@demo.pe');

        // El vendedor vende productos del catálogo, a su precio
        $mouse = $this->empresa->productos()->where('codigo', 'P002')->first();
        $borrador = $this->borrador();
        $borrador->items()->delete();
        $borrador->items()->create([
            'producto_id' => $mouse->id, 'descripcion' => $mouse->descripcion, 'cantidad' => 1,
            'precio_unitario' => $mouse->precio_unitario, 'tipo_afectacion_igv' => '10',
            'valor_unitario' => 0, 'valor_venta' => 0, 'igv' => 0, 'total' => 0,
        ]);
        $boleta = $this->svc->emitir($borrador)->refresh();
        $this->assertSame(EstadoComprobante::Aceptado, $boleta->estado);

        // Nota de crédito: bloqueada en el servicio, aunque llegue por otra vía
        $nc = $this->borrador(TipoComprobante::NotaCredito, 'BC01', [
            'cliente_id' => $boleta->cliente_id, 'comprobante_referencia_id' => $boleta->id,
            'motivo_codigo' => '01', 'motivo_descripcion' => 'Anulación',
        ]);
        $this->assertNoEmite('permiso', $nc);

        // En pantalla ni siquiera ve la opción ni los botones
        Livewire::test(CreateComprobante::class)->assertDontSee('Nota de crédito')->assertSee('Boleta de venta');
        $this->get('/admin/'.$this->empresa->ruc.'/ventas/'.$boleta->venta_id)
            ->assertOk()->assertDontSee('Anular venta')->assertDontSee('Devolver productos');
        $this->assertFalse(ComprobanteResource::canEdit($nc));
    }

    public function test_vendedor_no_accede_a_configuracion(): void
    {
        $this->entrarComo('vendedor@demo.pe');
        $base = '/admin/'.$this->empresa->ruc;

        $this->get($base.'/series')->assertForbidden();
        $this->get($base.'/usuarios')->assertForbidden();
        $this->get($base.'/bitacora')->assertForbidden();
        $this->get($base.'/profile')->assertNotFound();
        $this->get($base.'/productos')->assertOk();   // puede consultar precios...
        $this->assertFalse(ProductoResource::canCreate()); // ...pero no cambiarlos
        $this->get($base.'/clientes')->assertOk();
    }

    public function test_contador_solo_consulta(): void
    {
        $this->entrarComo('contador@demo.pe');
        $base = '/admin/'.$this->empresa->ruc;

        $this->get($base.'/ventas')->assertOk();
        $this->get($base.'/comprobantes')->assertOk();
        $this->get($base.'/bitacora')->assertOk();
        $this->get($base.'/comprobantes/create')->assertForbidden();
        $this->get($base.'/usuarios')->assertForbidden();
        $this->assertNoEmite('permiso', $this->borrador());
    }

    public function test_usuario_sin_empresa_no_ve_nada(): void
    {
        $ajeno = User::factory()->create();

        $this->actingAs($ajeno)->get('/admin/'.$this->empresa->ruc)->assertNotFound();
        $this->actingAs($ajeno)->get('/admin/new')->assertNotFound(); // tampoco puede registrar una empresa
    }

    // ───────────────────────── 5. Usuarios y registro ─────────────────────────

    public function test_registro_publico_cerrado_y_solo_superadmin_crea_empresas(): void
    {
        $this->get('/admin/register')->assertRedirect('/admin/login'); // no existe página de registro

        $this->actingAs($this->usuario('vendedor@demo.pe'))->get('/admin/new')->assertNotFound();

        $this->artisan('facturador:crear-admin', ['email' => 'dueno@software.pe', '--password' => 'secreto123'])->assertSuccessful();
        $this->assertTrue(User::where('email', 'dueno@software.pe')->value('es_superadmin'));
    }

    public function test_superadmin_si_puede_registrar_empresas(): void
    {
        $this->actingAs($this->usuario('admin@demo.pe'))->get('/admin/new')->assertOk()->assertSee('Registrar empresa');
    }

    public function test_admin_gestiona_usuarios_de_su_empresa(): void
    {
        $this->entrarComo('admin@demo.pe');

        Notification::fake();

        // Usuario nuevo: el administrador NO elige su contraseña; al usuario le llega un enlace para crearla
        Livewire::test(ManageUsuarios::class)
            ->callAction('create', ['email' => 'Nuevo@Tienda.pe', 'nombre' => 'Nuevo Cajero', 'rol' => 'vendedor'])
            ->assertHasNoActionErrors();
        $nuevo = User::where('email', 'nuevo@tienda.pe')->firstOrFail();
        $this->assertSame(RolEmpresa::Vendedor, $nuevo->rolEn($this->empresa));
        Notification::assertSentTo($nuevo, InvitacionUsuario::class,
            fn ($n) => str_contains($n->url, '/admin/password-reset/reset') && str_contains($n->url, 'token='));

        // Agregar una cuenta que ya existe (de otra empresa) no toca su contraseña ni le envía invitación
        $existente = User::factory()->create(['email' => 'gerente@otra.pe']);
        $hashAntes = $existente->password;
        Livewire::test(ManageUsuarios::class)
            ->callAction('create', ['email' => 'gerente@otra.pe', 'nombre' => 'Otro nombre', 'rol' => 'contador'])
            ->assertHasNoActionErrors();
        $this->assertSame($hashAntes, $existente->refresh()->password);
        $this->assertNotSame('Otro nombre', $existente->name);
        Notification::assertNotSentTo($existente, InvitacionUsuario::class);

        // Repetirlo: ya es miembro
        Livewire::test(ManageUsuarios::class)
            ->callAction('create', ['email' => 'nuevo@tienda.pe', 'rol' => 'admin'])
            ->assertHasActionErrors(['email']);

        // Usuario nuevo sin nombre
        Livewire::test(ManageUsuarios::class)
            ->callAction('create', ['email' => 'otro@tienda.pe', 'rol' => 'vendedor'])
            ->assertHasActionErrors(['nombre']);

        // Quitar acceso
        $membresia = Membresia::where('user_id', $nuevo->id)->first();
        Livewire::test(ManageUsuarios::class)->callTableAction('delete', $membresia);
        $this->assertModelMissing($membresia);
        $this->assertModelExists($nuevo); // la cuenta se conserva

        // El único administrador no se puede quitar ni degradar; tampoco aparece "quitar" sobre uno mismo
        $propia = Membresia::where('user_id', auth()->id())->first();
        Livewire::test(ManageUsuarios::class)
            ->assertTableActionHidden('delete', $propia)
            ->callTableAction('edit', $propia, ['rol' => 'vendedor'])
            ->assertNotified('La empresa debe tener al menos un administrador.');
        $this->assertSame(RolEmpresa::Admin, $propia->refresh()->rol);
    }

    // ───────────────────────── 6. Bitácora ─────────────────────────

    public function test_la_bitacora_registra_quien_cambio_que(): void
    {
        $admin = $this->entrarComo('admin@demo.pe');

        $producto = $this->empresa->productos()->where('codigo', 'P002')->first();
        $producto->update(['precio_unitario' => '39.90']);

        $registro = Bitacora::where('modelo_tipo', 'Producto')->where('accion', 'modificó')->latest('id')->first();
        $this->assertSame($admin->id, $registro->user_id);
        $this->assertSame('Administrador', $registro->usuario_nombre);
        $this->assertSame($this->empresa->id, $registro->empresa_id);
        $this->assertEquals(['59.90', '39.90'], $registro->cambios['precio_unitario']);

        // Los secretos se registran sin mostrar su valor
        $this->empresa->update(['pse_token' => 'super-secreto', 'pse_entorno' => 'produccion']);
        $cambio = Bitacora::where('modelo_tipo', 'Empresa')->latest('id')->first();
        $this->assertSame(['beta', 'produccion'], $cambio->cambios['pse_entorno']);
        $this->assertStringNotContainsString('super-secreto', json_encode($cambio->cambios));
        $this->empresa->update(['pse_entorno' => 'beta']);

        // Emisión y eliminación de borradores
        $c = $this->svc->emitir($this->borrador());
        $this->assertTrue(Bitacora::where('accion', 'emitió')->where('modelo_id', $c->id)->exists());
        $this->borrador()->delete();
        $this->assertTrue(Bitacora::where('accion', 'eliminó')->where('modelo_tipo', 'Comprobante')->exists());

        Livewire::test(ListBitacora::class)->assertSee('Modificó producto Mouse inalámbrico');
    }

    public function test_la_bitacora_no_se_puede_modificar_ni_borrar(): void
    {
        $registro = Bitacora::registrar('prueba', 'Registro de prueba', $this->empresa);

        foreach ([fn () => $registro->update(['descripcion' => 'x']), fn () => $registro->delete()] as $intento) {
            try {
                $intento();
                $this->fail('La bitácora debe ser inalterable');
            } catch (LogicException) {
            }
        }

        // Lo que hace la cola queda a nombre del "Sistema"
        auth()->logout();
        $this->empresa->productos()->first()->update(['descripcion' => 'Cambiado por un proceso']);
        $this->assertSame('Sistema', Bitacora::latest('id')->first()->usuario_nombre);
    }

    // ───────────────────────── 7. Numeración de ventas ─────────────────────────

    public function test_un_numero_de_venta_nunca_se_reutiliza(): void
    {
        $primera = $this->borrador();
        $this->assertSame(1, $primera->refresh()->venta->numero);

        $primera->delete(); // se elimina el borrador y su venta V-000001
        $this->assertSame(0, Venta::count());

        $this->assertSame(2, $this->borrador()->refresh()->venta->numero);
    }

    // ───────────────────────── 8. Vigilancia de plazos ─────────────────────────

    public function test_vigilante_reencola_atascados_y_alerta_antes_de_vencer(): void
    {
        Queue::fake();
        $atascado = $this->svc->emitir($this->borrador(extra: ['fecha_emision' => today()->subDays(3)])); // vence HOY
        $reciente = $this->svc->emitir($this->borrador());
        Comprobante::whereKey($atascado->id)->update(['estado' => EstadoComprobante::Error, 'updated_at' => now()->subHour()]);
        Queue::fake(); // reinicia el conteo

        $this->artisan('facturacion:vigilar')->assertSuccessful();

        Queue::assertPushed(EnviarComprobanteJob::class, fn ($job) => $job->comprobanteId === $atascado->id);
        Queue::assertNotPushed(EnviarComprobanteJob::class, fn ($job) => $job->comprobanteId === $reciente->id);

        // Avisa al administrador, no al vendedor
        $admin = $this->usuario('admin@demo.pe');
        $this->assertSame(1, $admin->notifications()->count());
        $this->assertStringContainsString('vence HOY', $admin->notifications()->first()->data['title']);
        $this->assertSame(0, $this->usuario('vendedor@demo.pe')->notifications()->count());

        // Una sola alerta por día por comprobante (aunque el vigilante lo vuelva a encontrar atascado)
        Comprobante::whereKey($atascado->id)->update(['updated_at' => now()->subHour()]);
        $this->artisan('facturacion:vigilar');
        $this->assertSame(1, $admin->notifications()->count());

        // Al día siguiente ya venció: nueva alerta, más grave
        $this->travel(1)->days();
        $this->artisan('facturacion:vigilar');
        $this->assertStringContainsString('venció el plazo', $admin->notifications()->latest()->first()->data['title']);
    }

    public function test_dias_para_vencer(): void
    {
        $c = $this->borrador(extra: ['fecha_emision' => today()->subDays(2)]);
        $this->assertSame(1, $c->diasParaVencer());

        $this->travel(2)->days();
        $this->assertSame(-1, $c->diasParaVencer());
    }
}
