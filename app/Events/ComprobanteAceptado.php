<?php

namespace App\Events;

use App\Models\Comprobante;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * SUNAT (vía el PSE) aceptó un comprobante. Es el ÚNICO momento en que deben
 * producirse los efectos del negocio: mover stock, registrar en caja, etc.
 * Así un comprobante rechazado nunca descuadra el inventario.
 *
 * Para reaccionar: crea un Listener (php artisan make:listener) que reciba este evento.
 */
class ComprobanteAceptado
{
    use Dispatchable;

    public function __construct(public Comprobante $comprobante) {}
}
