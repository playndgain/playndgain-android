<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\ConversionStatus;
use App\Enums\NetworkStatus;
use App\Enums\OutgoingPostbackStatus;
use App\Enums\WithdrawalStatus;
use App\Filament\Admin\Resources\Conversions\ConversionResource;
use App\Filament\Admin\Resources\Networks\NetworkResource;
use App\Filament\Admin\Resources\OutgoingPostbacks\OutgoingPostbackResource;
use App\Filament\Admin\Resources\Withdrawals\WithdrawalResource;
use App\Models\Conversion;
use App\Models\Network;
use App\Models\OutgoingPostback;
use App\Models\Publisher;
use App\Models\Withdrawal;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Row 2 — docs/07 §3. Red when breached.
 *
 * These are counts of work waiting on a human, not performance figures, so they
 * come from the source tables rather than `daily_stats`: a pending withdrawal
 * that only appears after the hourly aggregation is a pending withdrawal nobody
 * pays for an hour.
 */
class HealthStats extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected ?string $heading = 'Needs attention';

    protected function getStats(): array
    {
        $openWithdrawals = Withdrawal::query()->withoutGlobalScopes()
            ->whereIn('status', [WithdrawalStatus::Requested, WithdrawalStatus::Processing]);

        $withdrawalCount = (clone $openWithdrawals)->count();
        $withdrawalTotal = (float) (clone $openWithdrawals)->sum('amount');

        $failedPostbacks = OutgoingPostback::query()
            ->where('status', OutgoingPostbackStatus::Failed)
            ->where('created_at', '>=', now()->subDay())
            ->count();

        $networksInError = Network::query()->where('status', NetworkStatus::Error)->count();

        $inReview = Conversion::query()->withoutGlobalScopes()
            ->where('status', ConversionStatus::Pending)
            ->count();

        // Signed sum: a publisher carrying a negative balance genuinely reduces
        // what the platform owes, and hiding that overstates the liability.
        $liability = (float) Publisher::query()->sum('balance');

        return [
            Stat::make('Open withdrawals', '$'.number_format($withdrawalTotal, 2))
                ->description($withdrawalCount.' waiting')
                ->color($withdrawalCount > 0 ? 'warning' : 'gray')
                ->url(WithdrawalResource::getUrl()),

            Stat::make('Failed postbacks (24h)', number_format($failedPostbacks))
                ->color($failedPostbacks > 0 ? 'danger' : 'success')
                ->url(OutgoingPostbackResource::getUrl()),

            Stat::make('Networks in error', number_format($networksInError))
                ->color($networksInError > 0 ? 'danger' : 'success')
                ->url(NetworkResource::getUrl()),

            Stat::make('Conversions in review', number_format($inReview))
                ->color($inReview > 0 ? 'warning' : 'gray')
                ->url(ConversionResource::getUrl(parameters: ['tableFilters' => ['status' => ['value' => ConversionStatus::Pending->value]]])),

            Stat::make('Publisher liability', '$'.number_format($liability, 2))
                ->description('total owed'),
        ];
    }
}
