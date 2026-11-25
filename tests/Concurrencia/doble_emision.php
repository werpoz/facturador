<?php

/*
 * Prueba de concurrencia REAL del "doble clic" (necesita MySQL/MariaDB; no corre con `php artisan test`).
 * Varios procesos intentan emitir EL MISMO borrador a la vez; con "nota" intentan además
 * emitir cada uno una nota de crédito por el total del mismo comprobante.
 *
 *   php artisan migrate:fresh --seed        (base MySQL y QUEUE_CONNECTION=sync)
 *   php tests/Concurrencia/doble_emision.php preparar            → imprime el id del borrador
 *   for i in 1 2 3 4 5 6 7 8; do php tests/Concurrencia/doble_emision.php emitir ID & done; wait
 *
 * Resultado esperado: un solo "emitido"; los demás "rechazado". Un solo número usado y un solo envío.
 */
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Enums\EstadoComprobante;
use App\Enums\TipoComprobante;
use App\Models\Comprobante;
use App\Models\Empresa;
use App\Services\Facturacion\EmisionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

$empresa = Empresa::first();
$svc = app(EmisionService::class);
$mouse = $empresa->productos()->where('codigo', 'P002')->first();

$borrador = function (TipoComprobante $tipo, string $serie, array $extra = []) use ($empresa, $mouse) {
    $c = $empresa->comprobantes()->create($extra + [
        'cliente_id' => $empresa->clientes()->where('numero_documento', '45678912')->value('id'),
        'serie_id' => $empresa->series()->where('serie', $serie)->value('id'),
        'tipo_comprobante' => $tipo, 'serie' => $serie, 'fecha_emision' => today(), 'estado' => EstadoComprobante::Borrador,
    ]);
    $c->items()->create(['producto_id' => $mouse->id, 'descripcion' => $mouse->descripcion, 'cantidad' => 2,
        'precio_unitario' => $mouse->precio_unitario, 'tipo_afectacion_igv' => '10',
        'valor_unitario' => 0, 'valor_venta' => 0, 'igv' => 0, 'total' => 0]);

    return $c;
};

$modo = $argv[1] ?? '';

if ($modo === 'preparar') {
    echo $borrador(TipoComprobante::Boleta, 'B001')->id, "\n";
    exit;
}

try {
    if ($modo === 'emitir') {
        $svc->emitir(Comprobante::findOrFail((int) $argv[2]));
    } elseif ($modo === 'nota') { // cada proceso crea SU nota por el total del mismo comprobante
        $ref = Comprobante::findOrFail((int) $argv[2]);
        $svc->emitir($borrador(TipoComprobante::NotaCredito, 'BC01', [
            'comprobante_referencia_id' => $ref->id, 'motivo_codigo' => '06', 'motivo_descripcion' => 'Devolución total',
        ]));
    }
    echo "emitido\n";
} catch (ValidationException $e) {
    echo "rechazado\n";
} catch (Throwable $t) {
    echo 'ERROR '.get_class($t).': '.substr($t->getMessage(), 0, 200)."\n";
}
