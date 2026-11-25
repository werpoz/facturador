<?php

namespace App\Filament\Resources\Comprobantes\Pages;

use App\Enums\EstadoComprobante;
use App\Enums\Permiso;
use App\Support\Permisos;
use App\Filament\Resources\Comprobantes\ComprobanteResource;
use App\Filament\Resources\Comprobantes\Tables\ComprobantesTable;
use App\Models\Comprobante;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewComprobante extends ViewRecord
{
    protected static string $resource = ComprobanteResource::class;

    public function getTitle(): string
    {
        return "{$this->record->tipo_comprobante->getLabel()} {$this->record->numero}";
    }

    protected function getHeaderActions(): array
    {
        return [
            ComprobantesTable::accionEmitir()->size('md')->after(fn () => $this->refreshFormData([])),
            ComprobantesTable::accionDuplicar(),
            Action::make('actualizar')
                ->label('Actualizar estado')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->visible(fn (Comprobante $record) => $record->estado === EstadoComprobante::Pendiente)
                ->action(fn () => $this->redirect(static::getUrl(['record' => $this->record]))),
            Action::make('imprimir')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->url(fn (Comprobante $record) => route('comprobantes.imprimir', $record))
                ->openUrlInNewTab()
                ->visible(fn (Comprobante $record) => $record->correlativo !== null),
            Action::make('nota_credito')
                ->label('Nota de crédito')
                ->icon(Heroicon::OutlinedReceiptRefund)
                ->color('gray')
                ->url(fn (Comprobante $record) => ComprobanteResource::getUrl('create', ['referencia' => $record->id]))
                ->visible(fn (Comprobante $record) => Permisos::puede(Permiso::EmitirNotas)
                    && ! $record->tipo_comprobante->esNota()
                    && in_array($record->estado, [EstadoComprobante::Aceptado, EstadoComprobante::Observado], true)),
            EditAction::make(),
        ];
    }
}
