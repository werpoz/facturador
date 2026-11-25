<?php

namespace App\Services\Facturacion;

use App\Models\Comprobante;

interface PseProvider
{
    public function nombre(): string;

    /**
     * Envía el comprobante al PSE. Debe lanzar una excepción si hay un problema de
     * comunicación (timeout, 5xx) para que el Job lo reintente. Si el PSE/SUNAT
     * responde con un rechazo, debe devolver una RespuestaPse con estado Rechazado.
     */
    public function enviar(Comprobante $comprobante): RespuestaPse;
}
