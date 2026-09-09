<?php

namespace App\Filament\Admin\Analytics;

use LaBoiteACode\FilamentDashboardWidgets\Data\BreakdownItem;
use LaBoiteACode\FilamentDashboardWidgets\Widgets\SegmentBarWidget;

class FraudByAction extends SegmentBarWidget
{
    use ReadsAnalyticsFilters;

    protected static bool $isLazy = false;

    protected static ?int $sort = 19;

    protected int|string|array $columnSpan = 2;

    protected ?string $heading = 'Fraud decisions';

    protected function getSegments(): array
    {
        $counts = $this->risk()->fraudActions();

        if (array_sum($counts) === 0) {
            return [];
        }

        return [
            BreakdownItem::make('Blocked', $counts['blocked'])->color('danger'),
            BreakdownItem::make('Flagged', $counts['flagged'])->color('warning'),
            BreakdownItem::make('Scored', $counts['scored'])->color('gray'),
        ];
    }
}
