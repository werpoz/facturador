<?php

namespace App\Observers;

use App\Models\Comprobante;
use App\Services\Ventas\VentaService;

class ComprobanteObserver
{
    public function __construct(private VentaService $ventas) {}

    public function saved(Comprobante $comprobante): void
    {
        $this->ventas->sincronizar($comprobante);
    }

    public function deleted(Comprobante $comprobante): void
    {
        $this->ventas->alEliminar($comprobante);
    }
}
