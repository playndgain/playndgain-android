<?php

namespace App\Filament\Admin\Analytics;

use App\Services\Reporting\AnalyticsWindow;
use LaBoiteACode\FilamentDashboardWidgets\Data\Metric;

class ConversionRateMetric extends AnalyticsMetric
{
    protected static ?int $sort = 6;

    protected function metricFrom(AnalyticsWindow $window): Metric
    {
        $now = $window->stats()->totals();
        $then = $window->previousStats()->totals();

        $rate = $this->rate($now['clicks'], $now['conversions']);
        $previous = $this->rate($then['clicks'], $then['conversions']);

        $metric = Metric::make('Conversion rate', $rate)
            ->formatUsing(fn (string $value): string => $value);

        if ($previous === '—' || $then['clicks'] === 0) {
            return $metric->description('first data');
        }

        $nowValue = $now['clicks'] === 0 ? 0.0 : $now['conversions'] / $now['clicks'];
        $thenValue = $then['conversions'] / $then['clicks'];

        return $this->withTrend($metric, $nowValue, $thenValue);
    }

    private function rate(int $clicks, int $conversions): string
    {
        return $clicks === 0 ? '—' : number_format($conversions / $clicks * 100, 2).'%';
    }
}
