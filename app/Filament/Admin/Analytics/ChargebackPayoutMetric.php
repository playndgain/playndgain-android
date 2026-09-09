<?php

namespace App\Filament\Admin\Analytics;

use App\Services\Reporting\AnalyticsWindow;
use App\Services\Reporting\RiskQuery;
use LaBoiteACode\FilamentDashboardWidgets\Data\Metric;

class ChargebackPayoutMetric extends AnalyticsMetric
{
    protected static ?int $sort = 9;

    protected function metricFrom(AnalyticsWindow $window): Metric
    {
        $now = $this->risk()->chargebacks();
        $then = RiskQuery::make($window->previous())->chargebacks();

        return $this->money('Chargeback payout', $now['payout'], $then['payout'], [], 'loss');
    }
}
