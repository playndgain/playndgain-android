<?php

namespace App\Fraud\Rules;

use App\Enums\FraudStage;
use App\Fraud\Contracts\Rule;
use App\Fraud\FraudContext;
use App\Fraud\RuleResult;
use App\Fraud\Rules\Concerns\CountsConversions;

/**
 * One end user over the daily conversion limit — docs/05 §3.
 */
class UserDailyCapRule implements Rule
{
    use CountsConversions;

    public static function key(): string
    {
        return 'user_daily_cap';
    }

    public static function stage(): FraudStage
    {
        return FraudStage::PostConversion;
    }

    public function evaluate(FraudContext $context, array $config): RuleResult
    {
        $max = (int) ($config['max'] ?? 25);
        $since = now()->startOfDay();

        // Per publisher, not global: the same end user identifier under two
        // publishers is two people.
        $count = $this->countable($context)
            ->where('publisher_id', $context->publisherId())
            ->where('end_user_id', $context->endUserId)
            ->where('created_at', '>=', $since)
            ->count();

        if ($count < $max) {
            return RuleResult::pass();
        }

        return RuleResult::trigger('end user is over the daily conversion cap', [
            'conversions_today' => $count,
            'max' => $max,
            'since' => $since->toIso8601String(),
            'publisher_id' => $context->publisherId(),
            'end_user_id' => $context->endUserId,
        ]);
    }
}
