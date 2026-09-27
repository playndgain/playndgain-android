<?php

namespace App\Filament\Admin\Audience;

use App\Services\Reporting\AudienceQuery;
use LaBoiteACode\FilamentDashboardWidgets\Data\Metric;

class MauMetric extends AudienceMetric
{
    protected static ?int $sort = 4;

    protected function metricFrom(AudienceQuery $audience): Metric
    {
        return $this->users(
            'MAU',
            $audience->mau(),
            'Last '.AudienceQuery::MAU_DAYS.' days, today included',
            color: 'primary',
        );
    }
}
