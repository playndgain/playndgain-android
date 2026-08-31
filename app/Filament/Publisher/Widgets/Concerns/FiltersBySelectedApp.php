<?php

namespace App\Filament\Publisher\Widgets\Concerns;

use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * The Dashboard's app selector, read by the widgets it applies to.
 *
 * `$pageFilters` arrives as raw form state, so the cast lives here once rather
 * than in every widget that reads it — "All apps" comes through as an empty
 * string from the Select, and `StatsQuery::forApp(null)` is what that means.
 */
trait FiltersBySelectedApp
{
    use InteractsWithPageFilters;

    protected function selectedAppId(): ?int
    {
        $appId = $this->pageFilters['app_id'] ?? null;

        return is_numeric($appId) ? (int) $appId : null;
    }
}
