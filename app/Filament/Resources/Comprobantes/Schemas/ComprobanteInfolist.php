<?php

namespace App\Filament\Resources\Comprobantes\Schemas;

use App\Filament\Resources\Ventas\VentaResource;
use App\Models\Comprobante;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ComprobanteInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $money = fn (Comprobante $record) => $record->moneda->value;

        return $schema
            ->columns(3)
            ->components([
                Section::make('Comprobante')
                    ->columnSpan(2)
                    ->columns(['default' => 2, 'md' => 4])
                    ->schema([
                        TextEntry::make('numero')->label('Número')->weight('bold')->copyable(),
                        TextEntry::make('estado')->badge(),
                        TextEntry::make('fecha_emision')->label('Emisión')->date('d/m/Y'),
                        TextEntry::make('venta.codigo')->label('Venta')->color('primary')
                            ->url(fn (Comprobante $record) => $record->venta_id
                                ? VentaResource::getUrl('view', ['record' => $record->venta_id]) : null)
                            ->placeholder('-'),
                        TextEntry::make('cliente.razon_social')->label('Cliente')->columnSpan(2),
                        TextEntry::make('cliente.numero_documento')
                            ->label(fn (Comprobante $record) => $record->cliente->tipo_documento->getLabel()),
                        TextEntry::make('forma_pago')->label('Forma de pago')
                            ->formatStateUsing(fn (string $state, Comprobante $record) => $state === 'Credito'
                                ? 'Crédito'.($record->fecha_vencimiento ? ' · vence '.$record->fecha_vencimiento->format('d/m/Y') : '')
                                : 'Contado'),
                        TextEntry::make('referencia.numero')->label('Documento que modifica')
                            ->visible(fn (Comprobante $record) => $record->referencia !== null)
                            ->columnSpan(2),
                        TextEntry::make('motivo_descripcion')->label('Motivo')
                            ->visible(fn (Comprobante $record) => $record->referencia !== null)
                            ->columnSpan(2),
                        TextEntry::make('observaciones')->columnSpanFull()
                            ->visible(fn (Comprobante $record) => filled($record->observaciones)),
                    ]),

                Section::make('Totales')
                    ->columnSpan(1)
                    ->extraAttributes(['class' => 'qf-totales'])
                    ->schema([
                        TextEntry::make('op_gravadas')->label('Gravadas')->money($money, locale: 'es_PE')->inlineLabel(),
                        TextEntry::make('op_exoneradas')->label('Exoneradas')->money($money, locale: 'es_PE')->inlineLabel()
                            ->visible(fn (Comprobante $record) => $record->op_exoneradas > 0),
                        TextEntry::make('op_inafectas')->label('Inafectas')->money($money, locale: 'es_PE')->inlineLabel()
                            ->visible(fn (Comprobante $record) => $record->op_inafectas > 0),
                        TextEntry::make('op_gratuitas')->label('Gratuitas')->money($money, locale: 'es_PE')->inlineLabel()
                            ->visible(fn (Comprobante $record) => $record->op_gratuitas > 0),
                        TextEntry::make('igv')->label('IGV')->money($money, locale: 'es_PE')->inlineLabel(),
                        TextEntry::make('total')->label('TOTAL')->money($money, locale: 'es_PE')->inlineLabel()
                            ->size('lg')->weight('bold')->color('primary'),
                    ]),

                Section::make('Detalle')
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('items')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Descripción'),
                                TableColumn::make('Unidad'),
                                TableColumn::make('Cantidad'),
                                TableColumn::make('P. unitario'),
                                TableColumn::make('IGV'),
                                TableColumn::make('Total'),
                            ])
                            ->schema([
                                TextEntry::make('descripcion'),
                                TextEntry::make('unidad_medida')->formatStateUsing(fn ($state) => $state->value),
                                TextEntry::make('cantidad')->numeric(decimalPlaces: 2),
                                TextEntry::make('precio_unitario')->numeric(decimalPlaces: 2),
                                TextEntry::make('igv')->numeric(decimalPlaces: 2),
                                TextEntry::make('total')->numeric(decimalPlaces: 2)->weight('bold'),
                            ]),
                    ]),

                Section::make('Respuesta SUNAT / PSE')
                    ->columnSpanFull()
                    ->columns(3)
                    ->collapsible()
                    ->visible(fn (Comprobante $record) => $record->correlativo !== null)
                    ->schema([
                        TextEntry::make('sunat_codigo')->label('Código')->placeholder('-'),
                        TextEntry::make('enviado_at')->label('Enviado')->dateTime('d/m/Y H:i:s')->placeholder('-'),
                        TextEntry::make('intentos_envio')->label('Intentos'),
                        TextEntry::make('sunat_mensaje')->label('Mensaje')->placeholder('-')->columnSpanFull(),
                        TextEntry::make('hash')->label('Código hash')->placeholder('-')->copyable()->columnSpanFull(),
                    ]),
            ]);
    }
}
