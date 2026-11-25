<?php

namespace App\Jobs;

use App\Enums\EstadoComprobante;
use App\Models\Comprobante;
use App\Services\Facturacion\EmisionService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class EnviarComprobanteJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public int $timeout = 60;

    public function __construct(public int $comprobanteId) {}

    /** Reintentos con espera creciente: 30s, 2min, 5min, 15min, 1h */
    public function backoff(): array
    {
        return [30, 120, 300, 900, 3600];
    }

    /** Evita enviar el mismo comprobante dos veces en paralelo */
    public function uniqueId(): string
    {
        return (string) $this->comprobanteId;
    }

    public function handle(EmisionService $service): void
    {
        $comprobante = Comprobante::find($this->comprobanteId);

        // Idempotencia: si ya fue aceptado/rechazado no se reenvía
        if (! $comprobante || ! in_array($comprobante->estado, [EstadoComprobante::Pendiente, EstadoComprobante::Error], true)) {
            return;
        }

        $service->enviar($comprobante);
    }
}
