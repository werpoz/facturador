<?php

namespace App\Filament\Resources\Comprobantes\Pages;

use App\Enums\EstadoComprobante;
use App\Enums\MotivoNotaCredito;
use App\Enums\TipoComprobante;
use App\Filament\Resources\Comprobantes\ComprobanteResource;
use App\Filament\Resources\Comprobantes\Pages\Concerns\PreparaDatosComprobante;
use App\Filament\Resources\Comprobantes\Schemas\ComprobanteForm;
use App\Models\Comprobante;
use App\Services\Facturacion\EmisionService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Icons\Heroicon;

class CreateComprobante extends CreateRecord
{
    use PreparaDatosComprobante;

    protected static string $resource = ComprobanteResource::class;

    protected static bool $canCreateAnother = false;

    public bool $emitirAlGuardar = false;

    public function getTitle(): string
    {
        return 'Nuevo comprobante';
    }

    /**
     * El formulario se llena UNA sola vez. Llenarlo dos veces (defaults y luego datos)
     * hace que el Repeater mezcle el ítem vacío por defecto con los ítems copiados.
     */
    protected function fillForm(): void
    {
        $this->callHook('beforeFill');

        $empresaId = Filament::getTenant()->id;
        $refId = request()->query('referencia');
        $ref = $refId ? Comprobante::with('items')->where('empresa_id', $empresaId)->find($refId) : null;

        if ($ref) {
            // /comprobantes/create?referencia=ID → nota de crédito precargada
            $motivo = MotivoNotaCredito::tryFrom((string) request()->query('motivo'));

            $this->form->fill(ComprobanteForm::datosDesdeReferencia($ref, TipoComprobante::NotaCredito) + [
                'fecha_emision' => now()->toDateString(),
                'motivo_codigo' => $motivo?->value,
                'motivo_descripcion' => $motivo?->sustentoSugerido(),
            ]);
        } else {
            $this->form->fill();

            // /comprobantes/create?tipo=01 → abre con ese tipo (accesos rápidos F, B, NC)
            if ($tipo = TipoComprobante::tryFrom((string) request()->query('tipo'))) {
                $this->data['tipo_comprobante'] = $tipo;
                $this->data['serie_id'] = ComprobanteForm::serieDefecto($tipo);
            }
        }

        $this->callHook('afterFill');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = $this->prepararDatos($data);
        $data['estado'] = EstadoComprobante::Borrador;
        $data['user_id'] = auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        app(EmisionService::class)->recalcular($this->record);

        if ($this->emitirAlGuardar) {
            $this->emitirRegistro();
        }
    }

    public function guardarYEmitir(): void
    {
        $this->emitirAlGuardar = true;
        $this->create();
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('guardarYEmitir')
                ->label('Guardar y emitir')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('success')
                ->action('guardarYEmitir')
                ->keyBindings(['mod+enter']),
            $this->getCreateFormAction()->label('Guardar borrador')->color('gray'),
            $this->getCancelFormAction(),
        ];
    }

    protected function getCreatedNotification(): ?\Filament\Notifications\Notification
    {
        return $this->emitirAlGuardar ? null : parent::getCreatedNotification();
    }

    protected function getRedirectUrl(): string
    {
        return $this->urlTrasGuardar();
    }
}
