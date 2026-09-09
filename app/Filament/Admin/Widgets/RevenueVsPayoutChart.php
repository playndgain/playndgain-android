<?php

namespace App\Filament\Admin\Widgets;

use App\Services\Reporting\StatsQuery;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;

/** Thirty days, revenue against payout. The gap between the lines is the margin. */
class RevenueVsPayoutChart extends ChartWidget
{
    protected static ?int $sort = 3;

    protected ?string $heading = 'Revenue vs payout — 30 days';

    protected int|string|array $columnSpan = 1;

    protected ?string $maxHeight = '260px';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $to = CarbonImmutable::today();
        $from = $to->subDays(29);

        $days = StatsQuery::make()->between($from, $to)->daily($from, $to);

        return [
            'datasets' => [
                [
                    'label' => 'Revenue',
                    'data' => $days->map(fn (object $row): float => (float) $row->revenue)->values()->all(),
                    'borderColor' => '#DFC08A',
                    'backgroundColor' => 'rgba(245, 165, 36, 0.12)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
                [
                    'label' => 'Publisher payout',
                    'data' => $days->map(fn (object $row): float => (float) $row->payout)->values()->all(),
                    'borderColor' => '#7D8CA6',
                    'fill' => false,
                    'tension' => 0.3,
                ],
            ],
            'labels' => $days->keys()->map(fn (string $date): string => CarbonImmutable::parse($date)->format('M j'))->all(),
        ];
    }
}
