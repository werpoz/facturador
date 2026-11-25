<?php

use App\Http\Controllers\ImprimirComprobanteController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::get('/comprobantes/{comprobante}/imprimir', ImprimirComprobanteController::class)
    ->name('comprobantes.imprimir');
