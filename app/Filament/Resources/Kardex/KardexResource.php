<?php

namespace App\Filament\Resources\Kardex;

use App\Enums\Permiso;
use App\Enums\TipoMovimientoStock;
use App\Filament\Concerns\EtiquetasEnEspanol;
use App\Filament\Resources\Comprobantes\ComprobanteResource;
use App\Filament\Resources\Kardex\Pages\ListKardex;
use App\Models\MovimientoStock;
use App\Models\Producto;
use App\Services\Inventario\StockService;
use App\Support\Permisos;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Kardex: historial de entradas y salidas de mercadería. Solo lectura. */
class KardexResource extends Resource
{
    use EtiquetasEnEspanol;

    protected static ?string $model = MovimientoStock::class;

    protected static ?string $slug = 'kardex';

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static string|UnitEnum|null $navigationGroup = 'Productos/Servicios';

    protected static ?string $navigationLabel = 'Kardex (movimientos)';

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = 'movimiento';

    protected static ?string $pluralModelLabel = 'kardex';

    protected static ?string $tenantRelationshipName = 'movimientosStock';

    public static function canViewAny(): bool
    {
        return Permisos::puede(Permiso::VerProductos);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $cantidad = fn (MovimientoStock $record) => ((float) $record->cantidad > 0 ? '+' : '').StockService::fmt($record->cantidad);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['producto', 'comprobante', 'user']))
            ->columns([
                TextColumn::make('created_at')->label('Fecha')->dateTime('d/m/Y H:i')->sortable()->visibleFrom('md'),
                TextColumn::make('resumen')->label('Movimiento')->hiddenFrom('md')->wrap()
                    ->getStateUsing(fn (MovimientoStock $record) => $record->producto->descripcion)
                    ->description(fn (MovimientoStock $record) => $record->tipo->getLabel().' · '.$record->created_at->format('d/m H:i')),
                TextColumn::make('producto.descripcion')->label('Producto')->searchable()->wrap()->visibleFrom('md'),
                TextColumn::make('tipo')->badge()->visibleFrom('md'),
                TextColumn::make('cantidad')->alignEnd()->weight('bold')
                    ->formatStateUsing(fn (MovimientoStock $record) => $cantidad($record))
                    ->color(fn (MovimientoStock $record) => (float) $record->cantidad > 0 ? 'success' : 'danger'),
                TextColumn::make('stock_resultante')->label('Quedó')->alignEnd()
                    ->formatStateUsing(fn ($state) => StockService::fmt($state)),
                TextColumn::make('comprobante.numero')->label('Comprobante')->color('primary')->placeholder('-')
                    ->url(fn (MovimientoStock $record) => $record->comprobante_id
                        ? ComprobanteResource::getUrl('view', ['record' => $record->comprobante_id]) : null)
                    ->visibleFrom('lg'),
                TextColumn::make('motivo')->placeholder('-')->wrap()->visibleFrom('lg'),
                TextColumn::make('user.name')->label('Usuario')->placeholder('Sistema')->visibleFrom('lg'),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('producto_id')->label('Producto')->searchable()
                    ->options(fn () => Producto::query()->where('empresa_id', Filament::getTenant()?->id)
                        ->where('controla_stock', true)->orderBy('descripcion')->pluck('descripcion', 'id')),
                SelectFilter::make('tipo')->options(TipoMovimientoStock::class),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListKardex::route('/')];
    }
}
