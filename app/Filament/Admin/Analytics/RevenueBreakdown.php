<?php

namespace App\Filament\Admin\Analytics;

use App\Services\Money\Money;
use LaBoiteACode\FilamentDashboardWidgets\Data\BreakdownItem;
use LaBoiteACode\FilamentDashboardWidgets\Widgets\BreakdownWidget;

abstract class RevenueBreakdown extends BreakdownWidget
{
    use ReadsAnalyticsFilters;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 2;

    abstract protected function dimension(): string;

    abstract protected function labelFor(string $key): string;

    protected function getItems(): array
    {
        return $this->window()->stats()
            ->groupedBy($this->dimension())
            ->map(fn (object $row): BreakdownItem => BreakdownItem::make(
                $this->labelFor((string) $row->bucket),
                (float) $row->revenue,
            )
                ->formatUsing(fn (float $value): string => Money::display((string) $value))
                ->color('primary')
                ->url($this->urlFor((string) $row->bucket)))
            ->values()
            ->all();
    }

    protected function urlFor(string $key): ?string
    {
        return null;
    }
}
