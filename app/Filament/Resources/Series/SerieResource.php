<?php

namespace App\Filament\Resources\Series;

use App\Enums\Permiso;
use App\Enums\TipoComprobante;
use App\Filament\Concerns\EtiquetasEnEspanol;
use App\Filament\Resources\Series\Pages\ManageSeries;
use App\Models\Serie;
use App\Support\Permisos;
use BackedEnum;
use Closure;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

class SerieResource extends Resource
{
    use EtiquetasEnEspanol;

    protected static ?string $model = Serie::class;

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static ?string $navigationLabel = 'Sucursales y series';

    protected static string|UnitEnum|null $navigationGroup = 'Configuración';

    protected static ?string $modelLabel = 'serie';

    protected static ?string $recordTitleAttribute = 'serie';

    public static function canViewAny(): bool
    {
        return Permisos::puede(Permiso::GestionarSeries);
    }

    public static function canCreate(): bool
    {
        return Permisos::puede(Permiso::GestionarSeries);
    }

    public static function canEdit(Model $record): bool
    {
        return Permisos::puede(Permiso::GestionarSeries);
    }

    public static function canDelete(Model $record): bool
    {
        return Permisos::puede(Permiso::GestionarSeries);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('tipo_comprobante')
                ->label('Tipo de comprobante')
                ->options(TipoComprobante::class)
                ->required()
                ->live()
                ->disabledOn('edit'),
            TextInput::make('serie')
                ->required()
                ->length(4)
                ->placeholder('F001')
                ->helperText('4 caracteres. Factura: F001 · Boleta: B001 · Nota de crédito de factura: FC01')
                ->dehydrateStateUsing(fn (?string $state) => strtoupper((string) $state))
                ->disabledOn('edit')
                ->rules([
                    fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get) {
                        $tipo = $get('tipo_comprobante');
                        $tipo = $tipo instanceof TipoComprobante ? $tipo : TipoComprobante::tryFrom((string) $tipo);
                        if ($tipo && ! in_array(strtoupper($value[0] ?? ''), $tipo->prefijosSerie(), true)) {
                            $fail('La serie debe empezar con: '.implode(' o ', $tipo->prefijosSerie()));
                        }
                        if (! preg_match('/^[A-Z][A-Z0-9]{3}$/i', $value)) {
                            $fail('Formato inválido. Ejemplo: F001');
                        }
                    },
                ])
                ->unique(
                    table: Serie::class,
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule) => $rule->where('empresa_id', Filament::getTenant()?->id),
                ),
            TextInput::make('correlativo')
                ->label('Último número emitido')
                ->helperText('Déjalo en 0 si es una serie nueva. Si migras de otro sistema, pon el último número usado.')
                ->numeric()
                ->minValue(0)
                ->default(0)
                ->disabledOn('edit'),
            Toggle::make('activo')->default(true)->inline(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('serie')->weight('bold')
                    ->description(fn (Serie $record) => $record->tipo_comprobante->getLabel()),
                TextColumn::make('correlativo')->label('Último número'),
                IconColumn::make('activo')->boolean()->label('Activa'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('serie');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSeries::route('/'),
        ];
    }
}
