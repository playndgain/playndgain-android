<?php

namespace App\Fraud\Rules;

use App\Enums\FraudStage;
use App\Fraud\Contracts\Rule;
use App\Fraud\FraudContext;
use App\Fraud\RuleResult;
use App\Models\OfferClick;

/**
 * Too many distinct end users behind one IP — docs/05 §3.
 */
class IpVelocityRule implements Rule
{
    public static function key(): string
    {
        return 'ip_velocity';
    }

    public static function stage(): FraudStage
    {
        return FraudStage::PreClick;
    }

    public function evaluate(FraudContext $context, array $config): RuleResult
    {
        if (blank($context->ip)) {
            return RuleResult::pass();
        }

        $max = (int) ($config['max_users'] ?? 10);
        $windowHours = (int) ($config['window_hours'] ?? 24);
        $since = now()->subHours($windowHours);

        // Counted per publisher, like every other end-user lookup here: the
        // same identifier under two publishers is two people, so a global
        // count of distinct strings would be counting the wrong thing.
        $others = OfferClick::query()
            ->withoutGlobalScopes()
            ->where('publisher_id', $context->publisherId())
            ->where('ip', $context->ip)
            ->where('end_user_id', '!=', $context->endUserId)
            ->where('is_test', false)
            ->where('created_at', '>=', $since)
            ->distinct()
            ->count('end_user_id');

        $total = $others + 1;

        if ($total <= $max) {
            return RuleResult::pass();
        }

        return RuleResult::trigger('too many distinct end users behind one IP', [
            'ip' => $context->ip,
            'distinct_users' => $total,
            'max_users' => $max,
            'window_hours' => $windowHours,
            'since' => $since->toIso8601String(),
            'publisher_id' => $context->publisherId(),
        ]);
    }
}
