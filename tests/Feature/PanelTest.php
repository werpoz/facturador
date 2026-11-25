<?php

namespace Tests\Feature;

use App\Enums\EstadoComprobante;
use App\Filament\Resources\Clientes\Pages\ManageClientes;
use App\Filament\Resources\Comprobantes\ComprobanteResource;
use App\Filament\Resources\Comprobantes\Pages\CreateComprobante;
use App\Filament\Resources\Comprobantes\Pages\ListComprobantes;
use App\Filament\Resources\Comprobantes\Pages\ViewComprobante;
use App\Filament\Widgets\UltimosComprobantes;
use App\Filament\Widgets\VentasOverview;
use App\Models\Comprobante;
use App\Models\Empresa;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PanelTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->empresa = Empresa::first();
        $this->actingAs(User::first());
        Filament::setCurrentPanel('admin');
        Filament::setTenant($this->empresa);
        Filament::bootCurrentPanel();
    }

    public function test_paginas_principales_cargan(): void
    {
        $this->get('/admin/'.$this->empresa->ruc)->assertOk();
        Livewire::test(VentasOverview::class)->assertSee('Ventas del mes');
        Livewire::test(UltimosComprobantes::class)->assertOk();
        $this->get(ComprobanteResource::getUrl('index'))->assertOk();
        $this->get(ComprobanteResource::getUrl('create'))->assertOk()->assertSee('Guardar y emitir');
        $this->get('/admin/'.$this->empresa->ruc.'/clientes')->assertOk();
        $this->get('/admin/'.$this->empresa->ruc.'/productos')->assertOk();
        $this->get('/admin/'.$this->empresa->ruc.'/series')->assertOk();
        $this->get('/admin/'.$this->empresa->ruc.'/profile')->assertOk();
    }

    public function test_crear_y_emitir_boleta_desde_el_formulario(): void
    {
        $producto = $this->empresa->productos()->where('codigo', 'P002')->first();
        $cliente = $this->empresa->clientes()->where('numero_documento', '45678912')->first();

        $lw = Livewire::test(CreateComprobante::class)
            ->fillForm([
                'tipo_comprobante' => '03',
                'cliente_id' => $cliente->id,
            ]);

        // Reemplazar el ítem vacío por defecto con un producto
        $items = $lw->get('data.items');
        $key = array_key_first($items);
        $lw->set("data.items.{$key}.producto_id", $producto->id)
            ->assertSet("data.items.{$key}.descripcion", 'Mouse inalámbrico')
            ->set("data.items.{$key}.cantidad", 3)
            ->call('guardarYEmitir')
            ->assertHasNoFormErrors();

        $c = Comprobante::latest('id')->first();
        $this->assertSame('B001-00000001', $c->numero);
        $this->assertSame('179.70', $c->total);
        $this->assertSame('27.41', $c->igv);
        $this->assertSame(EstadoComprobante::Aceptado, $c->estado);

        Livewire::test(ViewComprobante::class, ['record' => $c->getRouteKey()])->assertOk()->assertSee('B001-00000001');
        Livewire::test(ListComprobantes::class)->assertCanSeeTableRecords([$c]);

        // Precarga de nota de crédito desde el comprobante
        $this->get(ComprobanteResource::getUrl('create', ['referencia' => $c->id]))
            ->assertOk()
            ->assertSee('Documento que modifica');
    }

    public function test_crear_cliente(): void
    {
        Livewire::test(ManageClientes::class)
            ->callAction('create', [
                'tipo_documento' => '6',
                'numero_documento' => '20123456789',
                'razon_social' => 'NUEVO CLIENTE SAC',
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('clientes', ['numero_documento' => '20123456789', 'empresa_id' => $this->empresa->id]);
    }
}
