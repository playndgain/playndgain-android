<?php

namespace App\Filament\Admin\Analytics;

use App\Services\Reporting\AnalyticsWindow;
use LaBoiteACode\FilamentDashboardWidgets\Data\Metric;

class ClicksMetric extends AnalyticsMetric
{
    protected static ?int $sort = 4;

    protected function metricFrom(AnalyticsWindow $window): Metric
    {
        return $this->count(
            'Clicks',
            $window->stats()->totals()['clicks'],
            $window->previousStats()->totals()['clicks'],
            $this->spark('clicks'),
        );
    }
}
