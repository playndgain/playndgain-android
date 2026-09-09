<?php

namespace App\Filament\Publisher\Widgets;

use App\Filament\Publisher\Support\CurrentPublisher;
use App\Filament\Publisher\Widgets\Concerns\FiltersBySelectedApp;
use App\Services\Money\Money;
use App\Services\Reporting\StatsQuery;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Earnings row — docs/08 §3.
 *
 * Four periods, each against the one before it. These are payout figures, not
 * revenue: the publisher sees what they earn, never what the network paid us.
 */
class EarningsStats extends StatsOverviewWidget
{
    use FiltersBySelectedApp;

    protected static ?int $sort = 1;

    protected ?string $heading = 'Earnings';

    protected function getStats(): array
    {
        $today = CarbonImmutable::today();

        return [
            $this->period('Today', $today, $today, $today->subDay(), $today->subDay()),
            $this->period(
                'This month',
                $today->startOfMonth(), $today,
                $today->subMonth()->startOfMonth(), $today->subMonth(),
            ),
            $this->period(
                'Last month',
                $today->subMonth()->startOfMonth(), $today->subMonth()->endOfMonth(),
                $today->subMonths(2)->startOfMonth(), $today->subMonths(2)->endOfMonth(),
            ),
            $this->period(
                'This year',
                $today->startOfYear(), $today,
                $today->subYear()->startOfYear(), $today->subYear(),
            ),
        ];
    }

    private function period(
        string $label,
        CarbonImmutable $from,
        CarbonImmutable $to,
        CarbonImmutable $previousFrom,
        CarbonImmutable $previousTo,
    ): Stat {
        $publisherId = CurrentPublisher::id();
        $appId = $this->selectedAppId();

        $now = (float) StatsQuery::make()->forPublisher($publisherId)->forApp($appId)->between($from, $to)->totals()['payout'];
        $then = (float) StatsQuery::make()->forPublisher($publisherId)->forApp($appId)->between($previousFrom, $previousTo)->totals()['payout'];

        $stat = Stat::make($label, Money::display($now));

        if ($then <= 0.0) {
            return $stat->description($now > 0 ? 'no prior period to compare' : 'nothing yet');
        }

        $delta = ($now - $then) / $then * 100;
        $up = $delta >= 0;

        return $stat
            ->description(sprintf('%s%.1f%% vs previous', $up ? '+' : '', $delta))
            ->descriptionIcon($up ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
            ->color($up ? 'gain' : 'loss');
    }
}
