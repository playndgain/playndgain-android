<?php

namespace App\Filament\Publisher\Widgets;

use App\Enums\WithdrawalStatus;
use App\Filament\Publisher\Support\CurrentPublisher;
use App\Services\Money\WithdrawalService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/** Header cards on the withdrawals page — docs/08 §10. */
class WithdrawalSummary extends StatsOverviewWidget
{
    /**
     * Discovery scans the whole Widgets directory, so without this the panel
     * mounts this widget on the Dashboard as well as on the page that asks for
     * it by name — the same four cards twice, once in a place docs/08 §3 never
     * put them. Header widgets are referenced explicitly and do not go through
     * discovery, so opting out costs the withdrawals page nothing.
     */
    protected static bool $isDiscovered = false;

    protected function getStats(): array
    {
        $publisher = CurrentPublisher::get();

        if ($publisher === null) {
            return [];
        }

        $service = app(WithdrawalService::class);
        $available = (float) $publisher->withdrawableBalance();
        $minimum = (float) $service->minimumPayout($publisher);

        return [
            Stat::make('Available balance', '$'.number_format($available, 2))
                ->color($available >= $minimum ? 'success' : 'gray'),

            Stat::make('Minimum payout', '$'.number_format($minimum, 2)),

            Stat::make('Payment frequency', 'Monthly')
                ->description('Earnings clear on the 1st of next month'),

            Stat::make('Next eligible', $this->nextEligible())
                ->description($service->hasOpenRequest($publisher)
                    ? 'You have an open request'
                    : 'Once cleared balance passes the minimum'),
        ];
    }

    /**
     * The date the oldest still-held earning clears. That is the honest answer
     * to "when can I withdraw" — a fixed monthly date would be a guess.
     */
    private function nextEligible(): string
    {
        $publisher = CurrentPublisher::get();

        $next = $publisher?->ledgerEntries()
            ->where('available_at', '>', now())
            ->orderBy('available_at')
            ->value('available_at');

        if ($next === null) {
            return $publisher?->withdrawals()
                ->whereIn('status', [WithdrawalStatus::Requested, WithdrawalStatus::Processing])
                ->exists()
                ? 'after the open request'
                : 'now';
        }

        return Carbon::parse($next)->toFormattedDateString();
    }
}
