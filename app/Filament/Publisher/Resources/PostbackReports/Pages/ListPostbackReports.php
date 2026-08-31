<?php

namespace App\Filament\Publisher\Resources\PostbackReports\Pages;

use App\Filament\Publisher\Resources\PostbackReports\PostbackReportResource;
use App\Filament\Publisher\Widgets\PostbackHealth;
use Filament\Resources\Pages\ListRecords;

class ListPostbackReports extends ListRecords
{
    protected static string $resource = PostbackReportResource::class;

    /** The 24h health summary sits above the log — docs/08 §9. */
    protected function getHeaderWidgets(): array
    {
        return [PostbackHealth::class];
    }
}
