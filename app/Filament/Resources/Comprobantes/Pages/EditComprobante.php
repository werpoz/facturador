<?php

namespace App\Filament\Resources\Comprobantes\Pages;

use App\Filament\Resources\Comprobantes\ComprobanteResource;
use App\Filament\Resources\Comprobantes\Pages\Concerns\PreparaDatosComprobante;
use App\Services\Facturacion\EmisionService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditComprobante extends EditRecord
{
    use PreparaDatosComprobante;

    protected static string $resource = ComprobanteResource::class;

    public bool $emitirAlGuardar = false;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->prepararDatos($data);
    }

    protected function afterSave(): void
    {
        app(EmisionService::class)->recalcular($this->record);

        if ($this->emitirAlGuardar) {
            $this->emitirRegistro();
        }
    }

    public function guardarYEmitir(): void
    {
        $this->emitirAlGuardar = true;
        $this->save();
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('guardarYEmitir')
                ->label('Guardar y emitir')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('success')
                ->action('guardarYEmitir'),
            $this->getSaveFormAction()->label('Guardar borrador')->color('gray'),
            $this->getCancelFormAction(),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): ?string
    {
        return $this->urlTrasGuardar();
    }
}
