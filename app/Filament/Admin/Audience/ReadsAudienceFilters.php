<?php

namespace App\Filament\Admin\Audience;

use App\Services\Reporting\AudienceQuery;
use App\Services\Reporting\AudienceWindow;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

trait ReadsAudienceFilters
{
    use InteractsWithPageFilters;

    protected function audienceWindow(): AudienceWindow
    {
        return AudienceWindow::fromFilters($this->pageFilters ?? []);
    }

    protected function audience(): AudienceQuery
    {
        return AudienceQuery::for($this->audienceWindow());
    }
}
