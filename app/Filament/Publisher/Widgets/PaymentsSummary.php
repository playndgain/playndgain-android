<?php

namespace App\Filament\Publisher\Widgets;

use App\Enums\PayoutStatementStatus;
use App\Enums\WithdrawalStatus;
use App\Filament\Publisher\Support\CurrentPublisher;
use App\Models\PayoutStatement;
use App\Services\Money\Money;
use App\Services\Money\PayoutStatementService;
use App\Services\Money\WithdrawalService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/**
 * The five cards on the Payments page — docs/08 §10.
 *
 * Every money figure is the ledger's: "This month" is computed exactly the
 * way next month's statement will be, so the card and the row it becomes can
 * never disagree.
 */
class PaymentsSummary extends StatsOverviewWidget
{
    /**
     * Discovery scans the whole Widgets directory and would mount this on the
     * Dashboard too. Header widgets are referenced by name, so opting out
     * costs the Payments page nothing.
     */
    protected static bool $isDiscovered = false;

    protected function getColumns(): int|array|null
    {
        return ['default' => 1, 'sm' => 2, 'xl' => 5];
    }

    protected function getStats(): array
    {
        $publisher = CurrentPublisher::get();

        if ($publisher === null) {
            return [];
        }

        $statements = app(PayoutStatementService::class);
        $withdrawals = app(WithdrawalService::class);

        $month = $statements->sums($publisher->id, now()->startOfMonth());
        $pending = $statements->pendingPayment($publisher);
        $paid = Money::format($publisher->withdrawals()->where('status', WithdrawalStatus::Paid)->sum('amount'));
        $minimum = $withdrawals->minimumPayout($publisher);

        $open = PayoutStatement::query()
            ->where('publisher_id', $publisher->id)
            ->whereIn('status', [PayoutStatementStatus::Pending, PayoutStatementStatus::Carried]);
        $openCount = (clone $open)->count();
        $nextDue = (clone $open)->orderBy('due_date')->value('due_date');

        $hasChargebacks = bccomp($month['chargebacks'], '0', Money::SCALE) !== 0;

        return [
            Stat::make('This month', Money::display($month['gross']))
                ->description($hasChargebacks
                    ? 'After '.Money::display(ltrim($month['chargebacks'], '-')).' in chargebacks: '.Money::display($month['net'])
                    : 'Current month earnings')
                ->descriptionColor($hasChargebacks ? 'loss' : null),

            Stat::make('Pending payment', Money::display($pending))
                ->description($openCount === 1
                    ? '1 statement awaiting payment'
                    : "{$openCount} statements awaiting payment"),

            Stat::make('Total paid', Money::display($paid))
                ->description('All-time payouts'),

            Stat::make('Minimum payout', '$'.$minimum)
                ->description('Required for a monthly payout'),

            Stat::make('Payment terms', 'Monthly')
                ->description($nextDue !== null
                    ? 'Next due '.Carbon::parse($nextDue)->format('M j, Y')
                    : 'Earnings clear on the 1st of the following month'),
        ];
    }
}
