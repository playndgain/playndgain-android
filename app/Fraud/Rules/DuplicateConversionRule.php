<?php

namespace App\Fraud\Rules;

use App\Enums\FraudStage;
use App\Fraud\Contracts\Rule;
use App\Fraud\FraudContext;
use App\Fraud\RuleResult;
use App\Models\Conversion;

/**
 * Same network transaction ID — docs/05 §3.
 *
 * The `(network_id, network_txid)` unique index is what stops the second
 * payment, and it always will be: an `exists()` check loses the race between
 * two workers. This rule exists so a replay is *recorded* and explainable, and
 * it catches the case the index cannot — the same transaction ID arriving
 * under a different network row, which is what a re-integrated partner
 * replaying its history looks like.
 */
class DuplicateConversionRule implements Rule
{
    public static function key(): string
    {
        return 'duplicate_conversion';
    }

    public static function stage(): FraudStage
    {
        return FraudStage::PostConversion;
    }

    public function evaluate(FraudContext $context, array $config): RuleResult
    {
        $conversion = $context->conversion;

        // Nothing to compare against. In the inbound path the constraint has
        // already settled the duplicate before a conversion row exists.
        if ($conversion === null || blank($conversion->network_txid)) {
            return RuleResult::pass();
        }

        $twin = Conversion::query()
            ->withoutGlobalScopes()
            ->where('network_txid', $conversion->network_txid)
            ->whereKeyNot($conversion->getKey())
            ->first();

        if ($twin === null) {
            return RuleResult::pass();
        }

        return RuleResult::trigger('network transaction id already seen', [
            'network_txid' => $conversion->network_txid,
            'network_id' => $conversion->network_id,
            'original_conversion_id' => $twin->getKey(),
            'original_network_id' => $twin->network_id,
            'original_trans_id' => $twin->trans_id,
            'original_created_at' => $twin->created_at?->toIso8601String(),
        ]);
    }
}
