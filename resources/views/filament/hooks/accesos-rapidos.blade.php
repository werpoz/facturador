@php
    use App\Filament\Resources\Comprobantes\ComprobanteResource;
    use App\Filament\Resources\Productos\ProductoResource;

    $accesos = [
        ['POS', 'Punto de venta', \App\Filament\Pages\PuntoDeVenta::getUrl(), 'heroicon-o-shopping-cart'],
        ['F', 'Factura', ComprobanteResource::getUrl('create', ['tipo' => '01']), 'heroicon-o-document-text'],
        ['B', 'Boleta', ComprobanteResource::getUrl('create', ['tipo' => '03']), 'heroicon-o-receipt-percent'],
        ['NC', 'Nota de crédito', ComprobanteResource::getUrl('create', ['tipo' => '07']), 'heroicon-o-receipt-refund'],
        ['PRO', 'Productos', ProductoResource::getUrl('index'), 'heroicon-o-squares-2x2'],
    ];

    $puede = fn (\App\Enums\Permiso $p) => \App\Support\Permisos::puede($p);

    $accesos = array_values(array_filter($accesos, fn ($a) => match ($a[0]) {
        'POS', 'B' => $puede(\App\Enums\Permiso::EmitirVentas),
        'F' => $puede(\App\Enums\Permiso::EmitirVentas) && filament()->getTenant()?->permiteFacturas(),
        'NC' => $puede(\App\Enums\Permiso::EmitirNotas),
        'PRO' => $puede(\App\Enums\Permiso::VerProductos),
    }));
@endphp
<nav class="qf-accesos" aria-label="Accesos rápidos">
    @foreach ($accesos as [$corto, $titulo, $url, $icono])
        <a href="{{ $url }}" class="qf-acceso" title="{{ $titulo }}" wire:navigate>
            <x-filament::icon :icon="$icono" class="qf-acceso-icono" />
            <span>{{ $corto }}</span>
        </a>
    @endforeach
</nav>
