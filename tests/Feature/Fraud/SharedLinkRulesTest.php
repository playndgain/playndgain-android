<?php

use App\Enums\FraudAction;
use App\Enums\FraudStage;
use App\Enums\ProxyType;
use App\Fraud\FraudPipeline;
use App\Fraud\IpVerdict;
use App\Models\FraudEvent;
use App\Models\FraudRule;

require_once __DIR__.'/FraudFixtures.php';

/**
 * The shared-tracking-URL rules — spec §6A.
 *
 * After the click redirect the network's URL sits in the address bar with our
 * click_id in it, and anyone it is passed to completes the offer without ever
 * passing our gate. These two rules are the only place the person who actually
 * converted is visible.
 */

/** Run one post-conversion rule and say whether the decision blocked. */
function conversionRuleBlocks(string $key, array $verdicts, ?string $conversionIp, string $clickIp = '203.0.113.10'): bool
{
    $click = fraudClick(['ip' => $clickIp]);
    fakeIpVerdicts($verdicts);
    FraudRule::query()->where('key', $key)->delete();
    fraudRule($key, FraudStage::PostConversion, FraudAction::Block, weight: 10);

    return app(FraudPipeline::class)
        ->evaluate(FraudStage::PostConversion, fraudContext($click, conversionIp: $conversionIp))
        ->blocked;
}

it('blocks a conversion that arrived from a proxy', function () {
    $blocked = conversionRuleBlocks(
        'conversion_proxy_ip',
        ['198.51.100.7' => new IpVerdict(isProxy: true, proxyType: ProxyType::Vpn)],
        conversionIp: '198.51.100.7',
    );

    expect($blocked)->toBeTrue();
});

it('blocks a conversion that arrived from a datacenter', function () {
    $blocked = conversionRuleBlocks(
        'conversion_proxy_ip',
        ['198.51.100.7' => new IpVerdict(isDatacenter: true, asn: 16509)],
        conversionIp: '198.51.100.7',
    );

    expect($blocked)->toBeTrue();
});

it('leaves a conversion from an ordinary address alone', function () {
    $blocked = conversionRuleBlocks(
        'conversion_proxy_ip',
        ['198.51.100.7' => new IpVerdict(country: 'US', asn: 7922)],
        conversionIp: '198.51.100.7',
    );

    expect($blocked)->toBeFalse();
});

/**
 * The reason `FraudContext::$conversionIp` exists. `ConversionProcessor` resolves
 * `$dto->ip ?? $click->ip` for the context's `$ip`, so a rule reading that would
 * re-inspect the clicker whenever the network sent no address — re-blocking a
 * click that already passed, on evidence about the wrong person.
 */
it('never falls back to the click address when the network sent none', function () {
    $blocked = conversionRuleBlocks(
        'conversion_proxy_ip',
        ['203.0.113.10' => new IpVerdict(isProxy: true, proxyType: ProxyType::Vpn)],
        conversionIp: null,
        clickIp: '203.0.113.10',
    );

    expect($blocked)->toBeFalse();
});

it('flags a conversion from a different network than the click', function () {
    $blocked = conversionRuleBlocks(
        'conversion_ip_mismatch',
        [
            '203.0.113.10' => new IpVerdict(country: 'US', asn: 7922),
            '198.51.100.7' => new IpVerdict(country: 'US', asn: 20115),
        ],
        conversionIp: '198.51.100.7',
    );

    expect($blocked)->toBeTrue();
});

/**
 * A phone walking from wifi onto a carrier changes address legitimately, and a
 * dual-stack client changes it inside one session. Comparing addresses rather
 * than networks would flag ordinary mobile traffic all day.
 */
it('ignores a changed address that stayed on the same network', function () {
    $blocked = conversionRuleBlocks(
        'conversion_ip_mismatch',
        [
            '203.0.113.10' => new IpVerdict(country: 'US', asn: 7922),
            '198.51.100.7' => new IpVerdict(country: 'US', asn: 7922),
        ],
        conversionIp: '198.51.100.7',
    );

    expect($blocked)->toBeFalse();
});

/** Inert until the GeoIP databases are provisioned, rather than firing on every conversion. */
it('passes when either side has no resolvable network', function () {
    $blocked = conversionRuleBlocks(
        'conversion_ip_mismatch',
        ['203.0.113.10' => new IpVerdict(country: 'US', asn: 7922)],
        conversionIp: '198.51.100.7',
    );

    expect($blocked)->toBeFalse();
});

it('records the two addresses it compared so the block is explainable', function () {
    conversionRuleBlocks(
        'conversion_proxy_ip',
        ['198.51.100.7' => new IpVerdict(isProxy: true, proxyType: ProxyType::Residential, asn: 4134)],
        conversionIp: '198.51.100.7',
    );

    $event = FraudEvent::query()->where('rule_key', 'conversion_proxy_ip')->sole();

    expect($event->context['conversion_ip'])->toBe('198.51.100.7')
        ->and($event->context['click_ip'])->toBe('203.0.113.10')
        ->and($event->context['proxy_type'])->toBe('RES');
});

/**
 * `FraudContext::with()` rebuilds via `new self(...[...get_object_vars($this),
 * ...$overrides])`, so a field it forgets becomes a silent null rather than an
 * error — and a silently-null `conversionIp` turns both post-conversion IP
 * rules off without anything failing. Cheap to assert, expensive to notice.
 */
it('carries the conversion address through a context copy', function () {
    $click = fraudClick(['ip' => '203.0.113.10']);
    $context = fraudContext($click, conversionIp: '198.51.100.7');

    expect($context->conversionIp)->toBe('198.51.100.7')
        ->and($context->with(payout: '1.00')->conversionIp)->toBe('198.51.100.7')
        ->and($context->with(conversionIp: '198.51.100.8')->conversionIp)->toBe('198.51.100.8');
});
