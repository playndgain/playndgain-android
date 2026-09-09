<?php

namespace App\Filament\Admin\Analytics;

use App\Services\Reporting\AnalyticsWindow;
use LaBoiteACode\FilamentDashboardWidgets\Data\Metric;

class ConversionsMetric extends AnalyticsMetric
{
    protected static ?int $sort = 5;

    protected function metricFrom(AnalyticsWindow $window): Metric
    {
        return $this->count(
            'Conversions',
            $window->stats()->totals()['conversions'],
            $window->previousStats()->totals()['conversions'],
            $this->spark('conversions'),
        );
    }
}
