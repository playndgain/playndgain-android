<?php

namespace App\Fraud\Rules;

use App\Enums\FraudStage;
use App\Fraud\Contracts\Rule;
use App\Fraud\FraudContext;
use App\Fraud\RuleResult;
use App\Models\OfferClick;

/**
 * Too many clicks from one end user inside a window — docs/05 §3.
 */
class ClickVelocityRule implements Rule
{
    public static function key(): string
    {
        return 'click_velocity';
    }

    public static function stage(): FraudStage
    {
        return FraudStage::PreClick;
    }

    public function evaluate(FraudContext $context, array $config): RuleResult
    {
        // Thresholds come from the row, never a constant. The defaults here
        // are the seeded values and exist only so a rule row with an emptied
        // config still evaluates instead of counting against zero.
        $max = (int) ($config['max'] ?? 20);
        $windowMinutes = (int) ($config['window_minutes'] ?? 5);
        $since = now()->subMinutes($windowMinutes);

        $count = OfferClick::query()
            ->withoutGlobalScopes()
            ->where('publisher_id', $context->publisherId())
            ->where('end_user_id', $context->endUserId)
            ->where('is_test', false)
            ->where('created_at', '>=', $since)
            ->count();

        // The click being judged has not been written yet, so reaching `max`
        // in history means this one is the (max + 1)th.
        if ($count < $max) {
            return RuleResult::pass();
        }

        return RuleResult::trigger('click velocity over the configured maximum', [
            'clicks_in_window' => $count,
            'max' => $max,
            'window_minutes' => $windowMinutes,
            'since' => $since->toIso8601String(),
            'publisher_id' => $context->publisherId(),
            'end_user_id' => $context->endUserId,
        ]);
    }
}
