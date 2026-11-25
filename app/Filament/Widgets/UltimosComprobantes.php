<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Comprobantes\ComprobanteResource;
use App\Models\Comprobante;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class UltimosComprobantes extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Últimos comprobantes';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Comprobante::query()
                ->with('cliente')
                ->where('empresa_id', Filament::getTenant()?->id)
                ->latest('id')
                ->limit(8))
            ->paginated(false)
            ->columns([
                TextColumn::make('fecha_emision')->label('Fecha')->date('d/m/Y'),
                TextColumn::make('numero')->label('Número')->weight('bold'),
                TextColumn::make('cliente.razon_social')->label('Cliente')->limit(40),
                TextColumn::make('total')->money(fn (Comprobante $r) => $r->moneda->value, locale: 'es_PE')->alignEnd(),
                TextColumn::make('estado')->badge(),
            ])
            ->recordUrl(fn (Comprobante $r) => ComprobanteResource::getUrl('view', ['record' => $r]))
            ->headerActions([
                Action::make('nuevo')
                    ->label('Nuevo comprobante')
                    ->icon('heroicon-o-plus')
                    ->url(ComprobanteResource::getUrl('create')),
            ]);
    }
}
