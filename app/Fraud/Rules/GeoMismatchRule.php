<?php

namespace App\Fraud\Rules;

use App\Enums\FraudStage;
use App\Fraud\Contracts\IpIntelligence;
use App\Fraud\Contracts\Rule;
use App\Fraud\FraudContext;
use App\Fraud\RuleResult;

/**
 * Resolved country not in the offer's targeting — docs/05 §3.
 */
class GeoMismatchRule implements Rule
{
    public function __construct(private readonly IpIntelligence $intelligence) {}

    public static function key(): string
    {
        return 'geo_mismatch';
    }

    public static function stage(): FraudStage
    {
        return FraudStage::PreClick;
    }

    public function evaluate(FraudContext $context, array $config): RuleResult
    {
        $offer = $context->offer;

        if ($offer === null) {
            return RuleResult::pass();
        }

        $targets = array_map(strtoupper(...), array_filter(
            (array) ($offer->countries ?? []),
            'is_string',
        ));

        // Empty targeting is worldwide, not "nowhere".
        if ($targets === []) {
            return RuleResult::pass();
        }

        $country = $this->country($context);

        // An address the databases cannot place is not evidence of a mismatch.
        // Blocking on unknown would block every click while GeoLite2 is
        // unprovisioned; the eligibility filter is what fails closed on an
        // unplaced address, by only serving worldwide offers — see
        // NullIpIntelligence.
        if ($country === null) {
            return RuleResult::pass();
        }

        if (in_array($country, $targets, true)) {
            return RuleResult::pass();
        }

        return RuleResult::trigger('resolved country is outside the offer targeting', [
            'ip' => $context->ip,
            'resolved_country' => $country,
            'offer_id' => $offer->getKey(),
            'offer_countries' => array_values($targets),
        ]);
    }

    private function country(FraudContext $context): ?string
    {
        if (filled($context->country)) {
            return strtoupper($context->country);
        }

        return blank($context->ip)
            ? null
            : $this->intelligence->inspect($context->ip)->country;
    }
}
