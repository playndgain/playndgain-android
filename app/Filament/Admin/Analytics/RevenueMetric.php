<?php

namespace App\Filament\Admin\Analytics;

use App\Services\Reporting\AnalyticsWindow;
use LaBoiteACode\FilamentDashboardWidgets\Data\Metric;

class RevenueMetric extends AnalyticsMetric
{
    protected static ?int $sort = 1;

    protected function metricFrom(AnalyticsWindow $window): Metric
    {
        return $this->money(
            'Revenue',
            $window->stats()->totals()['revenue'],
            $window->previousStats()->totals()['revenue'],
            $this->spark('revenue'),
            'primary',
        );
    }
}
