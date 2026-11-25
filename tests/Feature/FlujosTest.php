<?php

namespace Tests\Feature;

use App\Enums\EstadoComprobante;
use App\Enums\Moneda;
use App\Enums\TipoComprobante;
use App\Enums\TipoDocumentoIdentidad;
use App\Jobs\EnviarComprobanteJob;
use App\Models\Cliente;
use App\Models\Comprobante;
use App\Models\Empresa;
use App\Models\User;
use App\Services\Facturacion\EmisionService;
use App\Services\Facturacion\PseProvider;
use App\Services\Facturacion\RespuestaPse;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/** Proveedor que simula que el PSE está caído */
class ProveedorCaido implements PseProvider
{
    public function nombre(): string
    {
        return 'caido';
    }

    public function enviar(Comprobante $comprobante): RespuestaPse
    {
        throw new RuntimeException('Timeout de conexión');
    }
}

/** Proveedor real (no simulado) que acepta todo: representa al PSE cuando vuelve a estar disponible */
class ProveedorDisponible implements PseProvider
{
    public function nombre(): string
    {
        return 'disponible';
    }

    public function enviar(Comprobante $comprobante): RespuestaPse
    {
        return new RespuestaPse(estado: EstadoComprobante::Aceptado, codigo: '0', mensaje: 'Aceptada', hash: 'H', httpStatus: 200);
    }
}

/**
 * Recorre los flujos de negocio de punta a punta:
 * emisión, reglas SUNAT, notas, errores del PSE, reintentos y aislamiento entre empresas.
 */
