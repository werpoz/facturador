<?php

/*
 * Prueba de concurrencia REAL (no corre con `php artisan test`; necesita MySQL/MariaDB).
 * Emite N boletas seguidas en un proceso; se lanza varias veces a la vez:
 *
 *   php artisan migrate:fresh --seed        (con la base MySQL configurada y QUEUE_CONNECTION=sync)
 *   for i in 1 2 3 4 5 6 7 8; do php tests/Concurrencia/emitir_en_paralelo.php 25 & done; wait
 *
 * Resultado esperado: cada proceso imprime "ok=25 err=0" y en la base
 *   select count(*), count(distinct correlativo), max(correlativo) from comprobantes;   → 200, 200, 200
 */
use App\Enums\EstadoComprobante;
use App\Enums\TipoComprobante;
use App\Models\Empresa;
use App\Services\Facturacion\EmisionService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$e = Empresa::first();
$svc = app(EmisionService::class);
$ok = 0;
$err = 0;
for ($i = 0; $i < (int) $argv[1]; $i++) {
    try {
        $c = $e->comprobantes()->create(['cliente_id' => $e->clientes()->where('tipo_documento', '1')->value('id'),
            'serie_id' => $e->series()->where('serie', 'B001')->value('id'), 'tipo_comprobante' => TipoComprobante::Boleta,
            'serie' => 'B001', 'fecha_emision' => today(), 'estado' => EstadoComprobante::Borrador]);
        $c->items()->create(['descripcion' => 'P', 'cantidad' => 1, 'precio_unitario' => 10, 'tipo_afectacion_igv' => '10',
            'valor_unitario' => 0, 'valor_venta' => 0, 'igv' => 0, 'total' => 0]);
        $svc->emitir($c);
        $ok++;
    } catch (Throwable $t) {
        $err++;
        fwrite(STDERR, get_class($t).': '.substr($t->getMessage(), 0, 700)."\n   en ".collect($t->getTrace())->filter(fn ($f) => str_contains($f['file'] ?? '', '/app/'))->map(fn ($f) => basename($f['file']).':'.$f['line'])->take(4)->implode(' < ')."\n");
    }
}
echo "ok=$ok err=$err\n";
