<?php

namespace App\Filament\Resources\Comprobantes\Tables;

use App\Enums\EstadoComprobante;
use App\Enums\Permiso;
use App\Support\Permisos;
use App\Enums\TipoComprobante;
use App\Filament\Resources\Comprobantes\ComprobanteResource;
use App\Models\Comprobante;
use App\Services\Facturacion\EmisionService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class ComprobantesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('cliente'))
            ->columns([
                TextColumn::make('fecha_emision')->label('Fecha')->date('d/m/Y')->sortable(),
                TextColumn::make('tipo_comprobante')->label('Tipo')->badge()->color('gray'),
                TextColumn::make('numero')
                    ->label('Número')
                    ->weight('bold')
                    ->searchable(query: fn (Builder $query, string $search) => self::buscarNumero($query, $search)),
                TextColumn::make('cliente.razon_social')
                    ->label('Cliente')
                    ->description(fn (Comprobante $r) => $r->cliente->numero_documento)
                    ->searchable(['razon_social', 'numero_documento'])
                    ->wrap(),
                TextColumn::make('total')
                    ->money(fn (Comprobante $r) => $r->moneda->value, locale: 'es_PE')
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('estado')->badge()->tooltip(fn (Comprobante $r) => $r->sunat_mensaje),
                TextColumn::make('plazo')
                    ->label('Plazo SUNAT')
                    ->badge()
                    ->getStateUsing(fn (Comprobante $r) => ! $r->estaPorAtender() ? null : match (true) {
                        $r->diasParaVencer() < 0 => 'Vencido',
                        $r->diasParaVencer() === 0 => 'Vence hoy',
                        default => 'Quedan '.$r->diasParaVencer().' día'.($r->diasParaVencer() === 1 ? '' : 's'),
                    })
                    ->color(fn (Comprobante $r) => $r->diasParaVencer() <= 0 ? 'danger' : ($r->diasParaVencer() === 1 ? 'warning' : 'gray'))
                    ->placeholder(''),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('estado')->options(EstadoComprobante::class)->multiple(),
                SelectFilter::make('tipo_comprobante')->label('Tipo')->options(TipoComprobante::class),
                Filter::make('fecha')
                    ->schema([
                        DatePicker::make('desde'),
                        DatePicker::make('hasta'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['desde'] ?? null, fn ($q, $d) => $q->whereDate('fecha_emision', '>=', $d))
                        ->when($data['hasta'] ?? null, fn ($q, $d) => $q->whereDate('fecha_emision', '<=', $d))),
            ])
            ->recordActions([
                self::accionEmitir(),
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    self::accionDuplicar(),
                    Action::make('imprimir')
                        ->label('Imprimir')
                        ->icon(Heroicon::OutlinedPrinter)
                        ->url(fn (Comprobante $r) => route('comprobantes.imprimir', $r))
                        ->openUrlInNewTab()
                        ->visible(fn (Comprobante $r) => $r->correlativo !== null),
                    Action::make('pdf')->label('PDF (PSE)')->icon(Heroicon::OutlinedDocumentArrowDown)
                        ->url(fn (Comprobante $r) => $r->enlace_pdf)->openUrlInNewTab()
                        ->visible(fn (Comprobante $r) => filled($r->enlace_pdf)),
                    Action::make('xml')->label('XML')->icon(Heroicon::OutlinedCodeBracket)
                        ->url(fn (Comprobante $r) => $r->enlace_xml)->openUrlInNewTab()
                        ->visible(fn (Comprobante $r) => filled($r->enlace_xml)),
                    Action::make('cdr')->label('CDR')->icon(Heroicon::OutlinedShieldCheck)
                        ->url(fn (Comprobante $r) => $r->enlace_cdr)->openUrlInNewTab()
                        ->visible(fn (Comprobante $r) => filled($r->enlace_cdr)),
                    Action::make('nota_credito')
                        ->label('Emitir nota de crédito')
                        ->icon(Heroicon::OutlinedReceiptRefund)
                        ->url(fn (Comprobante $r) => ComprobanteResource::getUrl('create', ['referencia' => $r->id]))
                        ->visible(fn (Comprobante $r) => Permisos::puede(Permiso::EmitirNotas)
                            && ! $r->tipo_comprobante->esNota()
                            && in_array($r->estado, [EstadoComprobante::Aceptado, EstadoComprobante::Observado], true)),
                ]),
            ]);
    }

    /**
     * "F001-123" o "F001-00000123" → esa serie y ese número exactos;
     * "F001" → toda la serie; "123" → ese número en cualquier serie.
     */
    public static function buscarNumero(Builder $query, string $search): Builder
    {
        $search = strtoupper(trim($search));

        if (preg_match('/^([A-Z][A-Z0-9]{3})\s*-\s*0*(\d+)$/', $search, $m)) {
            return $query->where('serie', $m[1])->where('correlativo', (int) $m[2]);
        }
        if (preg_match('/^0*(\d+)$/', $search, $m)) {
            return $query->where('correlativo', (int) $m[1]);
        }

        return $query->where('serie', 'like', $search.'%');
    }

    public static function accionDuplicar(): Action
    {
        return Action::make('duplicar')
            ->label('Corregir y volver a emitir')
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription('Se creará un borrador nuevo con los mismos datos para que lo corrijas y lo emitas con otro número. El comprobante rechazado se conserva como historial.')
            ->visible(fn (Comprobante $r) => $r->estado === EstadoComprobante::Rechazado && ComprobanteResource::puedeTrabajar($r))
            ->action(function (Comprobante $record) {
                $copia = app(EmisionService::class)->duplicar($record, auth()->id());
                Notification::make()->success()->title('Borrador creado')->send();

                return redirect(ComprobanteResource::getUrl('edit', ['record' => $copia]));
            });
    }

    public static function accionEmitir(): Action
    {
        return Action::make('emitir')
            ->label(fn (Comprobante $r) => $r->estado === EstadoComprobante::Error ? 'Reintentar' : 'Emitir')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('success')
            ->button()
            ->size('sm')
            ->requiresConfirmation()
            ->modalHeading(fn (Comprobante $r) => "¿Emitir {$r->tipo_comprobante->getLabel()}?")
            ->modalDescription('Se asignará el número correlativo y se enviará a SUNAT a través del PSE. Esta acción no se puede deshacer.')
            ->visible(fn (Comprobante $r) => $r->estado->puedeEnviarse() && ComprobanteResource::puedeTrabajar($r))
            ->action(function (Comprobante $record) {
                try {
                    $c = app(EmisionService::class)->emitir($record);
                    Notification::make()->success()
                        ->title("{$c->numero} enviado a la cola de SUNAT")
                        ->send();
                } catch (ValidationException $e) {
                    Notification::make()->danger()
                        ->title('No se pudo emitir')
                        ->body(implode("\n", collect($e->errors())->flatten()->all()))
                        ->persistent()
                        ->send();
                }
            });
    }
}
