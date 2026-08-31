<?php

namespace App\Fraud\Rules;

use App\Enums\FraudStage;
use App\Enums\ProxyType;
use App\Fraud\Contracts\IpIntelligence;
use App\Fraud\Contracts\RenderGateRule;
use App\Fraud\FraudContext;
use App\Fraud\RuleResult;

/**
 * Click from AWS/GCP/Azure/OVH/Hetzner — docs/05 §3.
 *
 * Both this rule and `vpn_proxy_ip` block, and the split between them is by
 * what they catch, not by how hard they hit: this one catches hosting
 * infrastructure, `vpn_proxy_ip` catches a person behind a tunnel.
 */
class DatacenterIpRule implements RenderGateRule
{
    public function __construct(private readonly IpIntelligence $intelligence) {}

    public static function key(): string
    {
        return 'datacenter_ip';
    }

    public static function stage(): FraudStage
    {
        return FraudStage::PreClick;
    }

    /** Every trigger here is conclusive — no fallback path to withhold a render for. */
    public function noticeKey(RuleResult $result): ?string
    {
        return 'blocked_datacenter';
    }

    public function evaluate(FraudContext $context, array $config): RuleResult
    {
        if (blank($context->ip)) {
            return RuleResult::pass();
        }

        // Reuse the verdict the render gate already resolved for this
        // request rather than asking again — see FraudContext::$verdict.
        $verdict = $context->verdict ?? $this->intelligence->inspect($context->ip);

        // The curated hosting list lives in the IP intelligence layer, but an
        // operator watching a new hosting range light up needs to add it
        // without a deploy — so `config.asns` extends the list from the table.
        $extra = array_map(
            intval(...),
            array_values(array_filter((array) ($config['asns'] ?? []), 'is_numeric')),
        );

        $fromConfig = $verdict->asn !== null && in_array($verdict->asn, $extra, true);

        // IP2Proxy classifies datacenter addresses whose ASN nobody thought to
        // add to the curated list, and the curated list covers ranges IP2Proxy
        // has not seen behaving as a proxy. Neither subsumes the other.
        $fromProxyType = $verdict->proxyType === ProxyType::Datacenter;

        if (! $verdict->isDatacenter && ! $fromConfig && ! $fromProxyType) {
            return RuleResult::pass();
        }

        return RuleResult::trigger('click originates from a hosting provider', [
            'ip' => $context->ip,
            'asn' => $verdict->asn,
            'organization' => $verdict->organization,
            'country' => $verdict->country,
            'matched' => match (true) {
                $fromConfig => 'config_asns',
                $verdict->isDatacenter => 'hosting_list',
                default => 'proxy_type',
            },
            'source' => $config['source'] ?? null,
        ]);
    }
}
