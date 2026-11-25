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

        // Errores 5xx / de servidor: lanzamos excepción para que el Job reintente
        if ($http->serverError()) {
            throw new RuntimeException("PSE no disponible (HTTP {$http->status()})");
        }

        return $this->interpretar($http->status(), $request, $http->json() ?? ['raw' => $http->body()]);
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
        $aceptado = (bool) Arr::get($json, 'aceptado', $status >= 200 && $status < 300 && $codigo === '0');
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
