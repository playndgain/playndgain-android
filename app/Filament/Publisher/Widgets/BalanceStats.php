<?php

namespace App\Filament\Publisher\Widgets;

use App\Filament\Publisher\Pages\Payments;
use App\Filament\Publisher\Support\CurrentPublisher;
use App\Services\Money\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Balance row — docs/08 §3.
 *
 * "Available" is `withdrawableBalance()` and never `clearedBalance()`. Cleared
 * still contains money the monthly run has already requested, so showing it
 * would promise a payout that is already on its way.
 */
class BalanceStats extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected ?string $heading = 'Balance';

    protected function getStats(): array
    {
        $publisher = CurrentPublisher::get();

        if ($publisher === null) {
            return [];
        }

        $available = $publisher->withdrawableBalance();

        return [
            Stat::make('Available', Money::display($available))
                ->description(bccomp($available, '0', Money::SCALE) < 0 ? 'Negative — future earnings pay this down' : 'Paid automatically on the 1st')
                ->color(bccomp($available, '0', Money::SCALE) < 0 ? 'loss' : 'gain')
                ->url(Payments::getUrl()),

            Stat::make('Pending', Money::display($publisher->pendingBalance()))
                ->description('Earnings clear at the start of next month'),

            Stat::make('Lifetime earnings', Money::display($publisher->lifetime_earnings))
                ->description('All earnings ever, before reversals and withdrawals'),
        ];
    }
}
