<?php

namespace Tests\Feature;

use App\Enums\EstadoComprobante;
use App\Enums\TipoComprobante;
use App\Filament\Pages\Tenancy\RegistrarEmpresa;
use App\Filament\Resources\Clientes\Pages\ManageClientes;
use App\Filament\Resources\Comprobantes\ComprobanteResource;
use App\Filament\Resources\Comprobantes\Pages\CreateComprobante;
use App\Filament\Resources\Comprobantes\Pages\EditComprobante;
use App\Filament\Resources\Comprobantes\Pages\ListComprobantes;
use App\Filament\Resources\Comprobantes\Pages\ViewComprobante;
use App\Filament\Resources\Series\Pages\ManageSeries;
use App\Models\Cliente;
use App\Models\Comprobante;
use App\Models\Empresa;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Los mismos flujos, pero a través de las pantallas reales (formularios y botones). */
class PanelFlujosTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->empresa = Empresa::first();
        $this->entrarComo(User::first(), $this->empresa);
    }

    private function entrarComo(User $user, ?Empresa $empresa): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($empresa);
        Filament::bootCurrentPanel();
    }

    private function cliente(string $doc): Cliente
    {
        return $this->empresa->clientes()->where('numero_documento', $doc)->firstOrFail();
    }

    /** Llena el formulario de comprobante con un producto del catálogo */
    private function formulario(string $tipo, Cliente $cliente, string $codigoProducto = 'P002', int $cantidad = 1)
    {
        $lw = Livewire::test(CreateComprobante::class)
            ->fillForm(['tipo_comprobante' => $tipo])
            ->fillForm(['cliente_id' => $cliente->id]);

        $key = array_key_first($lw->get('data.items'));

        return $lw->set("data.items.{$key}.producto_id", $this->empresa->productos()->where('codigo', $codigoProducto)->value('id'))
            ->set("data.items.{$key}.cantidad", $cantidad);
    }

    // ───────────────────────── Alta de empresa ─────────────────────────

    public function test_usuario_nuevo_registra_su_empresa_y_queda_lista_para_facturar(): void
    {
        $nuevo = User::factory()->create();
        $nuevo->forceFill(['es_superadmin' => true])->save(); // solo el superadmin da de alta empresas
        $this->entrarComo($nuevo, null);

        Livewire::test(RegistrarEmpresa::class)
            ->fillForm([
                'ruc' => '20601234567',
                'razon_social' => 'MI NEGOCIO SAC',
                'pse_proveedor' => 'fake',
                'pse_entorno' => 'beta',
            ])
            ->call('register')
            ->assertHasNoFormErrors();

        $empresa = Empresa::where('ruc', '20601234567')->firstOrFail();
        $this->assertTrue($nuevo->canAccessTenant($empresa));
        $this->assertEqualsCanonicalizing(['F001', 'B001', 'FC01', 'BC01', 'FD01', 'BD01'], $empresa->series()->pluck('serie')->all());
        $this->assertSame(1, $empresa->clientes()->count());
    }

    public function test_registro_de_empresa_valida_el_ruc(): void
    {
        $this->entrarComo(tap(User::factory()->create())->forceFill(['es_superadmin' => true]), null);

        Livewire::test(RegistrarEmpresa::class)
            ->fillForm(['ruc' => '12345678901', 'razon_social' => 'X'])
            ->call('register')
            ->assertHasFormErrors(['ruc']);

        Livewire::test(RegistrarEmpresa::class)
            ->fillForm(['ruc' => $this->empresa->ruc, 'razon_social' => 'X'])
            ->call('register')
            ->assertHasFormErrors(['ruc' => 'unique']);

        // Elegir Gior exige token
        Livewire::test(RegistrarEmpresa::class)
            ->fillForm(['ruc' => '20601234567', 'razon_social' => 'X', 'pse_proveedor' => 'gior'])
            ->call('register')
            ->assertHasFormErrors(['pse_token']);
    }

    // ───────────────────────── Factura: error → corregir → emitir ─────────────────────────

    public function test_factura_a_dni_queda_en_borrador_se_corrige_y_se_emite(): void
    {
        // 1. Intentar emitir factura a un cliente con DNI: se guarda como borrador, no se emite
        $lw = $this->formulario('01', $this->cliente('45678912'))
            ->call('guardarYEmitir')
            ->assertHasNoFormErrors()
            ->assertNotified('Se guardó como borrador, pero no se pudo emitir');
        $lw->assertRedirect(ComprobanteResource::getUrl('edit', ['record' => Comprobante::latest('id')->first()]));

        $c = Comprobante::latest('id')->first();
        // Lo lleva al formulario de edición para corregir (no al detalle)
        Livewire::test(EditComprobante::class, ['record' => $c->getRouteKey()])->assertOk();
        $this->assertSame(EstadoComprobante::Borrador, $c->estado);
        $this->assertNull($c->correlativo);

        // 2. Editar: cambiar a cliente con RUC y emitir
        Livewire::test(EditComprobante::class, ['record' => $c->getRouteKey()])
            ->fillForm(['cliente_id' => $this->cliente('20100070970')->id])
            ->call('guardarYEmitir')
            ->assertHasNoFormErrors()
            ->assertRedirect(ComprobanteResource::getUrl('view', ['record' => $c]));

        $c->refresh();
        $this->assertSame(EstadoComprobante::Aceptado, $c->estado);
        $this->assertSame('F001-00000001', $c->numero);

        // 3. Ya emitido: no se puede editar ni borrar
        $this->get(ComprobanteResource::getUrl('edit', ['record' => $c]))->assertForbidden();
        $this->assertFalse(ComprobanteResource::canDelete($c));
    }

    public function test_campos_obligatorios_del_formulario(): void
    {
        Livewire::test(CreateComprobante::class)
            ->fillForm(['cliente_id' => null, 'serie_id' => null])
            ->call('create')
            ->assertHasFormErrors(['cliente_id' => 'required', 'serie_id' => 'required']);

        // Crédito sin vencimiento
        $this->formulario('03', $this->cliente('45678912'))
            ->fillForm(['forma_pago' => 'Credito'])
            ->call('create')
            ->assertHasFormErrors(['fecha_vencimiento' => 'required']);
    }

    public function test_borrador_se_puede_eliminar(): void
    {
        $this->formulario('03', $this->cliente('45678912'))->call('create')->assertHasNoFormErrors();
        $c = Comprobante::latest('id')->first();

        Livewire::test(EditComprobante::class, ['record' => $c->getRouteKey()])
            ->callAction('delete');

        $this->assertModelMissing($c);
    }

    // ───────────────────────── Nota de crédito desde la factura ─────────────────────────

    public function test_nota_de_credito_desde_la_vista_de_la_factura(): void
    {
        $this->formulario('01', $this->cliente('20100070970'), 'P002', 2)->call('guardarYEmitir');
        $factura = Comprobante::latest('id')->first();
        $this->assertSame('119.80', $factura->total);

        // "Nota de crédito" precarga cliente, serie FC01 e ítems
        $nc = Livewire::withQueryParams(['referencia' => $factura->id])->test(CreateComprobante::class)
            ->assertSet('data.tipo_comprobante', TipoComprobante::NotaCredito->value)
            ->assertSet('data.comprobante_referencia_id', $factura->id)
            ->assertSet('data.cliente_id', $factura->cliente_id)
            ->assertSet('data.serie_id', $this->empresa->series()->where('serie', 'FC01')->value('id'));

        $this->assertCount(1, $nc->get('data.items'));

        // Falta el motivo: no se puede guardar
        $nc->call('guardarYEmitir')->assertHasFormErrors(['motivo_codigo', 'motivo_descripcion']);

        $nc->fillForm(['motivo_codigo' => '06', 'motivo_descripcion' => 'Devolución total'])
            ->call('guardarYEmitir')
            ->assertHasNoFormErrors();

        $nota = Comprobante::latest('id')->first();
        $this->assertSame('FC01-00000001', $nota->numero);
        $this->assertSame(EstadoComprobante::Aceptado, $nota->estado);
        $this->assertSame('119.80', $nota->total);

        // Una segunda nota por el total ya no se puede emitir (no queda saldo)
        Livewire::withQueryParams(['referencia' => $factura->id])->test(CreateComprobante::class)
            ->fillForm(['motivo_codigo' => '06', 'motivo_descripcion' => 'Duplicada'])
            ->call('guardarYEmitir')
            ->assertNotified('Se guardó como borrador, pero no se pudo emitir');
    }

    // ───────────────────────── Listado ─────────────────────────

    public function test_busqueda_por_numero_y_pestanas(): void
    {
        foreach (range(1, 3) as $_) {
            $this->formulario('01', $this->cliente('20100070970'))->call('guardarYEmitir');
        }
        $this->formulario('03', $this->cliente('45678912'))->call('guardarYEmitir');
        [$f1, $f2, $f3, $b1] = Comprobante::orderBy('id')->get()->all();
        $f2->update(['estado' => EstadoComprobante::Rechazado]);

        Livewire::test(ListComprobantes::class)
            ->searchTable('F001-2')->assertCanSeeTableRecords([$f2])->assertCanNotSeeTableRecords([$f1, $f3, $b1])
            ->searchTable('F001-00000003')->assertCanSeeTableRecords([$f3])->assertCanNotSeeTableRecords([$f1, $f2])
            ->searchTable('00000001')->assertCanSeeTableRecords([$f1, $b1])->assertCanNotSeeTableRecords([$f2, $f3])
            ->searchTable('B001')->assertCanSeeTableRecords([$b1])->assertCanNotSeeTableRecords([$f1])
            ->searchTable('JUAN')->assertCanSeeTableRecords([$b1])->assertCanNotSeeTableRecords([$f1]);

        Livewire::withQueryParams(['tab' => 'por-atender'])->test(ListComprobantes::class)
            ->assertCanSeeTableRecords([$f2])
            ->assertCanNotSeeTableRecords([$f1, $f3, $b1]);
    }

    public function test_rechazado_se_corrige_desde_la_vista(): void
    {
        $this->formulario('01', $this->cliente('20100070970'))->call('guardarYEmitir');
        $c = Comprobante::latest('id')->first();
        $c->update(['estado' => EstadoComprobante::Rechazado, 'sunat_mensaje' => 'Rechazado por prueba']);

        Livewire::test(ViewComprobante::class, ['record' => $c->getRouteKey()])
            ->assertActionHidden('emitir')
            ->assertActionVisible('duplicar')
            ->callAction('duplicar')
            ->assertRedirect();

        $copia = Comprobante::latest('id')->first();
        $this->assertNotSame($c->id, $copia->id);
        $this->assertSame(EstadoComprobante::Borrador, $copia->estado);
    }

    // ───────────────────────── Maestros ─────────────────────────

    public function test_cliente_con_comprobantes_no_se_puede_borrar(): void
    {
        $this->formulario('03', $this->cliente('45678912'))->call('guardarYEmitir');
        $conVentas = $this->cliente('45678912');
        $sinVentas = $this->cliente('20100070970');

        Livewire::test(ManageClientes::class)
            ->assertTableActionHidden('delete', $conVentas)
            ->assertTableActionVisible('delete', $sinVentas)
            ->callTableAction('delete', $sinVentas);

        $this->assertModelExists($conVentas);
        $this->assertModelMissing($sinVentas);
    }

    public function test_validaciones_de_cliente(): void
    {
        $crear = fn (array $datos) => Livewire::test(ManageClientes::class)->callAction('create', $datos);

        $crear(['tipo_documento' => '6', 'numero_documento' => '2010007097', 'razon_social' => 'X'])
            ->assertHasActionErrors(['numero_documento']);
        $crear(['tipo_documento' => '1', 'numero_documento' => '1234567', 'razon_social' => 'X'])
            ->assertHasActionErrors(['numero_documento']);
        // Duplicado dentro de la misma empresa
        $crear(['tipo_documento' => '1', 'numero_documento' => '45678912', 'razon_social' => 'X'])
            ->assertHasActionErrors(['numero_documento' => 'unique']);
        // El mismo DNI en OTRA empresa no cuenta como duplicado
        Filament::setTenant(null);
        $otra = Empresa::create(['ruc' => '20555555551', 'razon_social' => 'OTRA']);
        $otra->clientes()->create(['tipo_documento' => '1', 'numero_documento' => '11112222', 'razon_social' => 'Y']);
        Filament::setTenant($this->empresa);
        $crear(['tipo_documento' => '1', 'numero_documento' => '11112222', 'razon_social' => 'Z'])
            ->assertHasNoActionErrors();
    }

    public function test_validaciones_de_serie(): void
    {
        $crear = fn (array $datos) => Livewire::test(ManageSeries::class)->callAction('create', $datos);

        $crear(['tipo_comprobante' => '01', 'serie' => 'B002'])->assertHasActionErrors(['serie']);
        $crear(['tipo_comprobante' => '03', 'serie' => 'B01'])->assertHasActionErrors(['serie']);
        $crear(['tipo_comprobante' => '01', 'serie' => 'F001'])->assertHasActionErrors(['serie' => 'unique']);
        $crear(['tipo_comprobante' => '01', 'serie' => 'f002', 'correlativo' => 150])->assertHasNoActionErrors();

        $this->assertDatabaseHas('series', ['serie' => 'F002', 'correlativo' => 150, 'empresa_id' => $this->empresa->id]);
    }
}
