<?php

namespace App\Filament\Resources\Comprobantes;

use App\Filament\Resources\Comprobantes\Pages\CreateComprobante;
use App\Filament\Resources\Comprobantes\Pages\EditComprobante;
use App\Filament\Resources\Comprobantes\Pages\ListComprobantes;
use App\Filament\Resources\Comprobantes\Pages\ViewComprobante;
use App\Filament\Resources\Comprobantes\Schemas\ComprobanteForm;
use App\Filament\Resources\Comprobantes\Schemas\ComprobanteInfolist;
use App\Filament\Resources\Comprobantes\Tables\ComprobantesTable;
use App\Enums\EstadoComprobante;
use App\Enums\Permiso;
use App\Support\Permisos;
use App\Models\Comprobante;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ComprobanteResource extends Resource
{
    protected static ?string $model = Comprobante::class;

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static string|UnitEnum|null $navigationGroup = 'Ventas';

    protected static ?string $navigationLabel = 'Listado de comprobantes';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'comprobante';

    public static function form(Schema $schema): Schema
    {
        return ComprobanteForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ComprobanteInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ComprobantesTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        return Permisos::puede(Permiso::VerVentas);
    }

    public static function canCreate(): bool
    {
        return Permisos::puede(Permiso::EmitirVentas);
    }

    /** Solo los borradores se pueden editar o borrar: lo emitido es inmutable. */
    public static function canEdit(Model $record): bool
    {
        return $record->estado->esEditable() && self::puedeTrabajar($record);
    }

    public static function canDelete(Model $record): bool
    {
        return $record->estado->esEditable() && self::puedeTrabajar($record);
    }

    /** Las notas de crédito/débito requieren un permiso aparte (anulaciones y devoluciones). */
    public static function puedeTrabajar(Model $record): bool
    {
        return Permisos::puede($record->tipo_comprobante->esNota() ? Permiso::EmitirNotas : Permiso::EmitirVentas);
    }

    /** El ítem del menú solo se marca en listado/ver/editar ("Nuevo comprobante" tiene su propio ítem). */
    public static function getNavigationItemActiveRoutePattern(): string|array
    {
        $base = static::getRouteBaseName();

        return request()->query('tab') === 'por-atender' ? 'ninguna' : [$base.'.index', $base.'.view', $base.'.edit'];
    }

    /** Cantidad de comprobantes rechazados, con error o en cola (se muestra en "Por atender"). */
    public static function contarPorAtender(): ?string
    {
        $n = Comprobante::query()
            ->where('empresa_id', Filament::getTenant()?->id)
            ->whereIn('estado', [EstadoComprobante::Rechazado, EstadoComprobante::Error, EstadoComprobante::Pendiente])
            ->count();

        return $n ? (string) $n : null;
    }


    public static function getPages(): array
    {
        return [
            'index' => ListComprobantes::route('/'),
            'create' => CreateComprobante::route('/create'),
            'view' => ViewComprobante::route('/{record}'),
            'edit' => EditComprobante::route('/{record}/edit'),
        ];
    }
}
