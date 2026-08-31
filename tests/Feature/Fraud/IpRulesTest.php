<?php

use App\Enums\FraudAction;
use App\Enums\FraudStage;
use App\Enums\ProxyType;
use App\Fraud\Contracts\RenderGateRule;
use App\Fraud\FraudPipeline;
use App\Fraud\IpVerdict;
use App\Fraud\RuleResult;
use App\Fraud\Rules\DatacenterIpRule;
use App\Fraud\Rules\IpRiskScoreRule;
use App\Fraud\Rules\ResidentialProxyIpRule;
use App\Fraud\Rules\TorIpRule;
use App\Fraud\Rules\VpnProxyIpRule;
use App\Models\FraudRule;

require_once __DIR__.'/FraudFixtures.php';

/**
 * Run one pre-click rule against one verdict and say whether it fired.
 *
 * A single `it()` calls this several times with the same key to probe
 * several verdicts, and `fraud_rules.key` is unique — so each call replaces
 * any row it left behind rather than colliding with it.
 */
function ipRuleFires(string $key, IpVerdict $verdict, array $config = []): bool
{
    $click = fraudClick();
    fakeIpVerdict($verdict);
    FraudRule::query()->where('key', $key)->delete();
    fraudRule($key, FraudStage::PreClick, FraudAction::Block, $config, weight: 10);

    return app(FraudPipeline::class)->evaluate(FraudStage::PreClick, fraudContext($click))->blocked;
}

it('fires tor_ip on Tor and on nothing else', function () {
    expect(ipRuleFires('tor_ip', new IpVerdict(isProxy: true, proxyType: ProxyType::Tor)))->toBeTrue()
        ->and(ipRuleFires('tor_ip', new IpVerdict(isProxy: true, proxyType: ProxyType::Vpn)))->toBeFalse()
        ->and(ipRuleFires('tor_ip', new IpVerdict))->toBeFalse();
});

it('fires residential_proxy_ip only on a rented home connection', function () {
    expect(ipRuleFires('residential_proxy_ip', new IpVerdict(isProxy: true, proxyType: ProxyType::Residential)))->toBeTrue()
        ->and(ipRuleFires('residential_proxy_ip', new IpVerdict(isProxy: true, proxyType: ProxyType::Vpn)))->toBeFalse();
});

it('narrows vpn_proxy_ip to the consumer classes', function () {
    expect(ipRuleFires('vpn_proxy_ip', new IpVerdict(isProxy: true, proxyType: ProxyType::Vpn)))->toBeTrue()
        ->and(ipRuleFires('vpn_proxy_ip', new IpVerdict(isProxy: true, proxyType: ProxyType::Public)))->toBeTrue()
        // Tor and residential have their own rules with their own weights now.
        ->and(ipRuleFires('vpn_proxy_ip', new IpVerdict(isProxy: true, proxyType: ProxyType::Tor)))->toBeFalse()
        ->and(ipRuleFires('vpn_proxy_ip', new IpVerdict(isProxy: true, proxyType: ProxyType::Residential)))->toBeFalse()
        // An unclassified proxy is still a proxy — the old boolean behaviour.
        ->and(ipRuleFires('vpn_proxy_ip', new IpVerdict(isProxy: true)))->toBeTrue();
});

it('fires datacenter_ip on a DCH proxy type no hosting ASN covers', function () {
    expect(ipRuleFires('datacenter_ip', new IpVerdict(asn: 4242, proxyType: ProxyType::Datacenter)))->toBeTrue()
        ->and(ipRuleFires('datacenter_ip', new IpVerdict(asn: 24940, isDatacenter: true)))->toBeTrue()
        ->and(ipRuleFires('datacenter_ip', new IpVerdict(asn: 4242)))->toBeFalse();
});

it('fires ip_risk_score at or above its threshold and never without a score', function () {
    expect(ipRuleFires('ip_risk_score', new IpVerdict(riskScore: 85), ['threshold' => 85]))->toBeTrue()
        ->and(ipRuleFires('ip_risk_score', new IpVerdict(riskScore: 84), ['threshold' => 85]))->toBeFalse()
        // Null is "nobody scored it", which is not zero and not clean.
        ->and(ipRuleFires('ip_risk_score', new IpVerdict, ['threshold' => 85]))->toBeFalse();
});

/**
 * Both halves matter and neither implies the other: `noticeKey()` staying
 * intact after `implements RenderGateRule` is deleted would leave this test
 * green while `WallGate::protectionBlock()`'s `instanceof RenderGateRule`
 * filter silently drops the rule from the render gate — `datacenter_ip` is
 * seeded `block` from day one, so it is the one most likely to be firing
 * when the marker falls off unnoticed.
 */
it('marks every IP-family rule as render-gate eligible with a notice key', function () {
    fraudBoot();

    $rules = [
        DatacenterIpRule::class => 'blocked_datacenter',
        VpnProxyIpRule::class => 'blocked_proxy',
        TorIpRule::class => 'blocked_proxy',
        ResidentialProxyIpRule::class => 'blocked_proxy',
        IpRiskScoreRule::class => 'blocked_risk',
    ];

    // A conclusive-match RuleResult for every rule here — VpnProxyIpRule's
    // own null-for-the-unclassified-fallback branch is covered separately in
    // RenderGateTest.php, where it can be exercised through a real verdict
    // rather than a hand-built context.
    $result = RuleResult::trigger('test', ['matched' => 'proxy_type']);

    foreach ($rules as $class => $notice) {
        expect(app($class))->toBeInstanceOf(RenderGateRule::class)
            ->and(app($class)->noticeKey($result))->toBe($notice);
    }
});

it('promotes a seeded vpn_proxy_ip still at flag to block/40', function () {
    fraudRule('vpn_proxy_ip', FraudStage::PreClick, FraudAction::Flag, weight: 20);

    (require database_path('migrations/2026_08_19_000003_promote_vpn_proxy_ip_to_block.php'))->up();

    $rule = FraudRule::query()->where('key', 'vpn_proxy_ip')->sole();

    expect($rule->action)->toBe(FraudAction::Block)
        ->and($rule->score_weight)->toBe(40);
});

it('leaves a vpn_proxy_ip an operator already moved off flag untouched', function () {
    fraudRule('vpn_proxy_ip', FraudStage::PreClick, FraudAction::Score, weight: 77);

    (require database_path('migrations/2026_08_19_000003_promote_vpn_proxy_ip_to_block.php'))->up();

    $rule = FraudRule::query()->where('key', 'vpn_proxy_ip')->sole();

    // Both columns, not just the action — a half-applied update is worse than
    // the migration refusing to run at all.
    expect($rule->action)->toBe(FraudAction::Score)
        ->and($rule->score_weight)->toBe(77);
});

it('reverses the promotion on down, back to flag/20', function () {
    fraudRule('vpn_proxy_ip', FraudStage::PreClick, FraudAction::Block, weight: 40);

    (require database_path('migrations/2026_08_19_000003_promote_vpn_proxy_ip_to_block.php'))->down();

    $rule = FraudRule::query()->where('key', 'vpn_proxy_ip')->sole();

    expect($rule->action)->toBe(FraudAction::Flag)
        ->and($rule->score_weight)->toBe(20);
});
