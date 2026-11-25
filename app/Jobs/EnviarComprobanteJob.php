<?php

namespace App\Jobs;

use App\Models\Comprobante;
use App\Services\Facturacion\EmisionService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class EnviarComprobanteJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public int $timeout = 60;

    /**
     * El candado de "trabajo único" solo evita encolar duplicados durante un minuto.
     * Antes duraba todos los reintentos (más de una hora) y un "Reintentar" manual se perdía en silencio.
     */
    public int $uniqueFor = 60;

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

        if (! $comprobante) {
            return;
        }

        // Exclusión real entre procesos: si otro trabajador ya lo está enviando, este espera su turno.
        $candado = Cache::lock("enviar-comprobante:{$comprobante->id}", 120);

        if (! $candado->get()) {
            $this->release(20);

            return;
        }

        try {
            $service->enviar($comprobante); // idempotente: no reenvía lo que ya tiene resultado
        } finally {
            $candado->release();
        }
    }
}
