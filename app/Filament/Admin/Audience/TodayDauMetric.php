<?php

namespace App\Filament\Admin\Audience;

use App\Services\Reporting\AudienceQuery;
use LaBoiteACode\FilamentDashboardWidgets\Data\Metric;

class TodayDauMetric extends AudienceMetric
{
    protected static ?int $sort = 1;

    protected function metricFrom(AudienceQuery $audience): Metric
    {
        return $this->users(
            'DAU today',
            $audience->todayDau(),
            'Since 00:00 '.$this->audienceWindow()->today->format('T'),
            $audience->daily()->map(fn (int $users): float => (float) $users)->values()->all(),
            'primary',
        );
    }
}