class FlujosTest extends TestCase
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
        config(['facturacion.proveedores.caido' => ProveedorCaido::class, 'facturacion.proveedores.disponible' => ProveedorDisponible::class]);
    }

    // ───────────────────────── helpers ─────────────────────────

    private function cliente(TipoDocumentoIdentidad $doc, ?Empresa $empresa = null): Cliente
    {
        return ($empresa ?? $this->empresa)->clientes()->where('tipo_documento', $doc)->firstOrFail();
    }

    /** @param array<int, array{0:string|int, 1:string, 2?:string}> $items [cantidad, precio, afectacion] */
    private function borrador(TipoComprobante $tipo, string $serie, Cliente $cliente, array $items = [[1, '118']], array $extra = []): Comprobante
    {
        $c = $this->empresa->comprobantes()->create($extra + [
            'cliente_id' => $cliente->id,
            'serie_id' => $this->empresa->series()->where('serie', $serie)->value('id'),
            'tipo_comprobante' => $tipo,
            'serie' => $serie,
            'fecha_emision' => today(),
            'estado' => EstadoComprobante::Borrador,
        ]);

        foreach ($items as $i => $it) {
            $c->items()->create([
                'descripcion' => "Ítem {$i}", 'cantidad' => $it[0], 'precio_unitario' => $it[1],
                'tipo_afectacion_igv' => $it[2] ?? '10',
                'valor_unitario' => 0, 'valor_venta' => 0, 'igv' => 0, 'total' => 0, 'orden' => $i,
            ]);
        }

        return $c;
    }

    private function factura(string $precio = '118'): Comprobante
    {
        return $this->svc->emitir($this->borrador(TipoComprobante::Factura, 'F001', $this->cliente(TipoDocumentoIdentidad::Ruc), [[1, $precio]]))->refresh();
    }

    private function notaCredito(Comprobante $ref, string $precio, string $serie = 'FC01'): Comprobante
    {
        return $this->borrador(TipoComprobante::NotaCredito, $serie, $ref->cliente, [[1, $precio]], [
            'comprobante_referencia_id' => $ref->id,
            'motivo_codigo' => '04',
            'motivo_descripcion' => 'Descuento',
        ]);
    }

    private function assertRechazaCon(string $campo, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Se esperaba un error de validación en [{$campo}]");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($campo, $e->errors(), 'Errores recibidos: '.json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
        }
    }

    // ───────────────────────── Emisión básica ─────────────────────────

    public function test_correlativos_son_independientes_por_serie_y_sin_saltos(): void
    {
        $f1 = $this->factura();
        $b1 = $this->svc->emitir($this->borrador(TipoComprobante::Boleta, 'B001', $this->cliente(TipoDocumentoIdentidad::Dni)));
        $f2 = $this->factura();

        $this->assertSame('F001-00000001', $f1->numero);
        $this->assertSame('B001-00000001', $b1->refresh()->numero);
        $this->assertSame('F001-00000002', $f2->numero);
    }

    public function test_un_error_de_validacion_no_consume_correlativo(): void
    {
        $malo = $this->borrador(TipoComprobante::Factura, 'F001', $this->cliente(TipoDocumentoIdentidad::Dni));
        $this->assertRechazaCon('cliente_id', fn () => $this->svc->emitir($malo));

        $this->assertSame('F001-00000001', $this->factura()->numero);
    }

    public function test_los_montos_se_recalculan_en_el_servidor(): void
    {
        $c = $this->borrador(TipoComprobante::Factura, 'F001', $this->cliente(TipoDocumentoIdentidad::Ruc), [
            [3, '59.90'], [2, '45', '20'], [1, '11.80', '11'],
        ]);
        // Totales "manipulados" que deben ser ignorados
        $c->update(['total' => '1.00', 'igv' => '999']);

        $c = $this->svc->emitir($c)->refresh();

        $this->assertSame('152.29', $c->op_gravadas);
        $this->assertSame('90.00', $c->op_exoneradas);
        $this->assertSame('10.00', $c->op_gratuitas);
        $this->assertSame('27.41', $c->igv);
        $this->assertSame('269.70', $c->total);
    }

    // ───────────────────────── Reglas SUNAT ─────────────────────────

    public function test_reglas_de_cliente_segun_tipo_de_comprobante(): void
    {
        $this->assertRechazaCon('cliente_id', fn () => $this->svc->emitir(
            $this->borrador(TipoComprobante::Factura, 'F001', $this->cliente(TipoDocumentoIdentidad::SinDocumento))));

        // Boleta a "clientes varios": permitida hasta S/ 700, rechazada por encima
        $this->assertSame(EstadoComprobante::Aceptado, $this->svc->emitir(
            $this->borrador(TipoComprobante::Boleta, 'B001', $this->cliente(TipoDocumentoIdentidad::SinDocumento), [[1, '700']]))->refresh()->estado);
        $this->assertRechazaCon('cliente_id', fn () => $this->svc->emitir(
            $this->borrador(TipoComprobante::Boleta, 'B001', $this->cliente(TipoDocumentoIdentidad::SinDocumento), [[1, '700.01']])));

        // Boleta a cliente con RUC: permitida
        $this->assertSame(EstadoComprobante::Aceptado, $this->svc->emitir(
            $this->borrador(TipoComprobante::Boleta, 'B001', $this->cliente(TipoDocumentoIdentidad::Ruc)))->refresh()->estado);
    }

    public function test_reglas_de_fechas(): void
    {
        $cliente = $this->cliente(TipoDocumentoIdentidad::Ruc);

        $this->assertRechazaCon('fecha_emision', fn () => $this->svc->emitir(
            $this->borrador(TipoComprobante::Factura, 'F001', $cliente, extra: ['fecha_emision' => today()->addDay()])));

        $this->assertRechazaCon('fecha_emision', fn () => $this->svc->emitir(
            $this->borrador(TipoComprobante::Factura, 'F001', $cliente, extra: ['fecha_emision' => today()->subDays(4)])));

        // 3 días atrás todavía es válido
        $this->assertNotNull($this->svc->emitir(
            $this->borrador(TipoComprobante::Factura, 'F001', $cliente, extra: ['fecha_emision' => today()->subDays(3)]))->correlativo);

        // Crédito: vencimiento obligatorio y posterior a la emisión
        $this->assertRechazaCon('fecha_vencimiento', fn () => $this->svc->emitir(
            $this->borrador(TipoComprobante::Factura, 'F001', $cliente, extra: ['forma_pago' => 'Credito'])));
        $this->assertRechazaCon('fecha_vencimiento', fn () => $this->svc->emitir(
            $this->borrador(TipoComprobante::Factura, 'F001', $cliente, extra: ['forma_pago' => 'Credito', 'fecha_vencimiento' => today()])));
    }

    public function test_reglas_de_items(): void
    {
        $cliente = $this->cliente(TipoDocumentoIdentidad::Ruc);

        $this->assertRechazaCon('items', fn () => $this->svc->emitir(
            $this->borrador(TipoComprobante::Factura, 'F001', $cliente, [])));

        $this->assertRechazaCon('items', fn () => $this->svc->emitir(
            $this->borrador(TipoComprobante::Factura, 'F001', $cliente, [[1, '0']])));

        // Un regalo debe ir como gratuito: así sí se acepta
        $c = $this->svc->emitir($this->borrador(TipoComprobante::Factura, 'F001', $cliente, [[1, '50'], [1, '20', '11']]));
        $this->assertSame(EstadoComprobante::Aceptado, $c->refresh()->estado);
    }

    public function test_serie_desactivada_no_se_puede_usar(): void
    {
        $this->empresa->series()->where('serie', 'F001')->update(['activo' => false]);

        $this->assertRechazaCon('serie_id', fn () => $this->svc->emitir(
            $this->borrador(TipoComprobante::Factura, 'F001', $this->cliente(TipoDocumentoIdentidad::Ruc))));
    }

    // ───────────────────────── Notas de crédito ─────────────────────────

    public function test_nota_de_credito_completa(): void
    {
        $factura = $this->factura('236');
        $nc = $this->svc->emitir($this->notaCredito($factura, '236'))->refresh();

        $this->assertSame('FC01-00000001', $nc->numero);
        $this->assertSame(EstadoComprobante::Aceptado, $nc->estado);
        $this->assertSame('0.00', (string) $this->svc->saldoAcreditable($factura));
    }

    public function test_notas_de_credito_no_pueden_superar_el_saldo(): void
    {
        $factura = $this->factura('100');

        $this->assertRechazaCon('total', fn () => $this->svc->emitir($this->notaCredito($factura, '100.01')));

        $this->svc->emitir($this->notaCredito($factura, '60'));
        $this->assertSame('40.00', (string) $this->svc->saldoAcreditable($factura));

        $this->assertRechazaCon('total', fn () => $this->svc->emitir($this->notaCredito($factura, '40.01')));
        $this->assertSame(EstadoComprobante::Aceptado, $this->svc->emitir($this->notaCredito($factura, '40'))->refresh()->estado);
    }

    public function test_reglas_de_referencia_de_las_notas(): void
    {
        $factura = $this->factura();

        // Serie de boleta (BC01) sobre una factura
        $this->assertRechazaCon('serie_id', fn () => $this->svc->emitir($this->notaCredito($factura, '10', 'BC01')));

        // Moneda distinta
        $this->assertRechazaCon('moneda', fn () => $this->svc->emitir(
            tap($this->notaCredito($factura, '10'))->update(['moneda' => Moneda::Dolares])));

        // Cliente distinto
        $otro = $this->empresa->clientes()->create(['tipo_documento' => TipoDocumentoIdentidad::Ruc, 'numero_documento' => '20999999991', 'razon_social' => 'OTRO SAC']);
        $this->assertRechazaCon('cliente_id', fn () => $this->svc->emitir(
            tap($this->notaCredito($factura, '10'))->update(['cliente_id' => $otro->id])));

        // Referencia en borrador (no aceptada)
        $borrador = $this->borrador(TipoComprobante::Factura, 'F001', $this->cliente(TipoDocumentoIdentidad::Ruc));
        $this->assertRechazaCon('comprobante_referencia_id', fn () => $this->svc->emitir($this->notaCredito($borrador, '10')));

        // Sin sustento
        $this->assertRechazaCon('motivo_descripcion', fn () => $this->svc->emitir(
            tap($this->notaCredito($factura, '10'))->update(['motivo_descripcion' => ''])));
    }

    // ───────────────────────── PSE: errores y reintentos ─────────────────────────

    public function test_pse_caido_queda_en_error_y_luego_se_reintenta(): void
    {
        Queue::fake();
        $this->empresa->update(['pse_proveedor' => 'caido']);

        $c = $this->svc->emitir($this->borrador(TipoComprobante::Factura, 'F001', $this->cliente(TipoDocumentoIdentidad::Ruc)));
        Queue::assertPushed(EnviarComprobanteJob::class);

        try {
            (new EnviarComprobanteJob($c->id))->handle($this->svc);
            $this->fail('El job debe lanzar la excepción para que la cola lo reintente');
        } catch (RuntimeException) {
        }

        $c->refresh();
        $this->assertSame(EstadoComprobante::Error, $c->estado);
        $this->assertSame(1, $c->correlativo);
        $this->assertStringContainsString('Timeout', $c->sunat_mensaje);
        $this->assertFalse($c->logs()->first()->exitoso);

        // El PSE vuelve: el reintento conserva el MISMO número
        $this->empresa->update(['pse_proveedor' => 'disponible']);
        (new EnviarComprobanteJob($c->id))->handle($this->svc);

        $c->refresh();
        $this->assertSame(EstadoComprobante::Aceptado, $c->estado);
        $this->assertSame('F001-00000001', $c->numero);
        $this->assertSame(2, $c->intentos_envio);
    }

    public function test_el_job_no_reenvia_un_comprobante_ya_aceptado(): void
    {
        $c = $this->factura();
        $logs = $c->logs()->count();

        (new EnviarComprobanteJob($c->id))->handle($this->svc);

        $this->assertSame($logs, $c->logs()->count());
        $this->assertRechazaCon('estado', fn () => $this->svc->emitir($c));
    }

    public function test_gior_aceptado_rechazado_y_caido(): void
    {
        $this->empresa->update(['pse_proveedor' => 'gior', 'pse_token' => 'token-de-prueba']);
        config(['facturacion.gior.url_beta' => 'https://pse.test/api']);

        Http::fakeSequence('pse.test/*')
            ->push(['aceptado' => true, 'codigo' => '0', 'mensaje' => 'Aceptada', 'hash' => 'abc', 'enlace_pdf' => 'https://pse.test/f.pdf'])
            ->push(['aceptado' => false, 'codigo' => '2800', 'mensaje' => 'El dato ingresado en el tipo de documento de identidad del receptor no esta permitido.'], 200)
            ->push('Error interno', 503);

        $ok = $this->factura()->refresh();
        $this->assertSame(EstadoComprobante::Aceptado, $ok->estado);
        $this->assertSame('https://pse.test/f.pdf', $ok->enlace_pdf);

        $rechazado = $this->factura()->refresh();
        $this->assertSame(EstadoComprobante::Rechazado, $rechazado->estado);
        $this->assertSame('2800', $rechazado->sunat_codigo);

        // 503: excepción para que la cola reintente, estado Error
        try {
            $this->factura();
            $this->fail('Debió lanzar excepción');
        } catch (RuntimeException) {
        }
        $this->assertSame(EstadoComprobante::Error, Comprobante::latest('id')->first()->estado);

        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer token-de-prueba'));
    }

    public function test_rechazado_se_corrige_duplicando_como_borrador(): void
    {
        $original = $this->factura();
        $original->update(['estado' => EstadoComprobante::Rechazado]);

        $copia = $this->svc->duplicar($original);

        $this->assertSame(EstadoComprobante::Borrador, $copia->estado);
        $this->assertNull($copia->correlativo);
        $this->assertSame($original->total, $copia->total);
        $this->assertCount(1, $copia->items);
        $this->assertSame('F001-00000002', $this->svc->emitir($copia)->refresh()->numero);
        $this->assertSame(EstadoComprobante::Rechazado, $original->refresh()->estado);
    }

    // ───────────────────────── Aislamiento entre empresas ─────────────────────────

    public function test_no_se_mezclan_datos_entre_empresas(): void
    {
        $otraEmpresa = Empresa::create(['ruc' => '20555555551', 'razon_social' => 'OTRA EMPRESA SAC']);
        $otraEmpresa->series()->create(['tipo_comprobante' => TipoComprobante::Factura, 'serie' => 'F001']);
        $clienteAjeno = $otraEmpresa->clientes()->create(['tipo_documento' => TipoDocumentoIdentidad::Ruc, 'numero_documento' => '20100070970', 'razon_social' => 'X']);

        // Comprobante de mi empresa con cliente de otra empresa
        $this->assertRechazaCon('cliente_id', fn () => $this->svc->emitir(
            $this->borrador(TipoComprobante::Factura, 'F001', $clienteAjeno)));

        // Nota de crédito que referencia un comprobante de otra empresa
        $miFactura = $this->factura();
        $ajeno = $otraEmpresa->comprobantes()->create([
            'cliente_id' => $clienteAjeno->id, 'serie_id' => $otraEmpresa->series()->value('id'),
            'tipo_comprobante' => TipoComprobante::Factura, 'serie' => 'F001', 'correlativo' => 1,
            'fecha_emision' => today(), 'estado' => EstadoComprobante::Aceptado, 'total' => 1000,
        ]);
        $this->assertRechazaCon('comprobante_referencia_id', fn () => $this->svc->emitir(
            tap($this->notaCredito($miFactura, '10'))->update(['comprobante_referencia_id' => $ajeno->id])));

        // Usuario de otra empresa no puede ver ni imprimir
        $intruso = User::factory()->create();
        $intruso->empresas()->attach($otraEmpresa);
        $this->actingAs($intruso)->get(route('comprobantes.imprimir', $miFactura))->assertForbidden();
        $this->actingAs($intruso)->get('/admin/'.$this->empresa->ruc)->assertNotFound();
        $this->actingAs($intruso)->get('/admin/'.$otraEmpresa->ruc.'/comprobantes/'.$miFactura->id)->assertNotFound();
    }

    public function test_imprimir_sin_sesion_redirige_al_login(): void
    {
        $this->get(route('comprobantes.imprimir', $this->factura()))->assertRedirect('/admin/login');
    }
}
