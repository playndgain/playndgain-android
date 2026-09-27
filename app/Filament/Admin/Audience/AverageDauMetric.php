<?php

namespace App\Filament\Admin\Audience;

use App\Services\Reporting\AudienceQuery;
use LaBoiteACode\FilamentDashboardWidgets\Data\Metric;

class AverageDauMetric extends AudienceMetric
{
    protected static ?int $sort = 3;

    protected function metricFrom(AudienceQuery $audience): Metric
    {
        return $this->users(
            'Average DAU',
            round($audience->averageDau(), 1),
            AudienceQuery::AVERAGE_DAYS.' complete days, quiet days count',
            $audience->completeDays()->map(fn (int $users): float => (float) $users)->values()->all(),
        );
    }
}
