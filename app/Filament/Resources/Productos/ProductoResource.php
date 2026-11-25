<?php

namespace App\Filament\Resources\Productos;

use App\Enums\TipoAfectacionIgv;
use App\Enums\UnidadMedida;
use App\Filament\Resources\Productos\Pages\ManageProductos;
use App\Models\Producto;
use App\Enums\Permiso;
use App\Support\Permisos;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

class ProductoResource extends Resource
{
    use \App\Filament\Concerns\EtiquetasEnEspanol;

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
            Toggle::make('activo')->default(true),
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
                IconColumn::make('activo')->boolean()->visibleFrom('md'),
            ])
            ->filters([
                TernaryFilter::make('activo'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('descripcion');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageProductos::route('/'),
        ];
    }
}
