<?php

namespace App\Services\Facturacion\Providers;

use App\Enums\EstadoComprobante;
use App\Models\Comprobante;
use App\Services\Facturacion\PayloadBuilder;
use App\Services\Facturacion\PseProvider;
use App\Services\Facturacion\RespuestaPse;

/**
 * Proveedor simulado para desarrollo y demos: acepta todo sin llamar a nadie.
 * Úsalo hasta tener las credenciales beta de Gior Technology.
 */
class FakeProvider implements PseProvider
{
    public function __construct(private PayloadBuilder $payload) {}

    public function nombre(): string
    {
        return 'fake';
    }

    public function enviar(Comprobante $comprobante): RespuestaPse
    {
        $request = $this->payload->construir($comprobante);

        return new RespuestaPse(
            estado: EstadoComprobante::Aceptado,
            codigo: '0',
            mensaje: "La {$comprobante->tipo_comprobante->getLabel()} {$comprobante->numero} ha sido aceptada (SIMULADO).",
            hash: base64_encode(sha1(json_encode($request), true)),
            httpStatus: 200,
            request: $request,
            response: ['simulado' => true],
        );
    }
}
