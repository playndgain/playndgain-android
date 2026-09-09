<?php

namespace App\Fraud\Rules;

use App\Enums\FraudStage;
use App\Fraud\Contracts\IpIntelligence;
use App\Fraud\Contracts\Rule;
use App\Fraud\FraudContext;
use App\Fraud\RuleResult;

/**
 * The conversion and the click came from different networks — the spec's §6A.4.
 *
 * `conversion_proxy_ip` catches a shared tracking URL completed over a VPN or
 * from a datacenter. It cannot catch the plainer version: the link passed to
 * somebody else on an ordinary home connection, where neither address is a
 * proxy and nothing about either one is suspicious on its own. Two different
 * people almost never share an ASN.
 *
 * **ASN, not the address.** A phone walking from wifi onto a carrier changes IP
 * legitimately and constantly, and a dual-stack client changes it inside one
 * session. Comparing addresses would flag ordinary mobile traffic all day. ASN
 * survives both.
 *
 * **Flags, never blocks.** Shared connections are real — a household, an
 * office, a campus — and so is a genuine carrier handover between two ASNs at
 * the moment of conversion. This is a signal worth a human's attention, not a
 * refusal; the agreed posture is that nothing statistical refuses a person.
 *
 * Inert until the GeoIP databases are on disk: with no ASN on either side it
 * passes, which is the same silent-inertness `conversion_geo_mismatch` already
 * has and for the same reason.
 */
class ConversionIpMismatchRule implements Rule
{
    public function __construct(private readonly IpIntelligence $intelligence) {}

    public static function key(): string
    {
        return 'conversion_ip_mismatch';
    }

    public static function stage(): FraudStage
    {
        return FraudStage::PostConversion;
    }

    public function evaluate(FraudContext $context, array $config): RuleResult
    {
        $conversionIp = $context->conversionIp;
        $clickIp = $context->click?->ip;

        // No postback address means no second party to compare against. The
        // click's own IP is not a substitute — it would compare the clicker
        // with themselves and pass every time.
        if (blank($conversionIp) || blank($clickIp) || $conversionIp === $clickIp) {
            return RuleResult::pass();
        }

        $clickAsn = $this->intelligence->inspect($clickIp)->asn;
        $conversionAsn = $this->intelligence->inspect($conversionIp)->asn;

        // One side unplaced is not a mismatch. A rule that fired on "unknown"
        // would flag every conversion on an install with no GeoIP data — which
        // is every install until the databases are provisioned.
        if ($clickAsn === null || $conversionAsn === null || $clickAsn === $conversionAsn) {
            return RuleResult::pass();
        }

        return RuleResult::trigger('conversion network differs from the click network', [
            'click_ip' => $clickIp,
            'conversion_ip' => $conversionIp,
            'click_asn' => $clickAsn,
            'conversion_asn' => $conversionAsn,
            // Not nullsafe: a non-blank $clickIp above already proves the click
            // is there, and PHPStan narrows it to non-null on that basis.
            'click_id' => $context->click->click_id,
        ]);
    }
}
