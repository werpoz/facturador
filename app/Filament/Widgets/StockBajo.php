<?php

namespace App\Filament\Widgets;

use App\Enums\Permiso;
use App\Filament\Resources\Productos\ProductoResource;
use App\Models\Producto;
use App\Services\Inventario\StockService;
use App\Support\Permisos;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/** Productos agotados o por debajo de su mínimo: lo que hay que reponer. */
class StockBajo extends TableWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Productos por reponer';

    public static function porReponer(): Builder
    {
        return Producto::query()
            ->where('empresa_id', Filament::getTenant()?->id)
            ->where('activo', true)
            ->where('controla_stock', true)
            ->where(fn (Builder $q) => $q->where('stock', '<=', 0)->orWhereColumn('stock', '<=', 'stock_minimo'));
    }

    public static function canView(): bool
    {
        return Permisos::puede(Permiso::VerProductos) && static::porReponer()->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => static::porReponer()->orderBy('stock')->limit(8))
            ->paginated(false)
            ->columns([
                TextColumn::make('descripcion')->label('Producto')->wrap()
                    ->description(fn (Producto $record) => $record->codigo),
                TextColumn::make('stock')->label('Quedan')->alignEnd()->weight('bold')
                    ->formatStateUsing(fn ($state) => StockService::fmt($state))
                    ->color(fn (Producto $record) => $record->estaAgotado() ? 'danger' : 'warning'),
                TextColumn::make('stock_minimo')->label('Mínimo')->alignEnd()
                    ->formatStateUsing(fn ($state) => $state === null ? '-' : StockService::fmt($state))
                    ->visibleFrom('md'),
                TextColumn::make('estado_stock')->label('')->badge()
                    ->getStateUsing(fn (Producto $record) => $record->estaAgotado() ? 'Agotado' : 'Stock bajo')
                    ->color(fn (Producto $record) => $record->estaAgotado() ? 'danger' : 'warning'),
            ])
            ->recordUrl(fn () => ProductoResource::getUrl('index', ['filters' => ['stock' => ['value' => 'reponer']]]));
    }
}
