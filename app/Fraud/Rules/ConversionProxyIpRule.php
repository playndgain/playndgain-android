<?php

namespace App\Fraud\Rules;

use App\Enums\FraudStage;
use App\Fraud\Contracts\IpIntelligence;
use App\Fraud\Contracts\Rule;
use App\Fraud\FraudContext;
use App\Fraud\RuleResult;

/**
 * The conversion's own IP is a proxy, VPN, Tor exit, datacenter or residential
 * proxy — the spec's §6A.4.
 *
 * This is the answer to the shared-tracking-URL hole. After the click redirect
 * the user is on the network's domain with our `click_id` in the address bar,
 * and that URL can be copied and completed by anybody. Every `PreClick` IP rule
 * inspected the person who clicked; none of them ever sees the person who
 * completes. The postback is the only place that person is visible.
 *
 * One rule rather than a post-conversion copy of the four `PreClick` classes:
 * at render those are separately tunable because each one refuses a wall for a
 * different reason and shows a different screen. Here there is no screen and no
 * user to tell — it is a single yes/no about an address that has already
 * finished earning, so splitting it four ways would buy four rows of admin
 * surface and no extra decision.
 */
class ConversionProxyIpRule implements Rule
{
    public function __construct(private readonly IpIntelligence $intelligence) {}

    public static function key(): string
    {
        return 'conversion_proxy_ip';
    }

    public static function stage(): FraudStage
    {
        return FraudStage::PostConversion;
    }

    public function evaluate(FraudContext $context, array $config): RuleResult
    {
        // Deliberately not `$context->ip`. That falls back to the click's
        // address when the network sent none, and re-inspecting the clicker
        // here would re-block a click that already passed on a signal that says
        // nothing at all about who converted. See FraudContext::$conversionIp.
        $ip = $context->conversionIp;

        if (blank($ip)) {
            return RuleResult::pass();
        }

        $verdict = $this->intelligence->inspect($ip);

        if (! $verdict->isProxy && ! $verdict->isDatacenter) {
            return RuleResult::pass();
        }

        return RuleResult::trigger('conversion arrived from a proxy or datacenter address', [
            'conversion_ip' => $ip,
            'click_ip' => $context->click?->ip,
            'proxy_type' => $verdict->proxyType?->value,
            'is_datacenter' => $verdict->isDatacenter,
            'asn' => $verdict->asn,
            'organization' => $verdict->organization,
            'country' => $verdict->country,
            'source' => $verdict->source,
            'click_id' => $context->click?->click_id,
        ]);
    }
}
