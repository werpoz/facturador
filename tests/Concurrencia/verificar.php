<?php

/*
 * Prepara y verifica los escenarios de concurrencia. Lo usa tests/Concurrencia/correr.sh.
 * Termina con código distinto de 0 si algún resultado no es el esperado.
 */
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::getDriverName() === 'sqlite') {
    fwrite(STDERR, "Estas pruebas necesitan MySQL o MariaDB: SQLite no tiene bloqueos por fila y no detectaría nada.\n");
    exit(2);
}

$fallas = [];
$esperar = function (string $que, mixed $esperado, mixed $obtenido) use (&$fallas) {
    $ok = (string) $esperado === (string) $obtenido;
    echo ($ok ? '  ✓ ' : '  ✗ ').$que.': '.$obtenido.($ok ? '' : " (se esperaba {$esperado})")."\n";
    if (! $ok) {
        $fallas[] = $que;
    }
};
$n = (int) ($argv[2] ?? 0);

switch ($argv[1] ?? '') {
    case 'preparar-stock': // 100 unidades y venta sin stock bloqueada
        DB::table('empresas')->update(['bloquear_venta_sin_stock' => true]);
        $id = DB::table('productos')->where('codigo', 'P002')->value('id');
        DB::table('productos')->where('id', $id)->update(['stock' => 100]);
        DB::table('movimientos_stock')->where('producto_id', $id)->update(['cantidad' => 100, 'stock_resultante' => 100]);
        exit(0);

    case 'emision': // N comprobantes distintos emitidos a la vez sobre la misma serie
        $esperar('comprobantes emitidos', $n, DB::table('comprobantes')->whereNotNull('correlativo')->count());
        $esperar('números distintos', $n, DB::table('comprobantes')->distinct()->count('correlativo'));
        $esperar('número más alto (sin saltos)', $n, DB::table('comprobantes')->max('correlativo'));
        $esperar('contador de la serie', $n, DB::table('series')->where('serie', 'B001')->value('correlativo'));
        $esperar('ventas con número propio', $n, DB::table('ventas')->distinct()->count('numero'));
        $esperar('todos aceptados', $n, DB::table('comprobantes')->where('estado', 'aceptado')->count());
        break;

    case 'stock': // se intenta vender más de lo que hay
        $id = DB::table('productos')->where('codigo', 'P002')->value('id');
        $esperar('ventas realizadas (había 100)', 100, DB::table('comprobantes')->whereNotNull('correlativo')->count());
        $esperar('stock final', '0.0000', DB::table('productos')->where('id', $id)->value('stock'));
        $esperar('suma del kardex', 0, (float) DB::table('movimientos_stock')->where('producto_id', $id)->sum('cantidad'));
        $esperar('stock nunca negativo (mínimo histórico)', '0.0000', DB::table('movimientos_stock')->where('producto_id', $id)->min('stock_resultante'));
        $esperar('pagos registrados', 100, DB::table('pagos')->count());
        $esperar('números sin saltos', 100, DB::table('comprobantes')->max('correlativo'));
        break;

    case 'doble': // el mismo borrador emitido por varios procesos a la vez
        $esperar('número del comprobante', 1, DB::table('comprobantes')->where('id', $n)->value('correlativo'));
        $esperar('contador de la serie', 1, DB::table('series')->where('serie', 'B001')->value('correlativo'));
        $esperar('envíos al PSE', 1, DB::table('logs_envio')->where('comprobante_id', $n)->count());
        $esperar('descuentos de stock', 1, DB::table('movimientos_stock')->where('comprobante_id', $n)->count());
        break;

    case 'notas': // varias notas de crédito por el total del mismo comprobante, a la vez
        $notas = DB::table('comprobantes')->where('comprobante_referencia_id', $n)->whereNotNull('correlativo');
        $esperar('notas emitidas', 1, (clone $notas)->count());
        $esperar('monto acreditado = total del comprobante', DB::table('comprobantes')->where('id', $n)->value('total'), (clone $notas)->sum('total'));
        $esperar('stock devuelto una sola vez', '40.0000', DB::table('productos')->where('codigo', 'P002')->value('stock'));
        $esperar('venta anulada', 'anulada', DB::table('ventas')->where('comprobante_principal_id', $n)->value('estado'));
        break;

    default:
        fwrite(STDERR, "Escenario desconocido.\n");
        exit(2);
}

exit($fallas === [] ? 0 : 1);
