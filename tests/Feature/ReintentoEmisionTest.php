<?php

namespace Tests\Feature;

use App\Enums\EstadoComprobante;
use App\Enums\TipoComprobante;
use App\Models\Comprobante;
use App\Models\Empresa;
use App\Services\Facturacion\EmisionService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\DeadlockException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

/**
 * Regresión de la auditoría: si la base de datos reintenta la emisión por un bloqueo (deadlock),
 * el segundo intento NO debe reutilizar el número que el primero asignó y luego deshizo.
 * Antes, eso dejaba la serie trabada para siempre.
 *
 * Usa DatabaseMigrations (no RefreshDatabase) porque el reintento automático de Laravel solo
 * ocurre en la transacción más externa, y RefreshDatabase envuelve cada prueba en una.
 */
class ReintentoEmisionTest extends TestCase
{
    use DatabaseMigrations;

    public function test_un_reintento_por_bloqueo_no_deja_la_serie_trabada(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = Empresa::first();
        $svc = app(EmisionService::class);

        $borrador = function () use ($empresa) {
            $c = $empresa->comprobantes()->create([
                'cliente_id' => $empresa->clientes()->where('numero_documento', '45678912')->value('id'),
                'serie_id' => $empresa->series()->where('serie', 'B001')->value('id'),
                'tipo_comprobante' => TipoComprobante::Boleta, 'serie' => 'B001',
                'fecha_emision' => today(), 'estado' => EstadoComprobante::Borrador,
            ]);
            $c->items()->create([
                'descripcion' => 'Producto', 'cantidad' => 1, 'precio_unitario' => 10, 'tipo_afectacion_igv' => '10',
                'valor_unitario' => 0, 'valor_venta' => 0, 'igv' => 0, 'total' => 0,
            ]);

            return $c;
        };

        // Falla UNA vez, justo cuando el comprobante ya recibió su número dentro de la transacción
        $falla = true;
        Comprobante::updated(function (Comprobante $c) use (&$falla) {
            if ($falla && $c->correlativo !== null) {
                $falla = false;
                throw new DeadlockException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock');
            }
        });

        $primero = $svc->emitir($borrador())->refresh();

        $this->assertFalse($falla, 'El bloqueo simulado sí ocurrió');
        $this->assertSame(1, $primero->correlativo);
        $this->assertSame(EstadoComprobante::Aceptado, $primero->estado);
        $this->assertSame(1, (int) $empresa->series()->where('serie', 'B001')->value('correlativo'), 'Comprobante y serie tienen el mismo número');

        // La serie sigue funcionando: la siguiente emisión toma el número siguiente
        $this->assertSame('B001-00000002', $svc->emitir($borrador())->refresh()->numero);
    }
}
