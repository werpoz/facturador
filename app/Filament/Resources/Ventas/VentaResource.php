<?php

namespace App\Filament\Resources\Ventas;

use App\Enums\EstadoVenta;
use App\Enums\Permiso;
use App\Support\Permisos;
use App\Filament\Resources\Comprobantes\ComprobanteResource;
use App\Filament\Resources\Ventas\Pages\ListVentas;
use App\Filament\Resources\Ventas\Pages\ViewVenta;
use App\Models\Comprobante;
use App\Models\Venta;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Vista COMERCIAL: una fila por venta, con su saldo real después de devoluciones.
 * Las ventas no se crean ni editan aquí: nacen al crear una factura/boleta
 * y su estado se calcula a partir de sus comprobantes.
 */
class VentaResource extends Resource
{
    use \App\Filament\Concerns\EtiquetasEnEspanol;

    protected static ?string $model = Venta::class;

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static string|UnitEnum|null $navigationGroup = 'Ventas';

    protected static ?string $navigationLabel = 'Ventas';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'venta';

    public static function canViewAny(): bool
    {
        return Permisos::puede(Permiso::VerVentas);
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
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['cliente', 'principal']))
            ->columns([
                TextColumn::make('fecha')->date('d/m/Y')->sortable()->visibleFrom('md'),
                TextColumn::make('resumen')
                    ->label('Venta')
                    ->hiddenFrom('md')
                    ->getStateUsing(fn (Venta $record) => $record->codigo)
                    ->weight('bold')
                    ->description(fn (Venta $record) => str($record->cliente->razon_social)->limit(18).' · '.$record->fecha->format('d/m')),
                TextColumn::make('codigo')->label('Venta')->weight('bold')->visibleFrom('md')
                    ->searchable(query: fn (Builder $query, string $search) => $query
                        ->where('numero', (int) preg_replace('/\D/', '', $search))),
                TextColumn::make('cliente.razon_social')->label('Cliente')
                    ->description(fn (Venta $record) => $record->cliente->numero_documento)
                    ->searchable(['razon_social', 'numero_documento'])->wrap()->visibleFrom('md'),
                TextColumn::make('principal.numero')->label('Comprobante')
                    ->description(fn (Venta $record) => $record->principal?->tipo_comprobante->getLabel())->visibleFrom('lg'),
                TextColumn::make('total')->money(fn (Venta $record) => $record->moneda->value, locale: 'es_PE')->alignEnd()->visibleFrom('lg'),
                TextColumn::make('acreditado')->label('Devuelto')
                    ->money(fn (Venta $record) => $record->moneda->value, locale: 'es_PE')->alignEnd()
                    ->color('danger')
                    ->placeholder('-')
                    ->visibleFrom('lg')
                    ->getStateUsing(fn (Venta $record) => $record->acreditado > 0 ? $record->acreditado : null),
                TextColumn::make('neto')->label('Neto')->weight('bold')
                    ->money(fn (Venta $record) => $record->moneda->value, locale: 'es_PE')->alignEnd()->sortable(),
                TextColumn::make('estado')->badge(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('estado')->options(EstadoVenta::class)->multiple(),
                Filter::make('fecha')
                    ->schema([DatePicker::make('desde'), DatePicker::make('hasta')])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['desde'] ?? null, fn ($q, $d) => $q->whereDate('fecha', '>=', $d))
                        ->when($data['hasta'] ?? null, fn ($q, $d) => $q->whereDate('fecha', '<=', $d))),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        $money = fn (Venta $record) => $record->moneda->value;

        return $schema->columns(3)->components([
            Section::make('Venta')
                ->columnSpan(2)
                ->columns(3)
                ->schema([
                    TextEntry::make('codigo')->label('Número interno')->weight('bold'),
                    TextEntry::make('fecha')->date('d/m/Y'),
                    TextEntry::make('estado')->badge(),
                    TextEntry::make('cliente.razon_social')->label('Cliente')->columnSpan(2),
                    TextEntry::make('cliente.numero_documento')->label('Documento'),
                ]),

            Section::make('Montos')
                ->columnSpan(1)
                ->extraAttributes(['class' => 'qf-totales'])
                ->schema([
                    TextEntry::make('total')->label('Vendido')->money($money, locale: 'es_PE')->inlineLabel(),
                    TextEntry::make('acreditado')->label('Devuelto')->money($money, locale: 'es_PE')->inlineLabel()
                        ->color('danger')->visible(fn (Venta $record) => $record->acreditado > 0),
                    TextEntry::make('debitado')->label('Cargos')->money($money, locale: 'es_PE')->inlineLabel()
                        ->visible(fn (Venta $record) => $record->debitado > 0),
                    TextEntry::make('neto')->label('NETO')->money($money, locale: 'es_PE')->inlineLabel()
                        ->size('lg')->weight('bold')->color('primary'),
                    TextEntry::make('pagos')->label('Cobrado con')->inlineLabel()
                        ->visible(fn (Venta $record) => $record->pagos->isNotEmpty())
                        ->state(fn (Venta $record) => $record->pagos
                            ->map(fn ($p) => $p->medio->getLabel().($p->vuelto > 0 ? ' (vuelto S/ '.number_format((float) $p->vuelto, 2).')' : ''))
                            ->join(', ')),
                ]),

            Section::make('Comprobantes de esta venta')
                ->description('El comprobante original y todas sus notas de crédito o débito, en orden.')
                ->columnSpanFull()
                ->schema([
                    RepeatableEntry::make('comprobantes')
                        ->hiddenLabel()
                        ->table([
                            TableColumn::make('Fecha'),
                            TableColumn::make('Tipo'),
                            TableColumn::make('Número'),
                            TableColumn::make('Motivo'),
                            TableColumn::make('Total'),
                            TableColumn::make('Estado SUNAT'),
                        ])
                        ->schema([
                            TextEntry::make('fecha_emision')->date('d/m/Y'),
                            TextEntry::make('tipo_comprobante'),
                            TextEntry::make('numero')->weight('bold')->color('primary')
                                ->url(fn (Comprobante $record) => ComprobanteResource::getUrl('view', ['record' => $record])),
                            TextEntry::make('motivo_descripcion')->placeholder('-'),
                            TextEntry::make('total')->money(fn (Comprobante $record) => $record->moneda->value, locale: 'es_PE'),
                            TextEntry::make('estado')->badge(),
                        ]),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVentas::route('/'),
            'view' => ViewVenta::route('/{record}'),
        ];
    }
}
