<?php

namespace App\Filament\Admin\Widgets;

use App\Services\Reporting\StatsQuery;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Row 1 of the admin dashboard — docs/07 §3.
 *
 * Today, each against yesterday, each with a seven-day sparkline. Profit is the
 * middle card on purpose: it is the number the business runs on and the only
 * one that is not visible anywhere else in the panel.
 */
class PlatformStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $heading = 'Today';

    protected function getStats(): array
    {
        $today = CarbonImmutable::today();
        $yesterday = $today->subDay();

        $now = StatsQuery::make()->between($today, $today)->totals();
        $then = StatsQuery::make()->between($yesterday, $yesterday)->totals();
        $week = StatsQuery::make()->daily($today->subDays(6), $today);

        $spark = fn (string $field): array => $week->map(fn (object $row): float => (float) $row->{$field})->values()->all();

        return [
            $this->money('Revenue', $now['revenue'], $then['revenue'])->chart($spark('revenue')),
            $this->money('Publisher payout', $now['payout'], $then['payout'])->chart($spark('payout')),
            $this->money('Profit', $now['profit'], $then['profit'])->color('primary'),
            $this->count('Conversions', $now['conversions'], $then['conversions'])->chart($spark('conversions')),
            $this->count('Clicks', $now['clicks'], $then['clicks'])->chart($spark('clicks')),
            Stat::make('Conversion rate', $this->rate($now['clicks'], $now['conversions']))
                ->description('vs '.$this->rate($then['clicks'], $then['conversions']).' yesterday'),
        ];
    }

    private function money(string $label, string $now, string $then): Stat
    {
        return $this->withDelta(Stat::make($label, '$'.number_format((float) $now, 2)), (float) $now, (float) $then);
    }

    private function count(string $label, int $now, int $then): Stat
    {
        return $this->withDelta(Stat::make($label, number_format($now)), $now, $then);
    }

    /**
     * A bare "+12%" with no baseline is unreadable when yesterday was zero, so
     * the first day of any metric says "no comparison" rather than "+∞".
     */
    private function withDelta(Stat $stat, float $now, float $then): Stat
    {
        if ($then <= 0.0) {
            return $stat->description($now > 0 ? 'first data' : 'nothing yesterday');
        }

        $delta = ($now - $then) / $then * 100;
        $up = $delta >= 0;

        return $stat
            ->description(sprintf('%s%.1f%% vs yesterday', $up ? '+' : '', $delta))
            ->descriptionIcon($up ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
            ->color($up ? 'success' : 'danger');
    }

    private function rate(int $clicks, int $conversions): string
    {
        return $clicks === 0 ? '—' : number_format($conversions / $clicks * 100, 2).'%';
    }
}
