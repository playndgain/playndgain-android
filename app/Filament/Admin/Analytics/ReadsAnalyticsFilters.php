<?php

namespace App\Filament\Admin\Analytics;

use App\Services\Reporting\AnalyticsWindow;
use App\Services\Reporting\RiskQuery;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

trait ReadsAnalyticsFilters
{
    use InteractsWithPageFilters;

    protected function window(): AnalyticsWindow
    {
        return AnalyticsWindow::fromFilters($this->pageFilters ?? []);
    }

    protected function risk(): RiskQuery
    {
        return RiskQuery::make($this->window());
    }
}
