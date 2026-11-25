<?php

namespace App\Filament\Resources\Clientes;

use App\Enums\TipoDocumentoIdentidad;
use App\Filament\Resources\Clientes\Pages\ManageClientes;
use App\Models\Cliente;
use App\Enums\Permiso;
use App\Support\Permisos;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

class ClienteResource extends Resource
{
    use \App\Filament\Concerns\EtiquetasEnEspanol;

    protected static ?string $model = Cliente::class;

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static string|UnitEnum|null $navigationGroup = 'Clientes';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'cliente';

    protected static ?string $recordTitleAttribute = 'razon_social';

    /** Campos reutilizables (también los usa el "crear cliente" rápido del formulario de comprobante) */
    public static function campos(): array
    {
        return [
            Select::make('tipo_documento')
                ->label('Tipo de documento')
                ->options(TipoDocumentoIdentidad::class)
                ->default(TipoDocumentoIdentidad::Dni)
                ->required()
                ->live(),
            TextInput::make('numero_documento')
                ->label('Número de documento')
                ->required()
                ->maxLength(15)
                ->length(fn (Get $get) => self::tipo($get)?->longitud())
                ->numeric(fn (Get $get) => in_array(self::tipo($get), [TipoDocumentoIdentidad::Dni, TipoDocumentoIdentidad::Ruc], true))
                ->unique(
                    table: Cliente::class,
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule, Get $get) => $rule
                        ->where('empresa_id', Filament::getTenant()?->id)
                        ->where('tipo_documento', self::tipo($get)?->value),
                ),
            // TODO: agregar aquí un botón (suffixAction) que consulte una API de RUC/DNI y autocomplete
            TextInput::make('razon_social')
                ->label(fn (Get $get) => self::tipo($get) === TipoDocumentoIdentidad::Ruc ? 'Razón social' : 'Nombre completo')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),
            TextInput::make('direccion')->label('Dirección')->maxLength(255)->columnSpanFull(),
            TextInput::make('email')->label('Correo')->email(),
            TextInput::make('telefono')->label('Teléfono')->tel(),
        ];
    }

    private static function tipo(Get $get): ?TipoDocumentoIdentidad
    {
        $v = $get('tipo_documento');

        return $v instanceof TipoDocumentoIdentidad ? $v : TipoDocumentoIdentidad::tryFrom((string) $v);
    }

    public static function canViewAny(): bool
    {
        return Permisos::puede(Permiso::VerVentas);
    }

    public static function canCreate(): bool
    {
        return Permisos::puede(Permiso::GestionarClientes);
    }

    public static function canEdit(Model $record): bool
    {
        return Permisos::puede(Permiso::GestionarClientes);
    }

    public static function canDelete(Model $record): bool
    {
        return Permisos::puede(Permiso::GestionarClientes);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components(self::campos());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('comprobantes'))
            ->columns([
                TextColumn::make('tipo_documento')->label('Tipo')->badge()->visibleFrom('md'),
                TextColumn::make('resumen')->label('Cliente')->hiddenFrom('md')->wrap()
                    ->getStateUsing(fn (Cliente $record) => $record->razon_social)
                    ->description(fn (Cliente $record) => $record->tipo_documento->getLabel().' '.$record->numero_documento),
                TextColumn::make('numero_documento')->label('Documento')->searchable()->copyable()->visibleFrom('md'),
                TextColumn::make('razon_social')->label('Nombre / Razón social')->searchable()->wrap()->visibleFrom('md'),
                TextColumn::make('email')->label('Correo')->toggleable()->visibleFrom('lg'),
                TextColumn::make('telefono')->label('Teléfono')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('comprobantes_count')->label('Comprobantes')->alignEnd()->toggleable()->visibleFrom('md'),
            ])
            ->filters([
                SelectFilter::make('tipo_documento')->label('Tipo de documento')->options(TipoDocumentoIdentidad::class),
            ])
            ->recordActions([
                EditAction::make(),
                // Un cliente con comprobantes no se puede borrar (SUNAT exige conservar el historial)
                DeleteAction::make()->hidden(fn (Cliente $record) => $record->comprobantes_count > 0),
            ])
            ->defaultSort('razon_social');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageClientes::route('/'),
        ];
    }
}
