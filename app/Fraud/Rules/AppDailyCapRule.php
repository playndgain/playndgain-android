<?php

namespace App\Fraud\Rules;

use App\Enums\FraudStage;
use App\Fraud\Contracts\Rule;
use App\Fraud\FraudContext;
use App\Fraud\RuleResult;
use App\Fraud\Rules\Concerns\CountsConversions;

/**
 * App exceeded its configured daily conversion cap — docs/05 §3.
 *
 * The threshold is `apps.daily_conversion_cap`, set per placement by an admin.
 */
class AppDailyCapRule implements Rule
{
    use CountsConversions;

    public static function key(): string
    {
        return 'app_daily_cap';
    }

    public static function stage(): FraudStage
    {
        return FraudStage::PostConversion;
    }

    public function evaluate(FraudContext $context, array $config): RuleResult
    {
        // The app's own cap wins; `config.max` is a platform-wide ceiling an
        // operator can drop in from the table for every app that has none.
        $appCap = $context->app->daily_conversion_cap;
        $cap = $appCap === null ? $config['max'] ?? null : (int) $appCap;

        if (! is_numeric($cap)) {
            return RuleResult::pass();
        }

        $cap = (int) $cap;
        $since = now()->startOfDay();

        $count = $this->countable($context)
            ->where('app_id', $context->appId())
            ->where('created_at', '>=', $since)
            ->count();

        if ($count < $cap) {
            return RuleResult::pass();
        }

        return RuleResult::trigger('app is over its daily conversion cap', [
            'conversions_today' => $count,
            'cap' => $cap,
            'cap_source' => $appCap === null ? 'rule_config' : 'apps.daily_conversion_cap',
            'since' => $since->toIso8601String(),
            'app_id' => $context->appId(),
        ]);
    }
}
