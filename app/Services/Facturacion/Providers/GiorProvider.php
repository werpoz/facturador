<?php

namespace App\Services\Facturacion\Providers;

use App\Enums\EstadoComprobante;
use App\Models\Comprobante;
use App\Services\Facturacion\PayloadBuilder;
use App\Services\Facturacion\PseProvider;
use App\Services\Facturacion\RespuestaPse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Integración con el PSE Gior Technology.
 *
 * ⚠️ IMPORTANTE: los nombres de campos, la URL y la forma de autenticación de este archivo
 * son PROVISIONALES. Ajusta `transformar()` y `interpretar()` según la documentación
 * oficial que te entregue Gior Technology. Es el ÚNICO archivo que debería cambiar.
 */
class GiorProvider implements PseProvider
{
    public function __construct(private PayloadBuilder $payload) {}

    public function nombre(): string
    {
        return 'gior';
    }

    public function enviar(Comprobante $comprobante): RespuestaPse
    {
        $empresa = $comprobante->empresa;

        if (blank($empresa->pse_token)) {
            throw new RuntimeException('La empresa no tiene configurado el token del PSE.');
        }

        $base = $empresa->pse_entorno === 'produccion'
            ? config('facturacion.gior.url_produccion')
            : config('facturacion.gior.url_beta');

        $request = $this->transformar($this->payload->construir($comprobante));

        $http = Http::withToken($empresa->pse_token)
            ->acceptJson()
            ->timeout(config('facturacion.gior.timeout'))
            ->post(rtrim($base, '/').config('facturacion.gior.endpoint_emitir'), $request);

        $json = $http->json();

        // "Rechazado" es definitivo: quema el número y devuelve el stock. Por eso SOLO se acepta como tal
        // una respuesta bien formada en la que el PSE dice explícitamente que SUNAT lo rechazó.
        // Todo lo demás (token vencido, límite de peticiones, caída, respuesta que no es JSON o incompleta)
        // es un problema de comunicación: se lanza excepción, queda en "Error" y se reintenta con el mismo número.
        if ($http->serverError()
            || in_array($http->status(), [401, 403, 404, 408, 429], true)
            || ! is_array($json)
            || ! array_key_exists('aceptado', $json)) {
            throw new RuntimeException("Respuesta no válida del PSE (HTTP {$http->status()}): ".mb_substr($http->body(), 0, 300));
        }

        return $this->interpretar($http->status(), $request, $json);
    }

    /** Convierte el payload neutro al formato que pide Gior. TODO: ajustar con su documentación. */
    protected function transformar(array $datos): array
    {
        return $datos;
    }

    /** Lee la respuesta de Gior. TODO: ajustar las claves con su documentación. */
    protected function interpretar(int $status, array $request, array $json): RespuestaPse
    {
        $codigo = (string) Arr::get($json, 'sunat_codigo', Arr::get($json, 'codigo', ''));
        $aceptado = filter_var(Arr::get($json, 'aceptado'), FILTER_VALIDATE_BOOLEAN);
        $tieneObservaciones = ! empty(Arr::get($json, 'observaciones'));

        $estado = match (true) {
            $aceptado && $tieneObservaciones => EstadoComprobante::Observado,
            $aceptado => EstadoComprobante::Aceptado,
            default => EstadoComprobante::Rechazado,
        };

        return new RespuestaPse(
            estado: $estado,
            codigo: $codigo ?: null,
            mensaje: Arr::get($json, 'sunat_descripcion', Arr::get($json, 'mensaje')),
            hash: Arr::get($json, 'hash'),
            enlacePdf: Arr::get($json, 'enlace_pdf'),
            enlaceXml: Arr::get($json, 'enlace_xml'),
            enlaceCdr: Arr::get($json, 'enlace_cdr'),
            httpStatus: $status,
            request: $request,
            response: $json,
        );
    }
}
