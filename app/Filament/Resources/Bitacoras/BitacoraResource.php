<?php

namespace App\Filament\Resources\Bitacoras;

use App\Enums\Permiso;
use App\Filament\Resources\Bitacoras\Pages\ListBitacora;
use App\Models\Bitacora;
use App\Support\Permisos;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Bitácora de auditoría: solo lectura. */
class BitacoraResource extends Resource
{
    protected static ?string $model = Bitacora::class;

    protected static ?string $slug = 'bitacora';

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static string|UnitEnum|null $navigationGroup = 'Configuración';

    protected static ?string $navigationLabel = 'Bitácora';

    protected static ?int $navigationSort = 8;

    protected static ?string $modelLabel = 'registro';

    protected static ?string $pluralModelLabel = 'bitácora';

    protected static ?string $tenantRelationshipName = 'bitacora';

    public static function canViewAny(): bool
    {
        return Permisos::puede(Permiso::VerBitacora);
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
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Fecha y hora')->dateTime('d/m/Y H:i:s')->sortable(),
                TextColumn::make('usuario_nombre')->label('Usuario')->searchable(),
                TextColumn::make('accion')->label('Acción')->badge()->color(fn (string $state) => match ($state) {
                    'creó' => 'success',
                    'eliminó' => 'danger',
                    'emitió' => 'primary',
                    'modificó' => 'warning',
                    default => 'gray',
                }),
                TextColumn::make('descripcion')->label('Detalle')->searchable()->wrap(),
                TextColumn::make('ip')->label('IP')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('accion')->label('Acción')->options(fn () => Bitacora::query()->distinct()->pluck('accion', 'accion')->all()),
                SelectFilter::make('usuario_nombre')->label('Usuario')->options(fn () => Bitacora::query()->distinct()->pluck('usuario_nombre', 'usuario_nombre')->all()),
                Filter::make('fecha')
                    ->schema([DatePicker::make('desde'), DatePicker::make('hasta')])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['desde'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['hasta'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))),
            ])
            ->recordActions([
                ViewAction::make()->label('Ver cambios')->visible(fn (Bitacora $record) => filled($record->cambios)),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            TextEntry::make('descripcion')->label('Acción'),
            TextEntry::make('cambios')
                ->label('Qué cambió (antes → después)')
                ->listWithLineBreaks()
                ->state(fn (Bitacora $record) => collect($record->cambios ?? [])
                    ->map(fn ($v, $campo) => $campo.':  '.self::texto($v[0] ?? null).'  →  '.self::texto($v[1] ?? null))
                    ->values()->all()),
        ]);
    }

    private static function texto(mixed $v): string
    {
        return match (true) {
            $v === null, $v === '' => '(vacío)',
            is_bool($v) => $v ? 'sí' : 'no',
            default => (string) $v,
        };
    }

    public static function getPages(): array
    {
        return ['index' => ListBitacora::route('/')];
    }
}
