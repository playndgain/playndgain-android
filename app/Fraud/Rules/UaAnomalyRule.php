<?php

namespace App\Fraud\Rules;

use App\Enums\FraudStage;
use App\Fraud\Contracts\Rule;
use App\Fraud\FraudContext;
use App\Fraud\RuleResult;

/**
 * Missing, malformed, or known-bot user agent — docs/05 §3.
 */
class UaAnomalyRule implements Rule
{
    public static function key(): string
    {
        return 'ua_anomaly';
    }

    public static function stage(): FraudStage
    {
        return FraudStage::PreClick;
    }

    public function evaluate(FraudContext $context, array $config): RuleResult
    {
        $agent = trim((string) $context->userAgent);
        $minLength = (int) ($config['min_length'] ?? 20);

        /** @var list<string> $signatures */
        $signatures = array_values(array_filter(
            (array) ($config['bot_signatures'] ?? []),
            'is_string',
        ));

        $shared = [
            'user_agent' => $agent === '' ? null : $agent,
            'length' => strlen($agent),
            'min_length' => $minLength,
        ];

        if ($agent === '') {
            return RuleResult::trigger('user agent is missing', $shared + ['anomaly' => 'missing']);
        }

        // A real browser or app agent is never a handful of characters. This
        // is the cheap half of the check; the signature list is the specific
        // half and an operator extends it from the table.
        if (strlen($agent) < $minLength) {
            return RuleResult::trigger('user agent is too short to be real', $shared + ['anomaly' => 'malformed']);
        }

        $haystack = strtolower($agent);

        foreach ($signatures as $signature) {
            if ($signature !== '' && str_contains($haystack, strtolower($signature))) {
                return RuleResult::trigger('user agent matches a known bot signature', $shared + [
                    'anomaly' => 'bot_signature',
                    'matched_signature' => $signature,
                ]);
            }
        }

        return RuleResult::pass();
    }
}
