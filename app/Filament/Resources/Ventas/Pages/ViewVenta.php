<?php

namespace App\Filament\Resources\Ventas\Pages;

use App\Enums\EstadoComprobante;
use App\Enums\EstadoVenta;
use App\Enums\Permiso;
use App\Filament\Resources\Comprobantes\ComprobanteResource;
use App\Filament\Resources\Ventas\VentaResource;
use App\Models\Venta;
use App\Support\Permisos;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewVenta extends ViewRecord
{
    protected static string $resource = VentaResource::class;

    public function getTitle(): string
    {
        return "Venta {$this->record->codigo}";
    }

    /**
     * Acciones en lenguaje del negocio. Por dentro generan la nota de crédito
     * con el motivo SUNAT correcto: el usuario no necesita conocer los códigos.
     */
    protected function getHeaderActions(): array
    {
        $puedeNotas = Permisos::puede(Permiso::EmitirNotas);
        $vigente = fn (Venta $record) => $puedeNotas && $record->estado->estaVigente() && $record->principal;
        $nota = fn (Venta $record, string $motivo) => ComprobanteResource::getUrl('create', [
            'referencia' => $record->comprobante_principal_id,
            'motivo' => $motivo,
        ]);

        return [
            Action::make('devolver')
                ->label('Devolver productos')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('gray')
                ->visible($vigente)
                ->url(fn (Venta $record) => $nota($record, '07')),
            Action::make('descuento')
                ->label('Aplicar descuento')
                ->icon(Heroicon::OutlinedReceiptPercent)
                ->color('gray')
                ->visible($vigente)
                ->url(fn (Venta $record) => $nota($record, '04')),
            Action::make('anular')
                ->label('Anular venta')
                ->icon(Heroicon::OutlinedNoSymbol)
                ->color('danger')
                // Solo si aún no tiene devoluciones: una anulación es por el total
                ->visible(fn (Venta $record) => $puedeNotas && $record->estado === EstadoVenta::Confirmada && $record->principal)
                ->url(fn (Venta $record) => $nota($record, '01')),
            Action::make('corregir')
                ->label('Corregir comprobante rechazado')
                ->icon(Heroicon::OutlinedWrench)
                ->color('warning')
                ->visible(fn (Venta $record) => $record->estado === EstadoVenta::Rechazada && $record->principal)
                ->url(fn (Venta $record) => ComprobanteResource::getUrl('view', ['record' => $record->comprobante_principal_id])),
            Action::make('continuar')
                ->label('Continuar borrador')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->visible(fn (Venta $record) => $record->principal?->estado === EstadoComprobante::Borrador)
                ->url(fn (Venta $record) => ComprobanteResource::getUrl('edit', ['record' => $record->comprobante_principal_id])),
            Action::make('imprimir')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->visible(fn (Venta $record) => $record->principal?->correlativo !== null)
                ->url(fn (Venta $record) => route('comprobantes.imprimir', $record->comprobante_principal_id))
                ->openUrlInNewTab(),
        ];
    }
}
