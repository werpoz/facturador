<?php

namespace App\Filament\Widgets;

use App\Enums\EstadoComprobante;
use App\Enums\EstadoVenta;
use App\Filament\Resources\Comprobantes\ComprobanteResource;
use App\Filament\Resources\Ventas\VentaResource;
use App\Models\Comprobante;
use App\Models\Venta;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

class VentasOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '30s';

    protected function getColumns(): int|array
    {
        return ['default' => 1, 'sm' => 2, 'xl' => 4];
    }

    /** Ventas vigentes en soles, ya descontadas las devoluciones (monto neto). */
    public static function ventas(): Builder
    {
        return Venta::query()
            ->where('empresa_id', Filament::getTenant()?->id)
            ->where('moneda', 'PEN')
            ->whereIn('estado', [EstadoVenta::Confirmada, EstadoVenta::ConDevolucion]);
    }

    protected function getStats(): array
    {
        $hoy = (float) static::ventas()->whereDate('fecha', today())->sum('neto');
        $cantidadHoy = static::ventas()->whereDate('fecha', today())->count();
        $mes = (float) static::ventas()->whereBetween('fecha', [now()->startOfMonth(), now()->endOfMonth()])->sum('neto');
        $cantidadMes = static::ventas()->whereBetween('fecha', [now()->startOfMonth(), now()->endOfMonth()])->count();
        $anterior = (float) static::ventas()
            ->whereBetween('fecha', [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()])
            ->sum('neto');
        $variacion = $anterior > 0 ? round(($mes - $anterior) / $anterior * 100, 1) : null;

        $porAtender = Comprobante::query()
            ->where('empresa_id', Filament::getTenant()?->id)
            ->whereIn('estado', [EstadoComprobante::Rechazado, EstadoComprobante::Error, EstadoComprobante::Pendiente])
            ->count();

        return [
            Stat::make('Ventas de hoy', 'S/ '.number_format($hoy, 2))
                ->icon('heroicon-o-banknotes')
                ->description($cantidadHoy === 1 ? '1 venta' : "{$cantidadHoy} ventas")
                ->color('primary'),
            Stat::make('Ventas del mes', 'S/ '.number_format($mes, 2))
                ->icon('heroicon-o-calendar-days')
                ->description($variacion === null
                    ? 'Primer mes con ventas'
                    : ($variacion >= 0 ? "+{$variacion}%" : "{$variacion}%").' frente al mes anterior')
                ->descriptionIcon($variacion !== null && $variacion < 0 ? 'heroicon-m-arrow-trending-down' : 'heroicon-m-arrow-trending-up')
                ->color($variacion !== null && $variacion < 0 ? 'danger' : 'success'),
            Stat::make('N.º de ventas del mes', (string) $cantidadMes)
                ->icon('heroicon-o-shopping-bag')
                ->description($cantidadMes > 0 ? 'Promedio S/ '.number_format($mes / $cantidadMes, 2).' por venta' : 'Aún sin ventas')
                ->url(VentaResource::getUrl('index'))
                ->color('gray'),
            Stat::make('Por atender', (string) $porAtender)
                ->icon('heroicon-o-exclamation-triangle')
                ->description($porAtender ? 'Rechazados, con error o en cola' : 'Todo enviado a SUNAT')
                ->descriptionIcon($porAtender ? 'heroicon-m-exclamation-circle' : 'heroicon-m-check-circle')
                ->url(ComprobanteResource::getUrl('index', ['tab' => 'por-atender']))
                ->color($porAtender ? 'danger' : 'success'),
        ];
    }
}
