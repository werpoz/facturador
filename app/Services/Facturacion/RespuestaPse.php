<?php

namespace App\Services\Facturacion;

use App\Enums\EstadoComprobante;

/** Respuesta normalizada de cualquier PSE, para que el resto del sistema no dependa del proveedor. */
final class RespuestaPse
{
    public function __construct(
        public readonly EstadoComprobante $estado,
        public readonly ?string $codigo = null,
        public readonly ?string $mensaje = null,
        public readonly ?string $hash = null,
        public readonly ?string $enlacePdf = null,
        public readonly ?string $enlaceXml = null,
        public readonly ?string $enlaceCdr = null,
        public readonly ?int $httpStatus = null,
        public readonly array $request = [],
        public readonly array $response = [],
    ) {}

    public function exitoso(): bool
    {
        return in_array($this->estado, [EstadoComprobante::Aceptado, EstadoComprobante::Observado], true);
    }
}
