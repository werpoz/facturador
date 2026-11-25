<?php

namespace App\Filament\Resources\Productos;

use App\Enums\Permiso;
use App\Enums\TipoAfectacionIgv;
use App\Enums\TipoMovimientoStock;
use App\Enums\UnidadMedida;
use App\Filament\Concerns\EtiquetasEnEspanol;
use App\Filament\Resources\Kardex\KardexResource;
use App\Filament\Resources\Productos\Pages\ManageProductos;
use App\Models\Producto;
use App\Services\Inventario\StockService;
use App\Support\Permisos;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ProductoResource extends Resource
{
    use EtiquetasEnEspanol;

    protected static ?string $model = Producto::class;

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static string|UnitEnum|null $navigationGroup = 'Productos/Servicios';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'producto / servicio';

    protected static ?string $pluralModelLabel = 'productos y servicios';

    protected static ?string $navigationLabel = 'Productos';

    protected static ?string $recordTitleAttribute = 'descripcion';

    public static function canViewAny(): bool
    {
        return Permisos::puede(Permiso::VerProductos);
    }

    public static function canCreate(): bool
    {
        return Permisos::puede(Permiso::GestionarProductos);
    }

    public static function canEdit(Model $record): bool
    {
        return Permisos::puede(Permiso::GestionarProductos);
    }

    public static function canDelete(Model $record): bool
    {
        return Permisos::puede(Permiso::GestionarProductos);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('codigo')->label('Código interno')->maxLength(30),
            Select::make('unidad_medida')
                ->label('Unidad de medida')
                ->options(UnidadMedida::class)
                ->default(UnidadMedida::Unidad)
                ->searchable()
                ->required(),
            TextInput::make('descripcion')->label('Descripción')->required()->maxLength(255)->columnSpanFull(),
            TextInput::make('precio_unitario')
                ->label('Precio de venta (incluye IGV)')
                ->numeric()
                ->minValue(0)
                ->step('0.01')
                ->prefix('S/')
                ->required(),
            Select::make('tipo_afectacion_igv')
                ->label('Afectación IGV')
                ->options(TipoAfectacionIgv::class)
                ->default(TipoAfectacionIgv::Gravado)
                ->required(),
            Toggle::make('activo')->default(true)->inline(false),

            Section::make('Inventario')
                ->columnSpanFull()
                ->columns(3)
                ->schema([
                    Toggle::make('controla_stock')
                        ->label('Controlar stock')
                        ->helperText('Actívalo para productos físicos. Déjalo apagado en servicios.')
                        ->default(true)
                        ->inline(false)
                        ->live(),
                    TextInput::make('stock')
                        ->label('Stock inicial')
                        ->helperText('Cuántas unidades tienes hoy.')
                        ->numeric()->minValue(0)->default(0)
                        ->visibleOn('create')
                        ->visible(fn ($get, string $operation) => $operation === 'create' && $get('controla_stock')),
                    TextInput::make('stock_minimo')
                        ->label('Avisarme cuando queden')
                        ->helperText('Opcional. Aparecerá en "Productos por reponer".')
                        ->numeric()->minValue(0)
                        ->visible(fn ($get) => (bool) $get('controla_stock')),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('codigo')->label('Código')->searchable()->toggleable()->visibleFrom('md'),
                TextColumn::make('resumen')->label('Producto')->hiddenFrom('md')->wrap()
                    ->getStateUsing(fn (Producto $record) => $record->descripcion)
                    ->description(fn (Producto $record) => collect([$record->codigo, $record->unidad_medida->value, $record->activo ? null : 'Inactivo'])->filter()->join(' · ')),
                TextColumn::make('descripcion')->label('Descripción')->searchable()->wrap()->visibleFrom('md'),
                TextColumn::make('unidad_medida')->label('Unidad')->formatStateUsing(fn ($state) => $state?->value)->visibleFrom('md'),
                TextColumn::make('precio_unitario')->label('Precio')->money('PEN', locale: 'es_PE')->sortable(),
                TextColumn::make('tipo_afectacion_igv')->label('IGV')->badge()->visibleFrom('md')
                    ->formatStateUsing(fn (TipoAfectacionIgv $state) => match ($state) {
                        TipoAfectacionIgv::Gravado => 'Gravado',
                        TipoAfectacionIgv::GravadoGratuito => 'Gratuito',
                        TipoAfectacionIgv::Exonerado => 'Exonerado',
                        TipoAfectacionIgv::Inafecto => 'Inafecto',
                    }),
                TextColumn::make('stock')->label('Stock')->alignEnd()->sortable()->badge()
                    ->getStateUsing(fn (Producto $record) => ! $record->controla_stock ? null
                        : ($record->estaAgotado() ? 'Agotado' : StockService::fmt($record->stock)))
                    ->color(fn (Producto $record) => match (true) {
                        $record->estaAgotado() => 'danger',
                        $record->stockBajo() => 'warning',
                        default => 'success',
                    })
                    ->tooltip(fn (Producto $record) => $record->stockBajo() && ! $record->estaAgotado() ? 'Por debajo del mínimo ('.StockService::fmt($record->stock_minimo).')' : null)
                    ->placeholder('—'),
                IconColumn::make('activo')->boolean()->visibleFrom('md'),
            ])
            ->filters([
                TernaryFilter::make('activo'),
                SelectFilter::make('stock')
                    ->label('Stock')
                    ->options(['reponer' => 'Por reponer (agotado o bajo)', 'agotado' => 'Agotado', 'con_stock' => 'Con stock'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'reponer' => $query->where('controla_stock', true)
                            ->where(fn (Builder $q) => $q->where('stock', '<=', 0)->orWhereColumn('stock', '<=', 'stock_minimo')),
                        'agotado' => $query->where('controla_stock', true)->where('stock', '<=', 0),
                        'con_stock' => $query->where('controla_stock', true)->where('stock', '>', 0),
                        default => $query,
                    }),
            ])
            ->recordActions([ActionGroup::make([
                self::accionStock(),
                Action::make('kardex')
                    ->label('Ver movimientos')
                    ->icon('heroicon-o-arrows-up-down')
                    ->visible(fn (Producto $record) => $record->controla_stock)
                    ->url(fn (Producto $record) => KardexResource::getUrl('index', ['filters' => ['producto_id' => ['value' => $record->id]]])),
                EditAction::make(),
                DeleteAction::make()->hidden(fn (Producto $record) => $record->tieneHistorial()),
            ])])
            ->defaultSort('descripcion');
    }

    /** Ingreso de mercadería, merma o ajuste al conteo físico. Siempre deja su línea en el kardex. */
    public static function accionStock(): Action
    {
        return Action::make('stock')
            ->label('Ingresar / ajustar stock')
            ->icon('heroicon-o-archive-box-arrow-down')
            ->visible(fn (Producto $record) => $record->controla_stock && Permisos::puede(Permiso::GestionarProductos))
            ->modalHeading(fn (Producto $record) => $record->descripcion)
            ->modalDescription(fn (Producto $record) => 'Stock actual: '.StockService::fmt($record->stock).' '.$record->unidad_medida->value)
            ->modalSubmitActionLabel('Guardar')
            ->schema([
                Radio::make('tipo')
                    ->label('¿Qué pasó?')
                    ->options([
                        TipoMovimientoStock::Ingreso->value => 'Llegó mercadería',
                        TipoMovimientoStock::Merma->value => 'Se perdió, dañó o venció',
                        TipoMovimientoStock::Ajuste->value => 'Conté y el stock es otro',
                    ])
                    ->default(TipoMovimientoStock::Ingreso->value)
                    ->required()
                    ->live(),
                TextInput::make('cantidad')
                    ->label(fn ($get) => match ($get('tipo')) {
                        TipoMovimientoStock::Merma->value => 'Cantidad que se perdió',
                        TipoMovimientoStock::Ajuste->value => 'Stock real (lo que contaste)',
                        default => 'Cantidad que ingresa',
                    })
                    ->numeric()->minValue(0)->required(),
                TextInput::make('motivo')
                    ->label(fn ($get) => $get('tipo') === TipoMovimientoStock::Ingreso->value ? 'Nota (proveedor, guía…)' : 'Motivo')
                    ->required(fn ($get) => $get('tipo') !== TipoMovimientoStock::Ingreso->value)
                    ->maxLength(200),
            ])
            ->action(function (Producto $record, array $data) {
                $mov = app(StockService::class)->registrarManual(
                    $record, TipoMovimientoStock::from($data['tipo']), $data['cantidad'], $data['motivo'] ?? null,
                );
                Notification::make()->success()
                    ->title('Stock actualizado: quedan '.StockService::fmt($mov->stock_resultante))
                    ->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageProductos::route('/'),
        ];
    }
}
