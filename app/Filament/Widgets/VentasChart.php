<?php

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;

class VentasChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected ?string $heading = 'Ventas de los últimos 14 días';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '260px';

    protected ?string $pollingInterval = '60s';

    protected function getData(): array
    {
        $desde = today()->subDays(13);

        $porDia = VentasOverview::ventas()
            ->whereDate('fecha', '>=', $desde)
            ->selectRaw('fecha, sum(neto) as total')
            ->groupBy('fecha')
            ->pluck('total', 'fecha')
            ->mapWithKeys(fn ($total, $fecha) => [substr((string) $fecha, 0, 10) => (float) $total]);

        $dias = collect(range(0, 13))->map(fn ($i) => $desde->copy()->addDays($i));

        return [
            'labels' => $dias->map(fn ($d) => $d->format('d/m'))->all(),
            'datasets' => [[
                'label' => 'Ventas (S/)',
                'data' => $dias->map(fn ($d) => $porDia[$d->toDateString()] ?? 0)->all(),
                'borderColor' => '#ff0066',
                'backgroundColor' => 'rgba(255, 0, 102, 0.12)',
                'pointBackgroundColor' => '#ff0066',
                'fill' => true,
                'tension' => 0.35,
            ]],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
