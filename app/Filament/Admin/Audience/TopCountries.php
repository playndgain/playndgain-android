<?php

namespace App\Filament\Admin\Audience;

use App\Filament\Publisher\Pages\Statistics;
use LaBoiteACode\FilamentDashboardWidgets\Data\BreakdownItem;
use LaBoiteACode\FilamentDashboardWidgets\Widgets\BreakdownWidget;

/**
 * Share is of users with a known country. Unknown is reported beside this in
 * RangeSummary and never spread across the countries here.
 */
class TopCountries extends BreakdownWidget
{
    use ReadsAudienceFilters;

    protected static bool $isLazy = false;

    protected static ?int $sort = 7;

    protected int|string|array $columnSpan = ['default' => 1, 'md' => 2, 'xl' => 3];

    protected ?string $heading = 'Top countries';

    protected ?string $emptyStateHeading = 'No known-country visitors in this range';

    protected ?string $emptyStateDescription = 'No visitor in this range had a resolvable country.';

    public function getHeadingDescription(): ?string
    {
        return $this->audienceWindow()->rangeLabel().', share of users with a known country';
    }

    protected function getItems(): array
    {
        return $this->audience()->countries()
            ->take(10)
            ->map(fn (int $users, string $code): BreakdownItem => BreakdownItem::make(Statistics::countryLabel($code), $users)
                ->formatUsing(fn (float $value): string => number_format($value))
                ->color('primary'))
            ->values()
            ->all();
    }

    /** Every known country, not only the ten shown, so shares are of the whole. */
    protected function getTotal(): ?float
    {
        return (float) $this->audience()->countries()->sum();
    }

    protected function getLimit(): ?int
    {
        return 10;
    }
}
