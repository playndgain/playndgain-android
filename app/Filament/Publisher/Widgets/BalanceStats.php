<?php

namespace App\Filament\Publisher\Widgets;

use App\Filament\Publisher\Resources\Withdrawals\WithdrawalResource;
use App\Filament\Publisher\Support\CurrentPublisher;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Balance row — docs/08 §3.
 *
 * "Available" is `withdrawableBalance()` and never `clearedBalance()`. Cleared
 * still contains money the publisher has already requested, so showing it next
 * to a Withdraw button produces a form that rejects the number above it.
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

        $available = (float) $publisher->withdrawableBalance();

        return [
            Stat::make('Available', '$'.number_format($available, 2))
                ->description($available < 0 ? 'Negative — future earnings pay this down' : 'Ready to withdraw')
                ->color($available < 0 ? 'danger' : 'success')
                ->url(WithdrawalResource::getUrl()),

            Stat::make('Pending', '$'.number_format((float) $publisher->pendingBalance(), 2))
                ->description('Earnings clear at the start of next month'),

            Stat::make('Lifetime earnings', '$'.number_format((float) $publisher->lifetime_earnings, 2))
                ->description('All earnings ever, before reversals and withdrawals'),
        ];
    }
}
