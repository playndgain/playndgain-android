<?php

namespace App\Filament\Admin\Analytics;

use LaBoiteACode\FilamentDashboardWidgets\Data\FunnelStage;
use LaBoiteACode\FilamentDashboardWidgets\Widgets\FunnelWidget;

class ClickConversionFunnel extends FunnelWidget
{
    use ReadsAnalyticsFilters;

    protected static bool $isLazy = false;

    protected static ?int $sort = 11;

    protected int|string|array $columnSpan = 2;

    protected ?string $heading = 'Clicks to approved';

    protected function getStages(): array
    {
        $totals = $this->window()->stats()->totals();

        return [
            FunnelStage::make('Clicks', $totals['clicks'])->color('gray'),
            FunnelStage::make('Approved', $totals['conversions'])->color('success'),
        ];
    }
}
