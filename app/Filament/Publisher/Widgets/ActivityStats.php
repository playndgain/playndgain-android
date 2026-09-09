<?php

namespace App\Filament\Publisher\Widgets;

use App\Enums\ConversionStatus;
use App\Enums\WithdrawalStatus;
use App\Filament\Publisher\Resources\Transactions\TransactionResource;
use App\Filament\Publisher\Support\CurrentPublisher;
use App\Filament\Publisher\Widgets\Concerns\FiltersBySelectedApp;
use App\Models\Conversion;
use App\Models\Withdrawal;
use App\Services\Reporting\StatsQuery;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

/**
 * Activity row — docs/08 §3.
 *
 * Chargebacks sit here on purpose. Hiding reversals until a publisher notices
 * their balance dropped is how trust dies, and the card links straight to the
 * conversions that reversed.
 */
class ActivityStats extends StatsOverviewWidget
{
    use FiltersBySelectedApp;

    protected static ?int $sort = 3;

    protected function getStats(): array
    {
        $publisherId = CurrentPublisher::id();
        $appId = $this->selectedAppId();
        $today = CarbonImmutable::today();

        $week = StatsQuery::make()->forPublisher($publisherId)->forApp($appId)->between($today->subDays(6), $today)->totals();

        // Withdrawals are publisher-level money and belong to no app, so the
        // selector cannot narrow this one. Showing it unfiltered beside filtered
        // cards is honest; hiding it when an app is selected would read as zero.
        $processing = Withdrawal::query()
            ->where('publisher_id', $publisherId)
            ->whereIn('status', [WithdrawalStatus::Requested, WithdrawalStatus::Processing])
            ->count();

        $chargebacks = Conversion::query()
            ->where('publisher_id', $publisherId)
            ->when($appId !== null, fn (Builder $query): Builder => $query->where('app_id', $appId))
            ->where('status', ConversionStatus::Reversed)
            ->where('reversed_at', '>=', now()->subDays(30))
            ->count();

        return [
            Stat::make('Clicks (7d)', number_format($week['clicks'])),
            Stat::make('Conversions (7d)', number_format($week['conversions'])),
            Stat::make('Conversion rate', $week['clicks'] === 0
                ? '—'
                : number_format($week['conversions'] / $week['clicks'] * 100, 2).'%'),
            Stat::make('Open withdrawals', number_format($processing))
                ->color($processing > 0 ? 'info' : 'gray'),
            Stat::make('Chargebacks (30d)', number_format($chargebacks))
                ->description($chargebacks > 0 ? 'See which conversions reversed' : 'None')
                ->color($chargebacks > 0 ? 'loss' : 'gray')
                ->url(TransactionResource::getUrl(parameters: [
                    'tableFilters' => ['status' => ['value' => ConversionStatus::Reversed->value]],
                ])),
        ];
    }
}
