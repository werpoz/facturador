<?php

namespace App\Filament\Resources\Comprobantes\Pages;

use App\Enums\EstadoComprobante;
use App\Filament\Resources\Comprobantes\ComprobanteResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListComprobantes extends ListRecords
{
    protected static string $resource = ComprobanteResource::class;

    public function getTitle(): string
    {
        return $this->activeTab === 'por-atender' ? 'Comprobantes por atender' : 'Listado de comprobantes';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nuevo')->icon('heroicon-o-plus-circle'),
        ];
    }

    public function getTabs(): array
    {
        $porAtender = [EstadoComprobante::Rechazado, EstadoComprobante::Error, EstadoComprobante::Pendiente];

        return [
            'todos' => Tab::make('Todos'),
            'aceptados' => Tab::make('Aceptados')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('estado', [EstadoComprobante::Aceptado, EstadoComprobante::Observado])),
            'por-atender' => Tab::make('Por atender')
                ->badge(fn () => ComprobanteResource::contarPorAtender())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('estado', $porAtender)),
            'borradores' => Tab::make('Borradores')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('estado', EstadoComprobante::Borrador)),
            'anulados' => Tab::make('Anulados')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('estado', EstadoComprobante::Anulado)),
        ];
    }
}
