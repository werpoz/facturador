@php
    $s = $c->moneda->simbolo();
    $f = fn ($v) => number_format((float) $v, 2);
    $titulo = mb_strtoupper($c->tipo_comprobante->getLabel()).' ELECTRÓNICA';
@endphp
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $c->numero }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; color: #111; margin: 0; background: #e5e7eb; font-size: 12px; }
        .hoja { background: #fff; margin: 16px auto; padding: 28px; width: 210mm; min-height: 297mm; }
        .ticket .hoja { width: 80mm; min-height: auto; padding: 8px; font-size: 11px; }
        .cabecera { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; }
        .ticket .cabecera { flex-direction: column; align-items: center; text-align: center; }
        .emisor h1 { font-size: 16px; margin: 0 0 4px; }
        .caja { border: 2px solid #111; border-radius: 6px; padding: 10px 16px; text-align: center; min-width: 230px; }
        .caja div { font-weight: bold; margin: 3px 0; }
        .ticket .caja { border: 0; border-top: 1px dashed #111; border-bottom: 1px dashed #111; border-radius: 0; width: 100%; min-width: 0; }
        .datos { margin: 16px 0; border: 1px solid #ccc; border-radius: 6px; padding: 10px; display: grid; grid-template-columns: 140px 1fr; gap: 3px 8px; }
        .ticket .datos { border: 0; padding: 4px 0; grid-template-columns: 70px 1fr; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #111; color: #fff; padding: 6px; text-align: left; font-size: 11px; }
        td { padding: 5px 6px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        .ticket th { background: none; color: #111; border-bottom: 1px dashed #111; padding: 3px 2px; }
        .ticket td { padding: 3px 2px; border-bottom: 0; }
        .num { text-align: right; white-space: nowrap; }
        .pie { display: flex; justify-content: space-between; gap: 16px; margin-top: 16px; }
        .ticket .pie { flex-direction: column-reverse; align-items: center; }
        .totales { width: 260px; }
        .ticket .totales { width: 100%; }
        .totales td { border: 0; padding: 2px 6px; }
        .totales .total td { font-size: 14px; font-weight: bold; border-top: 2px solid #111; }
        .letras { margin-top: 10px; font-weight: bold; }
        .legal { font-size: 10px; color: #444; margin-top: 12px; }
        .ticket .legal, .ticket .letras { text-align: center; }
        .acciones { text-align: center; padding: 12px; }
        .acciones a, .acciones button { margin: 0 4px; padding: 8px 14px; border-radius: 6px; border: 1px solid #111; background: #fff; cursor: pointer; text-decoration: none; color: #111; font-size: 13px; }
        .acciones button { background: #111; color: #fff; }
        #qr svg { width: 110px; height: 110px; display: block; }
        .advertencia { border: 3px solid #c00; color: #c00; font-weight: bold; text-align: center; padding: 8px; margin-bottom: 14px; font-size: 14px; letter-spacing: .5px; }
        .ticket .advertencia { font-size: 11px; padding: 5px; }
        .hoja { position: relative; overflow: hidden; }
        .marca { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; pointer-events: none; z-index: 5; }
        .marca span { transform: rotate(-32deg); font-size: 64px; font-weight: 900; color: rgba(204, 0, 0, .13); white-space: nowrap; text-align: center; line-height: 1.1; }
        .ticket .marca span { font-size: 26px; }
        @media print {
            body { background: #fff; }
            .acciones { display: none; }
            .hoja { margin: 0; box-shadow: none; }
            @page { size: {{ $formato === 'ticket' ? '80mm auto' : 'A4' }}; margin: {{ $formato === 'ticket' ? '0' : '10mm' }}; }
            .a4 .hoja { width: auto; min-height: auto; padding: 0; }
        }
    </style>
</head>
<body class="{{ $formato }}">
<div class="acciones">
    <button onclick="window.print()">Imprimir</button>
    <a href="?formato=a4">Formato A4</a>
    <a href="?formato=ticket">Ticket 80 mm</a>
</div>

<div class="hoja">
    @if ($advertencia)
        <div class="marca"><span>SIN VALOR<br>TRIBUTARIO</span></div>
        <div class="advertencia">{{ $advertencia }}</div>
    @endif
    <div class="cabecera">
        <div class="emisor">
            @if ($c->empresa->logo)
                <img src="{{ Storage::disk('public')->url($c->empresa->logo) }}" alt="" style="max-height:70px;max-width:200px">
            @endif
            <h1>{{ $c->empresa->nombre_comercial ?: $c->empresa->razon_social }}</h1>
            @if ($c->empresa->nombre_comercial)<div>{{ $c->empresa->razon_social }}</div>@endif
            <div>{{ $c->empresa->direccion }}</div>
            <div>{{ collect([$c->empresa->distrito, $c->empresa->provincia, $c->empresa->departamento])->filter()->join(' - ') }}</div>
            @if ($c->empresa->telefono)<div>Tel: {{ $c->empresa->telefono }}</div>@endif
        </div>
        <div class="caja">
            <div>RUC {{ $c->empresa->ruc }}</div>
            <div>{{ $titulo }}</div>
            <div>{{ $c->numero }}</div>
        </div>
    </div>

    <div class="datos">
        <strong>{{ $c->cliente->tipo_documento->getLabel() }}:</strong> <span>{{ $c->cliente->numero_documento }}</span>
        <strong>Cliente:</strong> <span>{{ $c->cliente->razon_social }}</span>
        @if ($c->cliente->direccion)<strong>Dirección:</strong> <span>{{ $c->cliente->direccion }}</span>@endif
        <strong>Fecha emisión:</strong> <span>{{ $c->fecha_emision->format('d/m/Y') }}</span>
        <strong>Moneda:</strong> <span>{{ $c->moneda->getLabel() }}</span>
        <strong>Forma de pago:</strong> <span>{{ $c->forma_pago === 'Credito' ? 'Crédito' : 'Contado' }}</span>
        @if ($c->fecha_vencimiento)<strong>Vencimiento:</strong> <span>{{ $c->fecha_vencimiento->format('d/m/Y') }}</span>@endif
        @if ($c->referencia)
            <strong>Doc. que modifica:</strong> <span>{{ $c->referencia->numero }}</span>
            <strong>Motivo:</strong> <span>{{ $c->motivo_descripcion }}</span>
        @endif
    </div>

    <table>
        <thead>
        <tr>
            <th class="num">Cant.</th>
            @if ($formato === 'a4')<th>Unid.</th>@endif
            <th>Descripción</th>
            <th class="num">P. unit.</th>
            <th class="num">Importe</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($c->items as $i)
            <tr>
                <td class="num">{{ rtrim(rtrim(number_format((float) $i->cantidad, 4, '.', ''), '0'), '.') }}</td>
                @if ($formato === 'a4')<td>{{ $i->unidad_medida->value }}</td>@endif
                <td>{{ $i->descripcion }}@if ($i->tipo_afectacion_igv->esGratuito()) <em>(gratuito)</em>@endif</td>
                <td class="num">{{ $f($i->precio_unitario) }}</td>
                <td class="num">{{ $i->tipo_afectacion_igv->esGratuito() ? $f(0) : $f($i->total) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div class="pie">
        <div>
            @if ($qrSvg)
                <div id="qr">{!! $qrSvg !!}</div>
                @if ($c->hash)<div class="legal">Hash: {{ $c->hash }}</div>@endif
            @endif
        </div>
        <table class="totales">
            <tr><td>Op. gravadas</td><td class="num">{{ $s }} {{ $f($c->op_gravadas) }}</td></tr>
            @if ($c->op_exoneradas > 0)<tr><td>Op. exoneradas</td><td class="num">{{ $s }} {{ $f($c->op_exoneradas) }}</td></tr>@endif
            @if ($c->op_inafectas > 0)<tr><td>Op. inafectas</td><td class="num">{{ $s }} {{ $f($c->op_inafectas) }}</td></tr>@endif
            @if ($c->op_gratuitas > 0)<tr><td>Op. gratuitas</td><td class="num">{{ $s }} {{ $f($c->op_gratuitas) }}</td></tr>@endif
            <tr><td>IGV</td><td class="num">{{ $s }} {{ $f($c->igv) }}</td></tr>
            <tr class="total"><td>TOTAL</td><td class="num">{{ $s }} {{ $f($c->total) }}</td></tr>
        </table>
    </div>

    <div class="letras">{{ $letras }}</div>
    @if ($c->observaciones)<div class="legal">Obs.: {{ $c->observaciones }}</div>@endif
    @if ($advertencia)
        <div class="legal"><strong>{{ $advertencia }}.</strong> Este documento no es un comprobante de pago.</div>
    @else
        <div class="legal">
            Representación impresa de la {{ mb_strtolower($titulo) }}.
            Puede ser consultada en el portal de SUNAT.
        </div>
    @endif
</div>
</body>
</html>
