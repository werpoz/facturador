@php
    use App\Enums\EstadoComprobante;
    use App\Filament\Resources\Comprobantes\ComprobanteResource;

    $empresa = filament()->getTenant();
    $produccion = $empresa?->pse_entorno === 'produccion';
    $simulado = ($empresa?->pse_proveedor ?? 'fake') === 'fake';
    $porAtender = $empresa
        ? $empresa->comprobantes()->whereIn('estado', [EstadoComprobante::Rechazado, EstadoComprobante::Error, EstadoComprobante::Pendiente])->count()
        : 0;
@endphp
@if ($empresa)
    <div class="qf-estado">
        <a href="{{ filament()->getTenantProfileUrl() }}"
           @class(['qf-modo', 'qf-modo-produccion' => $produccion])
           title="Configurar conexión con el PSE">
            <strong>Modo: {{ $simulado ? 'SIMULADO' : ($produccion ? 'PRODUCCIÓN' : 'BETA') }}</strong>
            <span>{{ $simulado ? 'Sin envío real' : 'Conectado al PSE' }}</span>
        </a>
        @if ($porAtender)
            <a href="{{ ComprobanteResource::getUrl('index', ['tab' => 'por-atender']) }}" class="qf-campana" title="Comprobantes por atender" wire:navigate>
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="qf-campana-icono" />
                <span class="qf-campana-badge">{{ $porAtender > 99 ? '99+' : $porAtender }}</span>
            </a>
        @endif
    </div>
@endif
