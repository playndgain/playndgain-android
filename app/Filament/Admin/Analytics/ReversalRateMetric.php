<?php

namespace App\Filament\Admin\Analytics;

use App\Services\Reporting\AnalyticsWindow;
use LaBoiteACode\FilamentDashboardWidgets\Data\Metric;

class ReversalRateMetric extends AnalyticsMetric
{
    protected static ?int $sort = 7;

    protected function metricFrom(AnalyticsWindow $window): Metric
    {
        $now = $window->stats()->totals();
        $then = $window->previousStats()->totals();

        $metric = Metric::make('Reversal rate', $this->rate($now))
            ->formatUsing(fn (string $value): string => $value)
            ->lowerIsBetter();

        $thenDenom = $then['conversions'] + $then['reversals'];

        if ($thenDenom === 0) {
            return $metric->description('first data');
        }

        return $this->withTrend(
            $metric,
            $this->ratio($now),
            $this->ratio($then),
        );
    }

    /**
     * @param  array{conversions: int, reversals: int}  $totals
     */
    private function rate(array $totals): string
    {
        $denom = $totals['conversions'] + $totals['reversals'];

        return $denom === 0 ? '—' : number_format($totals['reversals'] / $denom * 100, 2).'%';
    }

    /**
     * @param  array{conversions: int, reversals: int}  $totals
     */
    private function ratio(array $totals): float
    {
        $denom = $totals['conversions'] + $totals['reversals'];

        return $denom === 0 ? 0.0 : $totals['reversals'] / $denom;
    }
}
