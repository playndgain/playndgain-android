<?php

namespace App\Filament\Admin\Analytics;

use App\Services\Reporting\AnalyticsWindow;
use App\Services\Reporting\RiskQuery;
use LaBoiteACode\FilamentDashboardWidgets\Data\Metric;

class ChargebackCountMetric extends AnalyticsMetric
{
    protected static ?int $sort = 8;

    protected function metricFrom(AnalyticsWindow $window): Metric
    {
        $now = $this->risk()->chargebacks();
        $then = RiskQuery::make($window->previous())->chargebacks();

        return $this->count('Chargebacks', $now['count'], $then['count'], [], 'loss');
    }
}
