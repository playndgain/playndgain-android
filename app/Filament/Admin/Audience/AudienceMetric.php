<?php

namespace App\Filament\Admin\Audience;

use App\Services\Reporting\AudienceQuery;
use LaBoiteACode\FilamentDashboardWidgets\Data\Metric;
use LaBoiteACode\FilamentDashboardWidgets\Widgets\MetricWidget;

/**
 * The headline cards. They read only the publisher and app filters — the
 * range filter never reaches them, so each card keeps its fixed definition.
 * No trend arrows: a part-day today against a whole yesterday would mislead.
 */
abstract class AudienceMetric extends MetricWidget
{
    use ReadsAudienceFilters;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 1;

    abstract protected function metricFrom(AudienceQuery $audience): Metric;

    protected function getMetric(): Metric
    {
        return $this->metricFrom($this->audience());
    }

    /**
     * @param  list<float>  $spark
     */
    protected function users(string $label, int|float $value, string $description, array $spark = [], string $color = 'gray'): Metric
    {
        $metric = Metric::make($label, $value)
            ->formatUsing(fn (int|float $value): string => number_format($value, is_float($value) ? 1 : 0))
            ->description($description)
            ->color($color);

        return $spark === [] ? $metric : $metric->sparkline($spark);
    }
}
