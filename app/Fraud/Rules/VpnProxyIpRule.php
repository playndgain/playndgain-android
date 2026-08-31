<?php

namespace App\Fraud\Rules;

use App\Enums\FraudStage;
use App\Enums\ProxyType;
use App\Fraud\Contracts\IpIntelligence;
use App\Fraud\Contracts\RenderGateRule;
use App\Fraud\FraudContext;
use App\Fraud\RuleResult;

/**
 * Consumer VPN and unclassified proxy exit nodes.
 *
 * Tor and residential proxies used to land here too and scored the same 20.
 * They now have their own rules, because they are not the same thing: a
 * consumer VPN is often a privacy-conscious real person, while a residential
 * proxy is rented access to a stranger's connection.
 *
 * This rule blocks at click for both an explicit consumer class and the
 * unclassified fallback, but only the explicit class blocks *render* — see
 * `noticeKey()`. Blocking render for an explicit class reverses the original
 * default and the reversal is deliberate: it is not the same refusal as
 * blocking at click. The user is told they are on a VPN, the fix is one
 * toggle, and they come back and convert. A flagged VPN conversion is paid
 * out and then charged back. The unclassified fallback does not get that
 * same treatment: `isProxy` true with no `proxyType` is what a stale database
 * or a terse provider returns — and, concretely, what `ProxyCheckProvider`
 * produces for `type: "Business"`, since `normaliseType()` deliberately
 * leaves a corporate ISP allocation unmapped. Refusing a render on that shape
 * means the fallback meant to catch a stale record instead shows an office
 * worker a screen telling them to turn off a VPN they do not have.
 */
class VpnProxyIpRule implements RenderGateRule
{
    private const CONSUMER = [
        ProxyType::Vpn,
        ProxyType::Public,
        ProxyType::Web,
        ProxyType::Search,
        ProxyType::Consumer,
    ];

    public function __construct(private readonly IpIntelligence $intelligence) {}

    public static function key(): string
    {
        return 'vpn_proxy_ip';
    }

    public static function stage(): FraudStage
    {
        return FraudStage::PreClick;
    }

    /**
     * Null for the unclassified fallback: the trigger is real (it still
     * writes a fraud_event and still flags/blocks the click, per
     * `evaluate()`) but too weak on its own to refuse a render for — see the
     * class docblock. `evaluate()` records which branch fired in `matched`,
     * read back here rather than re-derived, because only `evaluate()` saw
     * the verdict that decided it.
     */
    public function noticeKey(RuleResult $result): ?string
    {
        return ($result->context['matched'] ?? null) === 'unclassified' ? null : 'blocked_proxy';
    }

    public function evaluate(FraudContext $context, array $config): RuleResult
    {
        if (blank($context->ip)) {
            return RuleResult::pass();
        }

        // Reuse the verdict the render gate already resolved for this
        // request rather than asking again — see FraudContext::$verdict.
        $verdict = $context->verdict ?? $this->intelligence->inspect($context->ip);

        // An unclassified proxy still counts: `isProxy` with no type is what a
        // stale database or a terse provider returns, and it is the behaviour
        // this rule had before the classes existed.
        $unclassified = $verdict->proxyType === null;
        $consumer = $unclassified
            ? $verdict->isProxy
            : in_array($verdict->proxyType, self::CONSUMER, true);

        if (! $consumer) {
            return RuleResult::pass();
        }

        return RuleResult::trigger('click originates from a known proxy or VPN exit node', [
            'ip' => $context->ip,
            'asn' => $verdict->asn,
            'organization' => $verdict->organization,
            'country' => $verdict->country,
            'provider' => $config['provider'] ?? null,
            'proxy_type' => $verdict->proxyType?->value,
            'source' => $verdict->source,
            // Read by noticeKey() to decide whether this trigger is
            // render-worthy — mirrors how DatacenterIpRule reports which of
            // its own conditions matched.
            'matched' => $unclassified ? 'unclassified' : 'proxy_type',
        ]);
    }
}
