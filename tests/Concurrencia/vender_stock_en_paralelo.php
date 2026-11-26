<?php

/*
 * Prueba de concurrencia REAL del inventario (necesita MySQL/MariaDB; no corre con `php artisan test`).
 * Con la venta sin stock bloqueada y 100 unidades, varios procesos intentan vender a la vez:
 *
 *   php artisan migrate:fresh --seed        (base MySQL y QUEUE_CONNECTION=sync)
 *   for i in 1 2 3 4 5 6 7 8; do php tests/Concurrencia/vender_stock_en_paralelo.php 25 & done; wait
 *
 * Resultado esperado: entre todos venden EXACTAMENTE 100; el resto es rechazado con
 * "No hay stock suficiente"; el stock termina en 0 (nunca negativo) y el kardex cuadra.
 */
use App\Enums\MedioPago;
use App\Enums\TipoComprobante;
use App\Models\Empresa;
use App\Services\Ventas\VentaRapidaService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$empresa = Empresa::first();
$producto = $empresa->productos()->where('codigo', 'P002')->first();
$cliente = $empresa->clientes()->where('tipo_documento', '0')->value('id');
$pos = app(VentaRapidaService::class);
$vendidos = 0;
$sinStock = 0;
$errores = 0;

for ($i = 0; $i < (int) ($argv[1] ?? 10); $i++) {
    try {
        $pos->registrar($empresa, TipoComprobante::Boleta, $cliente, [['producto_id' => $producto->id, 'cantidad' => 1]], MedioPago::Yape);
        $vendidos++;
    } catch (ValidationException $e) {
        $sinStock++;
    } catch (Throwable $t) {
        $errores++;
        fwrite(STDERR, get_class($t).': '.substr($t->getMessage(), 0, 300)."\n");
    }
}
echo "vendidos=$vendidos sin_stock=$sinStock errores=$errores\n";
