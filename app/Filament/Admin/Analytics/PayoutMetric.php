<?php

namespace App\Filament\Admin\Analytics;

use App\Services\Reporting\AnalyticsWindow;
use LaBoiteACode\FilamentDashboardWidgets\Data\Metric;

class PayoutMetric extends AnalyticsMetric
{
    protected static ?int $sort = 2;

    protected function metricFrom(AnalyticsWindow $window): Metric
    {
        return $this->money(
            'Publisher payout',
            $window->stats()->totals()['payout'],
            $window->previousStats()->totals()['payout'],
            $this->spark('payout'),
        );
    }
}
