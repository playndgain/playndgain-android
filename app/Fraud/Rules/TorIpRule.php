<?php

namespace App\Fraud\Rules;

use App\Enums\FraudStage;
use App\Enums\ProxyType;
use App\Fraud\Contracts\IpIntelligence;
use App\Fraud\Contracts\RenderGateRule;
use App\Fraud\FraudContext;
use App\Fraud\RuleResult;

/**
 * Tor exit node.
 *
 * Split out of `vpn_proxy_ip`, which scored it the same as a consumer VPN.
 * Nobody completes a mobile install offer over Tor by accident: the latency
 * makes it painful and the anonymity is the only reason to accept the pain.
 */
class TorIpRule implements RenderGateRule
{
    public function __construct(private readonly IpIntelligence $intelligence) {}

    public static function key(): string
    {
        return 'tor_ip';
    }

    public static function stage(): FraudStage
    {
        return FraudStage::PreClick;
    }

    /** A Tor exit match is always conclusive — no fallback path to withhold a render for. */
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

        if ($verdict->proxyType !== ProxyType::Tor) {
            return RuleResult::pass();
        }

        return RuleResult::trigger('click originates from a Tor exit node', [
            'ip' => $context->ip,
            'asn' => $verdict->asn,
            'organization' => $verdict->organization,
            'country' => $verdict->country,
            'source' => $verdict->source,
        ]);
    }
}
