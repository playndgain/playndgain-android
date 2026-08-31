<?php

namespace App\Fraud\Rules;

use App\Enums\FraudStage;
use App\Fraud\Contracts\Rule;
use App\Fraud\FraudContext;
use App\Fraud\RuleResult;
use App\Fraud\Rules\Concerns\CountsConversions;
use App\Services\Money\Money;

/**
 * One end user over a daily USD ceiling — docs/05 §3.
 *
 * The count cap misses the farm that takes ten conversions a day at ten
 * dollars each; this is the one that catches it.
 */
class UserDailyPayoutCapRule implements Rule
{
    use CountsConversions;

    public static function key(): string
    {
        return 'user_daily_payout_cap';
    }

    public static function stage(): FraudStage
    {
        return FraudStage::PostConversion;
    }

    public function evaluate(FraudContext $context, array $config): RuleResult
    {
        $max = Money::format(is_numeric($config['max_usd'] ?? null) ? $config['max_usd'] : 50);
        $since = now()->startOfDay();

        $earlier = Money::format($this->countable($context)
            ->where('publisher_id', $context->publisherId())
            ->where('end_user_id', $context->endUserId)
            ->where('created_at', '>=', $since)
            ->sum('payout'));

        // The conversion being judged is not in the sum — `countable()`
        // excludes it — so its own payout is added here. bcmath throughout:
        // float arithmetic on a decimal(14,6) column drifts, and the drift
        // only surfaces in a reconciliation nobody can explain.
        $pending = $context->conversion?->payout;
        $pending ??= $context->payout;
        $pending = Money::format($pending ?? 0);
        $total = bcadd($earlier, $pending, Money::SCALE);

        if (bccomp($total, $max, Money::SCALE) <= 0) {
            return RuleResult::pass();
        }

        return RuleResult::trigger('end user is over the daily payout ceiling', [
            'payout_today' => $earlier,
            'this_payout' => $pending,
            'total_usd' => $total,
            'max_usd' => $max,
            'since' => $since->toIso8601String(),
            'publisher_id' => $context->publisherId(),
            'end_user_id' => $context->endUserId,
        ]);
    }
}
