<?php

namespace App\Filament\Admin\Analytics;

use App\Services\Money\Money;
use App\Services\Reporting\AnalyticsWindow;
use LaBoiteACode\FilamentDashboardWidgets\Data\Metric;
use LaBoiteACode\FilamentDashboardWidgets\Widgets\MetricWidget;

abstract class AnalyticsMetric extends MetricWidget
{
    use ReadsAnalyticsFilters;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 1;

    abstract protected function metricFrom(AnalyticsWindow $window): Metric;

    protected function getMetric(): Metric
    {
        return $this->metricFrom($this->window());
    }

    /**
     * @param  list<float>  $spark
     */
    protected function money(string $label, string $now, string $then, array $spark, string $color = 'gray'): Metric
    {
        return $this->withTrend(
            Metric::make($label, (float) $now)
                ->formatUsing(fn (float $value): string => Money::display((string) $value))
                ->sparkline($spark)
                ->color($color),
            (float) $now,
            (float) $then,
        );
    }

    /**
     * @param  list<float>  $spark
     */
    protected function count(string $label, int $now, int $then, array $spark, string $color = 'gray'): Metric
    {
        return $this->withTrend(
            Metric::make($label, $now)
                ->formatUsing(fn (int $value): string => number_format($value))
                ->sparkline($spark)
                ->color($color),
            (float) $now,
            (float) $then,
        );
    }

    protected function withTrend(Metric $metric, float $now, float $then): Metric
    {
        if ($then <= 0.0) {
            return $metric->description('first data');
        }

        return $metric->trend(($now - $then) / $then * 100);
    }

    /**
     * @return list<float>
     */
    protected function spark(string $field): array
    {
        $window = $this->window();

        return $window->stats()
            ->daily($window->from, $window->to)
            ->map(fn (object $row): float => (float) $row->{$field})
            ->values()
            ->all();
    }
}
