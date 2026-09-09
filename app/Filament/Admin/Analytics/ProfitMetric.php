<?php

namespace App\Filament\Admin\Analytics;

use App\Services\Reporting\AnalyticsWindow;
use LaBoiteACode\FilamentDashboardWidgets\Data\Metric;

class ProfitMetric extends AnalyticsMetric
{
    protected static ?int $sort = 3;

    protected function metricFrom(AnalyticsWindow $window): Metric
    {
        return $this->money(
            'Profit',
            $window->stats()->totals()['profit'],
            $window->previousStats()->totals()['profit'],
            $window->stats()
                ->daily($window->from, $window->to)
                ->map(fn (object $row): float => (float) $row->revenue - (float) $row->payout)
                ->values()
                ->all(),
            'gain',
        );
    }
}
