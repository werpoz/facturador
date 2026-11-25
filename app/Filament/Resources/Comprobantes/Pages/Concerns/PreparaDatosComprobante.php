<?php

namespace App\Filament\Resources\Comprobantes\Pages\Concerns;

use App\Enums\Permiso;
use App\Services\Facturacion\EmisionService;
use App\Support\Permisos;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

trait PreparaDatosComprobante
{
    protected function prepararDatos(array $data): array
    {
        // La serie se busca dentro de la empresa actual (nunca confiar en el ID que llega del navegador)
        $serie = Filament::getTenant()->series()->findOrFail($data['serie_id']);
        if ($serie->tipo_comprobante->esNota()) {
            Permisos::exigir(Permiso::EmitirNotas, mensaje: 'Solo un administrador puede crear notas de crédito o débito.');
        }

        $data['serie'] = $serie->serie;
        $data['tipo_comprobante'] = $serie->tipo_comprobante;

        if (($data['forma_pago'] ?? 'Contado') === 'Contado') {
            $data['fecha_vencimiento'] = null;
        }

        if (! $serie->tipo_comprobante->esNota()) {
            $data['comprobante_referencia_id'] = null;
            $data['motivo_codigo'] = null;
            $data['motivo_descripcion'] = null;
        }

        return $data;
    }

    /** true si se intentó emitir y falló (el usuario debe quedarse en el formulario para corregir) */
    public bool $emisionFallida = false;

    protected function emitirRegistro(): void
    {
        try {
            $c = app(EmisionService::class)->emitir($this->record);
            Notification::make()->success()->title("{$c->numero} enviado a SUNAT")->send();
        } catch (ValidationException $e) {
            $this->emisionFallida = true;
            Notification::make()->warning()
                ->title('Se guardó como borrador, pero no se pudo emitir')
                ->body(implode("\n", collect($e->errors())->flatten()->all()))
                ->duration(12000)
                ->send();
        }
    }

    /** Tras guardar: al detalle si se emitió; al formulario de edición si hay algo que corregir. */
    protected function urlTrasGuardar(): string
    {
        return $this->getResource()::getUrl($this->emisionFallida ? 'edit' : 'view', ['record' => $this->record]);
    }
}
