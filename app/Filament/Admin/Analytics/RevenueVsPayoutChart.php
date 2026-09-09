<?php

namespace App\Filament\Admin\Analytics;

use Carbon\CarbonImmutable;
use LaBoiteACode\FilamentDashboardWidgets\Data\ChartSeries;
use LaBoiteACode\FilamentDashboardWidgets\Widgets\ComparisonChartWidget;

class RevenueVsPayoutChart extends ComparisonChartWidget
{
    use ReadsAnalyticsFilters;

    protected static bool $isLazy = false;

    protected static ?int $sort = 10;

    protected int|string|array $columnSpan = 2;

    protected ?string $heading = 'Revenue vs payout';

    protected function getSeries(): array
    {
        $window = $this->window();
        $days = $window->stats()->daily($window->from, $window->to);

        return [
            ChartSeries::make('Revenue')
                ->values($days->map(fn (object $row): float => (float) $row->revenue)->values()->all())
                ->color('primary')
                ->filled(),
            ChartSeries::make('Publisher payout')
                ->values($days->map(fn (object $row): float => (float) $row->payout)->values()->all())
                ->color('gray'),
        ];
    }

    protected function getLabels(): array
    {
        $window = $this->window();

        return $window->stats()
            ->daily($window->from, $window->to)
            ->keys()
            ->map(fn (string $date): string => CarbonImmutable::parse($date)->format('M j'))
            ->values()
            ->all();
    }
}
