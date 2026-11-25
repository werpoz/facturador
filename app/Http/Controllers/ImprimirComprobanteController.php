<?php

namespace App\Http\Controllers;

use App\Enums\EstadoComprobante;
use App\Models\Comprobante;
use App\Support\NumeroALetras;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

class ImprimirComprobanteController extends Controller
{
    public function __invoke(Request $request, Comprobante $comprobante)
    {
        $user = $request->user();

        if (! $user) {
            return redirect(Filament::getPanel('admin')->getLoginUrl());
        }

        abort_unless($user->canAccessTenant($comprobante->empresa), 403);
        abort_if($comprobante->correlativo === null, 404, 'El comprobante aún no ha sido emitido.');

        $comprobante->load(['empresa', 'cliente', 'items', 'referencia']);

        // Solo un comprobante ACEPTADO por SUNAT (y no simulado) tiene valor tributario.
        // Cualquier otro se imprime con una marca bien visible y SIN código QR ni hash,
        // para que nadie lo entregue a un cliente como si fuera válido.
        $advertencia = self::advertencia($comprobante);

        return view('comprobantes.imprimir', [
            'c' => $comprobante,
            'cli' => $comprobante->datosCliente(),
            'advertencia' => $advertencia,
            'qrSvg' => $advertencia ? null : self::qr($comprobante),
            'letras' => NumeroALetras::convertir($comprobante->total, $comprobante->moneda->value),
            'formato' => $request->query('formato') === 'ticket' ? 'ticket' : 'a4',
        ]);
    }

    public static function advertencia(Comprobante $c): ?string
    {
        return match (true) {
            $c->simulado || ($c->empresa->usaSimulador() && ! in_array($c->estado, [EstadoComprobante::Rechazado, EstadoComprobante::Anulado], true)) => 'DOCUMENTO DE PRUEBA · SIN VALOR TRIBUTARIO',
            in_array($c->estado, [EstadoComprobante::Aceptado, EstadoComprobante::Observado], true) => null,
            $c->estado === EstadoComprobante::Rechazado => 'RECHAZADO POR SUNAT · SIN VALOR TRIBUTARIO',
            $c->estado === EstadoComprobante::Anulado => 'COMPROBANTE ANULADO',
            default => 'PENDIENTE DE ENVÍO A SUNAT · AÚN SIN VALOR TRIBUTARIO',
        };
    }

    /** QR según SUNAT: RUC|TIPO|SERIE|NUMERO|IGV|TOTAL|FECHA|TIPO DOC|NUM DOC|HASH| */
    private static function qr(Comprobante $c): string
    {
        $contenido = implode('|', [
            $c->empresa->ruc,
            $c->tipo_comprobante->value,
            $c->serie,
            $c->correlativo,
            $c->igv,
            $c->total,
            $c->fecha_emision->format('Y-m-d'),
            $c->datosCliente()['tipo']->value,
            $c->datosCliente()['numero'],
            $c->hash,
        ]).'|';

        $svg = (new Writer(new ImageRenderer(new RendererStyle(220, 0), new SvgImageBackEnd)))->writeString($contenido);

        return preg_replace('/^<\?xml[^>]*\?>\s*/', '', $svg);
    }
}
