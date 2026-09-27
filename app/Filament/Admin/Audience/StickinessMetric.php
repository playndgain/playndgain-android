<?php

namespace App\Filament\Admin\Audience;

use App\Services\Reporting\AudienceQuery;
use LaBoiteACode\FilamentDashboardWidgets\Data\Metric;

class StickinessMetric extends AudienceMetric
{
    protected static ?int $sort = 5;

    protected function metricFrom(AudienceQuery $audience): Metric
    {
        $ratio = $audience->stickiness();

        // A dash, not 0%, when there is no MAU to divide by.
        return Metric::make('DAU/MAU', $ratio ?? '—')
            ->formatUsing(fn (float|string $value): string => is_float($value) ? number_format($value, 1).'%' : $value)
            ->description($ratio === null ? 'No wall activity in the last 30 days' : 'DAU today ÷ MAU')
            ->color('gray');
    }
}
