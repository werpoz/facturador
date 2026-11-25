<?php

namespace App\Console\Commands;

use App\Enums\EstadoComprobante;
use App\Enums\RolEmpresa;
use App\Filament\Resources\Comprobantes\ComprobanteResource;
use App\Jobs\EnviarComprobanteJob;
use App\Models\Comprobante;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;

/**
 * Vigila que ningún comprobante se quede sin llegar a SUNAT.
 * Se ejecuta sola cada 10 minutos (ver routes/console.php):
 *
 *  1. Reencola los comprobantes atascados en "Pendiente" o "Error".
 *  2. Avisa a los administradores cuando uno está por vencer su plazo o ya venció.
 */
class VigilarEnvios extends Command
{
    protected $signature = 'facturacion:vigilar';

    protected $description = 'Reintenta envíos atascados y alerta sobre plazos por vencer';

    public function handle(): int
    {
        $reencolados = 0;
        $alertas = 0;

        Comprobante::query()
            ->with('empresa')
            ->whereNotNull('correlativo')
            ->whereIn('estado', [EstadoComprobante::Pendiente, EstadoComprobante::Error])
            ->where('updated_at', '<=', now()->subMinutes(5)) // deja trabajar a los reintentos normales de la cola
            ->orderBy('id')
            ->each(function (Comprobante $c) use (&$reencolados, &$alertas) {
                EnviarComprobanteJob::dispatch($c->id);
                $reencolados++;

                if ($this->alertar($c)) {
                    $alertas++;
                }
            });

        $this->info("Reencolados: {$reencolados} · Alertas enviadas: {$alertas}");

        return self::SUCCESS;
    }

    /** Avisa (como máximo una vez al día por comprobante) si queda 1 día o menos, o si ya venció. */
    private function alertar(Comprobante $c): bool
    {
        $dias = $c->diasParaVencer();

        if ($dias > 1 || $c->alertado_at?->isToday()) {
            return false;
        }

        $admins = User::query()
            ->whereHas('empresas', fn ($q) => $q->whereKey($c->empresa_id)->where('empresa_user.rol', RolEmpresa::Admin->value))
            ->get();

        if ($admins->isEmpty()) {
            return false;
        }

        $vencido = $dias < 0;

        $aviso = Notification::make()
            ->title($vencido
                ? "{$c->numero}: venció el plazo de envío a SUNAT"
                : "{$c->numero}: el plazo de envío a SUNAT vence ".($dias === 0 ? 'HOY' : 'mañana'))
            ->body('No se ha podido enviar: '.($c->sunat_mensaje ?: 'sigue en cola').'. Revísalo cuanto antes.')
            ->icon('heroicon-o-exclamation-triangle')
            ->color($vencido ? 'danger' : 'warning')
            ->actions([
                Action::make('ver')->label('Ver comprobante')->button()
                    ->url(ComprobanteResource::getUrl('view', ['record' => $c, 'tenant' => $c->empresa], panel: 'admin')),
            ])
            ->toDatabase();

        // Se guarda de inmediato (sin pasar por la cola): si la cola está caída, la alerta igual llega
        $admins->each->notifyNow($aviso);

        $c->forceFill(['alertado_at' => now()])->saveQuietly();

        return true;
    }
}
