<?php

namespace App\Fraud\Rules;

use App\Enums\FraudStage;
use App\Fraud\Contracts\Rule;
use App\Fraud\FraudContext;
use App\Fraud\RuleResult;
use App\Fraud\Rules\Concerns\CountsConversions;

/**
 * Same end user completing a non-multi-reward offer twice — docs/05 §3.
 */
class RepeatOfferCompletionRule implements Rule
{
    use CountsConversions;

    public static function key(): string
    {
        return 'repeat_offer_completion';
    }

    public static function stage(): FraudStage
    {
        return FraudStage::PostConversion;
    }

    public function evaluate(FraudContext $context, array $config): RuleResult
    {
        $offer = $context->offer;
        $offer ??= $context->click?->offer;
        $offer ??= $context->conversion?->offer;

        if ($offer === null) {
            return RuleResult::pass();
        }

        // A multi-reward offer pays out per event by design. Counting the
        // second event as fraud would reject every level-up on it.
        if ($offer->is_multi_reward) {
            return RuleResult::pass();
        }

        // Config-driven so an operator can allow a second completion on an
        // offer type the network genuinely re-fires, without a deploy.
        $max = (int) ($config['max'] ?? 1);

        $previous = $this->countable($context)
            ->where('publisher_id', $context->publisherId())
            ->where('offer_id', $offer->getKey())
            ->where('end_user_id', $context->endUserId)
            ->count();

        if ($previous < $max) {
            return RuleResult::pass();
        }

        return RuleResult::trigger('end user already completed this single-reward offer', [
            'offer_id' => $offer->getKey(),
            'offer_name' => $offer->name,
            'is_multi_reward' => false,
            'previous_completions' => $previous,
            'max' => $max,
            'publisher_id' => $context->publisherId(),
            'end_user_id' => $context->endUserId,
        ]);
    }
}
