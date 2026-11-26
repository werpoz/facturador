<?php

namespace Tests\Feature;

use App\Enums\EstadoComprobante;
use App\Enums\TipoComprobante;
use App\Filament\Resources\Comprobantes\Pages\EditComprobante;
use App\Filament\Resources\Productos\Pages\ManageProductos;
use App\Models\Bitacora;
use App\Models\Comprobante;
use App\Models\Empresa;
use App\Models\Producto;
use App\Models\User;
use App\Services\Facturacion\CalculadoraTotales;
use App\Services\Facturacion\EmisionService;
use App\Services\Facturacion\PayloadBuilder;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** Cierre de los hallazgos que quedaron abiertos tras la auditoría. */
class CierreHallazgosTest extends TestCase
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
        $this->actingAs(User::where('email', 'admin@demo.pe')->first());
        Filament::setCurrentPanel('admin');
        Filament::setTenant($this->empresa);
        Filament::bootCurrentPanel();
    }

    private function producto(string $codigo): Producto
    {
        return $this->empresa->productos()->where('codigo', $codigo)->firstOrFail();
    }

    /** @param array<int, array{0:string, 1:int|float, 2?:string}> $lineas [código, cantidad, afectación] */
    private function borrador(array $lineas, TipoComprobante $tipo = TipoComprobante::Boleta, string $serie = 'B001'): Comprobante
    {
        $c = $this->empresa->comprobantes()->create([
            'cliente_id' => $this->empresa->clientes()->where('numero_documento', '45678912')->value('id'),
            'serie_id' => $this->empresa->series()->where('serie', $serie)->value('id'),
            'tipo_comprobante' => $tipo, 'serie' => $serie,
            'fecha_emision' => today(), 'estado' => EstadoComprobante::Borrador,
        ]);
        foreach ($lineas as $l) {
            $p = $this->producto($l[0]);
            $c->items()->create([
                'producto_id' => $p->id, 'descripcion' => $p->descripcion, 'unidad_medida' => $p->unidad_medida, 'cantidad' => $l[1],
                'precio_unitario' => $p->precio_unitario, 'tipo_afectacion_igv' => $l[2] ?? $p->tipo_afectacion_igv,
                'valor_unitario' => 0, 'valor_venta' => 0, 'igv' => 0, 'total' => 0,
            ]);
        }

        return $c;
    }

    // ───────────────────────── 1. Operaciones gratuitas ─────────────────────────

    public function test_item_gratuito_valor_unitario_cero_y_valor_referencial_aparte(): void
    {
        // Se regala 1 unidad cuyo precio de mercado es S/ 118.00 (con IGV)
        $item = (new CalculadoraTotales('0.18'))->calcularItem(1, '118.00', '11');

        $this->assertSame('0.0000000000', $item['valor_unitario'], 'El cliente no paga: valor unitario 0');
        $this->assertSame('100.0000000000', $item['valor_referencial'], 'Valor de mercado sin IGV');
        $this->assertSame('100.00', $item['valor_venta'], 'Base de la operación gratuita');
        $this->assertSame('18.00', $item['igv'], 'IGV de la operación gratuita (se informa aparte)');
        $this->assertSame('0.00', $item['total'], 'No se cobra');

        // Un ítem normal no tiene valor referencial
        $this->assertNull((new CalculadoraTotales('0.18'))->calcularItem(1, '118.00', '10')['valor_referencial']);
    }

    public function test_comprobante_con_venta_y_regalo(): void
    {
        // Vende 2 mouse (S/ 59.90) y regala 1 libro (S/ 45.00, marcado como gratuito gravado)
        $c = $this->svc->emitir($this->borrador([['P002', 2], ['L001', 1, '11']]))->refresh();

        $this->assertSame('101.53', $c->op_gravadas);
        $this->assertSame('18.27', $c->igv, 'El IGV a pagar es solo el de lo vendido');
        $this->assertSame('119.80', $c->total, 'El total a pagar no incluye el regalo');
        $this->assertSame('38.14', $c->op_gratuitas);
        $this->assertSame('6.86', $c->igv_gratuitas);

        $regalo = $c->items->firstWhere('descripcion', 'Libro "Contabilidad básica"');
        $this->assertSame(['0.0000000000', '0.00'], [$regalo->valor_unitario, $regalo->total]);
        $this->assertSame('38.1355932203', $regalo->valor_referencial);

        // Lo que se envía al PSE: precio 0, valor referencial, IGV gratuito y la leyenda 1002
        $payload = app(PayloadBuilder::class)->construir($c);
        $itemGratis = collect($payload['items'])->firstWhere('es_gratuito', true);
        $this->assertSame(['0.0000', '38.1355932203'], [$itemGratis['precio_unitario'], $itemGratis['valor_referencial']]);
        $this->assertSame('6.86', $payload['totales']['igv_gratuitas']);
        $this->assertSame('TRANSFERENCIA GRATUITA DE UN BIEN Y/O SERVICIO PRESTADO GRATUITAMENTE', $payload['leyendas']['1002']);
        $this->assertSame('SON: CIENTO DIECINUEVE CON 80/100 SOLES', $payload['leyendas']['1000']);

        // Y lo que se imprime
        $this->get(route('comprobantes.imprimir', $c))
            ->assertSee('TRANSFERENCIA GRATUITA')
            ->assertSee('Op. gratuitas')
            ->assertSee('119.80');

        // Un comprobante sin regalos no lleva esa leyenda
        $normal = $this->svc->emitir($this->borrador([['P002', 1]]))->refresh();
        $this->assertArrayNotHasKey('1002', $normal->leyendas());
    }

    // ───────────────────────── 2. Protección en la base de datos ─────────────────────────

    public function test_la_base_de_datos_impide_borrar_un_producto_con_historial(): void
    {
        $this->svc->emitir($this->borrador([['P002', 1]]));
        $mouse = $this->producto('P002');

        // Borrado directo en la base, saltándose toda la aplicación
        try {
            DB::table('productos')->where('id', $mouse->id)->delete();
            $this->fail('La base de datos debió impedirlo');
        } catch (QueryException $e) {
            $this->assertStringContainsStringIgnoringCase('foreign key', $e->getMessage());
        }

        $this->assertDatabaseHas('productos', ['id' => $mouse->id]);
        $this->assertSame(2, DB::table('movimientos_stock')->where('producto_id', $mouse->id)->count(), 'El kardex sigue completo');
    }

    public function test_la_base_de_datos_impide_borrar_una_empresa_con_registros(): void
    {
        $this->svc->emitir($this->borrador([['P002', 1]]));
        $bitacora = Bitacora::where('empresa_id', $this->empresa->id)->count();

        try {
            DB::table('empresas')->where('id', $this->empresa->id)->delete();
            $this->fail('La base de datos debió impedirlo');
        } catch (QueryException $e) {
            $this->assertStringContainsStringIgnoringCase('foreign key', $e->getMessage());
        }

        $this->assertSame(1, Comprobante::count());
        $this->assertSame($bitacora, Bitacora::where('empresa_id', $this->empresa->id)->count(), 'La bitácora sigue intacta');
    }

    public function test_un_producto_sin_ventas_si_se_puede_borrar(): void
    {
        $laptop = $this->producto('P001'); // solo tiene su "stock inicial"

        Livewire::test(ManageProductos::class)->callTableAction('delete', $laptop);

        $this->assertModelMissing($laptop);
        $this->assertSame(0, DB::table('movimientos_stock')->where('producto_id', $laptop->id)->count());
    }

    // ───────────────────────── 3. Bitácora de ítems en borradores ─────────────────────────

    public function test_los_cambios_de_items_en_un_borrador_quedan_en_la_bitacora(): void
    {
        $borrador = $this->borrador([['P002', 1], ['L001', 1]]);
        app()->forgetScopedInstances(); // termina la petición en la que se creó el borrador

        $delBorrador = fn () => Bitacora::where('modelo_tipo', 'Comprobante')->where('modelo_id', $borrador->id)
            ->where('descripcion', 'like', '%ítem%')->orderBy('id')->get();
        $this->assertCount(0, $delBorrador(), 'Los ítems con los que nace un comprobante no generan ruido');

        // Otra persona abre el borrador y lo edita: cambia la cantidad, quita un ítem y agrega otro
        $mouse = $borrador->items()->where('descripcion', 'Mouse inalámbrico')->first();
        $mouse->update(['cantidad' => 5]);
        $borrador->items()->where('descripcion', 'like', 'Libro%')->first()->delete();
        $laptop = $this->producto('P001');
        $borrador->items()->create([
            'producto_id' => $laptop->id, 'descripcion' => $laptop->descripcion, 'cantidad' => 1, 'precio_unitario' => $laptop->precio_unitario,
            'tipo_afectacion_igv' => '10', 'valor_unitario' => 0, 'valor_venta' => 0, 'igv' => 0, 'total' => 0,
        ]);

        $registros = $delBorrador();
        $this->assertSame(['modificó', 'quitó', 'agregó'], $registros->pluck('accion')->all());
        $this->assertEquals(['1.0000', 5], $registros[0]->cambios['cantidad']);
        $this->assertStringContainsString('Quitó el ítem "Libro', $registros[1]->descripcion);
        $this->assertStringContainsString('Agregó el ítem "Laptop', $registros[2]->descripcion);
        $this->assertSame(auth()->id(), $registros[0]->user_id);
        $this->assertSame($this->empresa->id, $registros[0]->empresa_id);

        // El recálculo automático de montos no es una acción de una persona: no ensucia la bitácora
        $antes = $delBorrador()->count();
        $this->svc->recalcular($borrador);
        $this->assertSame($antes, $delBorrador()->count());
    }

    public function test_editar_un_borrador_desde_el_formulario_deja_rastro(): void
    {
        $borrador = $this->borrador([['P002', 1]]);
        app()->forgetScopedInstances();

        $lw = Livewire::test(EditComprobante::class, ['record' => $borrador->getRouteKey()]);
        $key = array_key_first($lw->get('data.items'));
        $lw->set("data.items.{$key}.cantidad", 7)->call('save')->assertHasNoFormErrors();

        $registro = Bitacora::where('modelo_id', $borrador->id)->where('accion', 'modificó')->where('descripcion', 'like', '%ítem%')->latest('id')->first();
        $this->assertNotNull($registro, 'El cambio de cantidad quedó registrado');
        $this->assertEquals(7, (float) $registro->cambios['cantidad'][1]);
    }
}
