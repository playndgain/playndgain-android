<?php

namespace App\Fraud\Rules;

use App\Enums\FraudStage;
use App\Fraud\Contracts\Rule;
use App\Fraud\FraudContext;
use App\Fraud\RuleResult;

/**
 * Conversion arriving implausibly fast after the click — docs/05 §3.
 *
 * Fifteen seconds is deliberately low. A page visit or an email submit
 * genuinely converts in twenty; set the threshold high and the rule blocks
 * real users on the best-converting offers. It flags, and the operator raises
 * it per offer type once they have their own data.
 */
class ImpossibleSpeedRule implements Rule
{
    public static function key(): string
    {
        return 'impossible_speed';
    }

    public static function stage(): FraudStage
    {
        return FraudStage::PostConversion;
    }

    public function evaluate(FraudContext $context, array $config): RuleResult
    {
        $click = $context->click;
        $clickedAt = $click?->created_at;

        // No click, no elapsed time. `no_click_match` is the rule for that.
        if ($click === null || $clickedAt === null) {
            return RuleResult::pass();
        }

        $minSeconds = (int) ($config['min_seconds'] ?? 15);
        $convertedAt = $context->conversion?->created_at;
        $convertedAt ??= now();

        // Raw timestamps rather than a diff helper: the sign matters — a
        // conversion stamped before its click is the most impossible speed of
        // all — and Carbon's default absoluteness has changed between majors.
        $elapsed = $convertedAt->getTimestamp() - $clickedAt->getTimestamp();

        if ($elapsed >= $minSeconds) {
            return RuleResult::pass();
        }

        return RuleResult::trigger('conversion arrived faster than the offer can be completed', [
            'elapsed_seconds' => $elapsed,
            'min_seconds' => $minSeconds,
            'clicked_at' => $clickedAt->toIso8601String(),
            'converted_at' => $convertedAt->toIso8601String(),
            'click_id' => $click->click_id,
        ]);
    }
}
