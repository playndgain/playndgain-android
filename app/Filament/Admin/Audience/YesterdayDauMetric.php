<?php

namespace App\Filament\Admin\Audience;

use App\Services\Reporting\AudienceQuery;
use LaBoiteACode\FilamentDashboardWidgets\Data\Metric;

class YesterdayDauMetric extends AudienceMetric
{
    protected static ?int $sort = 2;

    protected function metricFrom(AudienceQuery $audience): Metric
    {
        return $this->users(
            'DAU yesterday',
            $audience->yesterdayDau(),
            $this->audienceWindow()->today->subDay()->format('j M').', full day',
        );
    }
}
