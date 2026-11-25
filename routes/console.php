<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Vigilancia de envíos a SUNAT: reintenta lo atascado y alerta antes de que venza un plazo.
// Requiere el cron del servidor:  * * * * * cd /ruta/del/proyecto && php artisan schedule:run >> /dev/null 2>&1
Schedule::command('facturacion:vigilar')->everyTenMinutes()->withoutOverlapping();
