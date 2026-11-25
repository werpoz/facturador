<?php

namespace App\Filament\Resources\Ventas\Pages;

use App\Filament\Resources\Comprobantes\ComprobanteResource;
use App\Filament\Resources\Ventas\VentaResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListVentas extends ListRecords
{
    protected static string $resource = VentaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('nueva')
                ->label('Nueva venta')
                ->icon('heroicon-o-plus-circle')
                ->visible(fn () => ComprobanteResource::canCreate())
                ->url(ComprobanteResource::getUrl('create')),
        ];
    }
}
