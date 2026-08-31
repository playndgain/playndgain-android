<?php

namespace App\Fraud\Rules;

use App\Enums\FraudStage;
use App\Fraud\Contracts\Rule;
use App\Fraud\FraudContext;
use App\Fraud\RuleResult;

/**
 * Conversion with no resolvable click — docs/05 §3.
 *
 * Flags rather than blocks: a truncated subid is a network's bug, not the
 * publisher's fraud, and the review queue is where a human decides which one
 * it was.
 */
class NoClickMatchRule implements Rule
{
    public static function key(): string
    {
        return 'no_click_match';
    }

    public static function stage(): FraudStage
    {
        return FraudStage::PostConversion;
    }

    public function evaluate(FraudContext $context, array $config): RuleResult
    {
        if ($context->click !== null) {
            return RuleResult::pass();
        }

        $conversion = $context->conversion;

        return RuleResult::trigger('conversion has no resolvable click', [
            'click_id' => $conversion?->click_id,
            'network_txid' => $conversion?->network_txid,
            'network_id' => $conversion?->network_id,
            'end_user_id' => $context->endUserId,
            'ip' => $context->ip,
        ]);
    }
}
