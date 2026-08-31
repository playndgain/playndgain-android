<?php

namespace App\Fraud\Rules;

use App\Enums\FraudStage;
use App\Fraud\Contracts\Rule;
use App\Fraud\FraudContext;
use App\Fraud\RuleResult;

/**
 * Conversion IP country differs from the click IP country — docs/05 §3.
 *
 * Flags rather than blocks: a phone that walked from wifi onto a carrier, or a
 * traveller, produces this legitimately. A farm clicking in one country and
 * converting from another produces it constantly.
 */
class ConversionGeoMismatchRule implements Rule
{
    public static function key(): string
    {
        return 'conversion_geo_mismatch';
    }

    public static function stage(): FraudStage
    {
        return FraudStage::PostConversion;
    }

    public function evaluate(FraudContext $context, array $config): RuleResult
    {
        $conversion = $context->conversion;
        $conversionIp = $conversion?->ip;

        $clickCountry = $this->normalize($context->click?->country);
        $conversionCountry = $this->normalize($conversion?->country) ?? $this->normalize($context->country);

        // One side unplaced is not a mismatch. Until GeoLite2 is on disk the
        // conversion side is always null and this rule is inert — see the
        // report; a rule that fires on "unknown" would flag every conversion.
        if ($clickCountry === null || $conversionCountry === null) {
            return RuleResult::pass();
        }

        if ($clickCountry === $conversionCountry) {
            return RuleResult::pass();
        }

        return RuleResult::trigger('conversion country differs from the click country', [
            'click_country' => $clickCountry,
            'conversion_country' => $conversionCountry,
            'click_ip' => $context->click?->ip,
            'conversion_ip' => $conversionIp ?? $context->ip,
            'click_id' => $context->click?->click_id,
        ]);
    }

    private function normalize(?string $country): ?string
    {
        return blank($country) ? null : strtoupper($country);
    }
}
