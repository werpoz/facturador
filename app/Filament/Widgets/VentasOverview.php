<?php

namespace App\Filament\Widgets;

use App\Enums\EstadoComprobante;
use App\Enums\TipoComprobante;
use App\Models\Comprobante;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

class VentasOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $base = fn (): Builder => Comprobante::query()
            ->where('empresa_id', Filament::getTenant()?->id)
            ->where('moneda', 'PEN');

        // Ventas = facturas + boletas aceptadas - notas de crédito aceptadas
        $ventas = function (Builder $q): float {
            $validos = [EstadoComprobante::Aceptado, EstadoComprobante::Observado, EstadoComprobante::Pendiente];
            $suma = (clone $q)->whereIn('estado', $validos)
                ->whereIn('tipo_comprobante', [TipoComprobante::Factura, TipoComprobante::Boleta, TipoComprobante::NotaDebito])
                ->sum('total');
            $nc = (clone $q)->whereIn('estado', $validos)
                ->where('tipo_comprobante', TipoComprobante::NotaCredito)
                ->sum('total');

            return (float) $suma - (float) $nc;
        };

        $hoy = $ventas($base()->whereDate('fecha_emision', today()));
        $mes = $ventas($base()->whereBetween('fecha_emision', [now()->startOfMonth(), now()->endOfMonth()]));
        $mesAnterior = $ventas($base()->whereBetween('fecha_emision', [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()]));

        // Ventas por día de los últimos 14 días para la mini-gráfica
        $tendencia = collect(range(13, 0))->map(fn ($d) => $ventas($base()->whereDate('fecha_emision', today()->subDays($d))))->all();

        $problemas = $base()->whereIn('estado', [EstadoComprobante::Rechazado, EstadoComprobante::Error])->count();
        $pendientes = $base()->where('estado', EstadoComprobante::Pendiente)->count();

        $variacion = $mesAnterior > 0 ? round(($mes - $mesAnterior) / $mesAnterior * 100, 1) : null;

        return [
            Stat::make('Ventas de hoy', 'S/ '.number_format($hoy, 2))
                ->chart($tendencia)
                ->color('primary'),
            Stat::make('Ventas del mes', 'S/ '.number_format($mes, 2))
                ->description($variacion === null ? 'Sin datos del mes anterior' : ($variacion >= 0 ? "+{$variacion}%" : "{$variacion}%").' vs. mes anterior')
                ->descriptionIcon($variacion !== null && $variacion < 0 ? 'heroicon-m-arrow-trending-down' : 'heroicon-m-arrow-trending-up')
                ->color($variacion !== null && $variacion < 0 ? 'danger' : 'success'),
            Stat::make('Por atender', (string) $problemas)
                ->description($pendientes ? "{$pendientes} en cola de envío" : 'Rechazados o con error')
                ->descriptionIcon($problemas ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color($problemas ? 'danger' : 'success'),
        ];
    }
}
