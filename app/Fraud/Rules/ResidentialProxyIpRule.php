<?php

namespace App\Fraud\Rules;

use App\Enums\FraudStage;
use App\Enums\ProxyType;
use App\Fraud\Contracts\IpIntelligence;
use App\Fraud\Contracts\RenderGateRule;
use App\Fraud\FraudContext;
use App\Fraud\RuleResult;

/**
 * Rented access to somebody else's home connection.
 *
 * The single most valuable signal this platform can read, and until now it
 * scored 20 alongside a consumer VPN. A residential proxy is not a privacy
 * tool; it is the standard transport for an install farm, precisely because it
 * defeats both the hosting-ASN list and a country check at once. The user
 * whose connection is being rented is usually unaware of it.
 */
class ResidentialProxyIpRule implements RenderGateRule
{
    public function __construct(private readonly IpIntelligence $intelligence) {}

    public static function key(): string
    {
        return 'residential_proxy_ip';
    }

    public static function stage(): FraudStage
    {
        return FraudStage::PreClick;
    }

    /** A residential-proxy match is always conclusive — no fallback path to withhold a render for. */
    public function noticeKey(RuleResult $result): ?string
    {
        return 'blocked_proxy';
    }

    public function evaluate(FraudContext $context, array $config): RuleResult
    {
        if (blank($context->ip)) {
            return RuleResult::pass();
        }

        // Reuse the verdict the render gate already resolved for this
        // request rather than asking again — see FraudContext::$verdict.
        $verdict = $context->verdict ?? $this->intelligence->inspect($context->ip);

        if ($verdict->proxyType !== ProxyType::Residential) {
            return RuleResult::pass();
        }

        return RuleResult::trigger('click originates from a residential proxy', [
            'ip' => $context->ip,
            'asn' => $verdict->asn,
            'organization' => $verdict->organization,
            'country' => $verdict->country,
            'source' => $verdict->source,
        ]);
    }
}
