<?php

use App\Enums\ProxyType;
use App\Fraud\ChainedIpIntelligence;
use App\Fraud\Contracts\IpIntelligence;
use App\Fraud\IpVerdict;
use App\Fraud\Providers\IpProviderRegistry;
use App\Models\IpProvider;
use App\Models\Setting;
use App\Providers\FraudServiceProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** A chain whose local half returns exactly what the test says. */
function chain(IpVerdict $local): ChainedIpIntelligence
{
    app()->register(FraudServiceProvider::class);

    $reader = new class($local) implements IpIntelligence
    {
        public function __construct(private readonly IpVerdict $verdict) {}

        public function inspect(string $ip, bool $consented = false): IpVerdict
        {
            return $this->verdict;
        }
    };

    return new ChainedIpIntelligence($reader, app(IpProviderRegistry::class));
}

function chainProvider(array $attributes = []): IpProvider
{
    return IpProvider::query()->create([
        'key' => 'proxycheck', 'name' => 'ProxyCheck.io',
        'enabled' => true, 'api_key' => 'k', ...$attributes,
    ]);
}

beforeEach(fn () => Cache::flush());

it('does not escalate when the local databases already answered', function () {
    Http::fake();
    chainProvider();

    $verdict = chain(new IpVerdict(country: 'US', isProxy: true, proxyType: ProxyType::Tor))->inspect('1.2.3.4');

    expect($verdict->proxyType)->toBe(ProxyType::Tor)
        ->and($verdict->source)->toBe('local');

    Http::assertNothingSent();
});

it('escalates when the local verdict looks clean and merges the answer', function () {
    Http::fake(['proxycheck.io/*' => Http::response([
        // `isocode` GB alongside a US local country is what makes this
        // discriminate merge direction: the correct order keeps the local
        // country, a reversed merge would let GB win instead.
        'status' => 'ok', '1.2.3.4' => ['proxy' => 'yes', 'type' => 'Residential', 'risk' => 88, 'isocode' => 'GB'],
    ])]);
    chainProvider();

    $verdict = chain(new IpVerdict(country: 'US'))->inspect('1.2.3.4');

    expect($verdict->isProxy)->toBeTrue()
        ->and($verdict->proxyType)->toBe(ProxyType::Residential)
        ->and($verdict->riskScore)->toBe(88)
        ->and($verdict->country)->toBe('US');
});

it('never lets a provider clear a local positive', function () {
    // A direct merge() call, not a round trip through the chain: Tor and
    // Datacenter are both conclusive on their own (ChainedIpIntelligence
    // never escalates past a local hit that isn't PUB or clean), so routing
    // this through inspect() would never call merge() at all — the same
    // vacuous-test failure this case exists to catch. PUB is deliberately
    // avoided too: since the merge() PUB exception (see IpVerdict::merge()),
    // it is no longer a "positive that must survive" case but the one case
    // that is supposed to be replaced — covered separately below.
    $local = new IpVerdict(country: 'US', isProxy: true, proxyType: ProxyType::Tor);
    $answer = new IpVerdict(country: 'US', isProxy: false, proxyType: ProxyType::Vpn, riskScore: 2);

    $merged = $local->merge($answer, 'proxycheck');

    expect($merged->isProxy)->toBeTrue()
        ->and($merged->proxyType)->toBe(ProxyType::Tor);
});

it('lets a provider replace a local PUB with a more specific class', function () {
    // PUB is IP2Proxy's vaguest class and the one ChainedIpIntelligence
    // escalates on specifically to refine — the exception documented on
    // IpVerdict::merge().
    $local = new IpVerdict(country: 'US', isProxy: true, proxyType: ProxyType::Public);
    $answer = new IpVerdict(country: 'US', proxyType: ProxyType::Vpn);

    expect($local->merge($answer, 'proxycheck')->proxyType)->toBe(ProxyType::Vpn);
});

it('degrades on a provider failure and caches it for minutes not hours', function () {
    Http::fake(['proxycheck.io/*' => Http::response('', 500)]);
    chainProvider();

    $verdict = chain(new IpVerdict(country: 'US'))->inspect('1.2.3.4');

    expect($verdict->degraded)->toBeTrue()
        ->and($verdict->country)->toBe('US')
        ->and(Cache::get('ip:v2:1.2.3.4'))->toEqual($verdict);

    // Five minutes, so one timeout cannot blind the paid layer for an hour —
    // asserted by actually outliving DEGRADED_TTL (300s), not merely by
    // reading the entry back before it could have expired either way.
    $this->travel(6)->minutes();
    expect(Cache::get('ip:v2:1.2.3.4'))->toBeNull();
});

it('rebuilds an expired merged verdict from the provider layer without paying again', function () {
    Http::fake(['proxycheck.io/*' => Http::response([
        'status' => 'ok', '1.2.3.4' => ['proxy' => 'yes', 'type' => 'VPN'],
    ])]);
    chainProvider();

    chain(new IpVerdict(country: 'US'))->inspect('1.2.3.4');
    Cache::forget('ip:v2:1.2.3.4');

    $verdict = chain(new IpVerdict(country: 'US'))->inspect('1.2.3.4');

    expect($verdict->proxyType)->toBe(ProxyType::Vpn);
    Http::assertSentCount(1);
});

it('refuses to send an unconsented consent-country address to a provider', function () {
    Http::fake();
    chainProvider();
    Setting::set('consent_required_countries', json_encode(['DE']));

    $verdict = chain(new IpVerdict(country: 'DE'))->inspect('1.2.3.4');

    expect($verdict->degraded)->toBeTrue()
        ->and($verdict->source)->toBe('local');

    Http::assertNothingSent();
});

it('escalates the same address once consent is given', function () {
    Http::fake(['proxycheck.io/*' => Http::response([
        'status' => 'ok', '1.2.3.4' => ['proxy' => 'yes', 'type' => 'VPN'],
    ])]);
    chainProvider();
    Setting::set('consent_required_countries', json_encode(['DE']));

    $chain = chain(new IpVerdict(country: 'DE'));
    $chain->inspect('1.2.3.4');
    $verdict = $chain->inspect('1.2.3.4', consented: true);

    expect($verdict->proxyType)->toBe(ProxyType::Vpn)
        ->and($verdict->degraded)->toBeFalse();
});

it('escalates an unplaced address, which is the case it is most worth it for', function () {
    Http::fake(['proxycheck.io/*' => Http::response([
        'status' => 'ok', '1.2.3.4' => ['proxy' => 'no', 'isocode' => 'EG'],
    ])]);
    chainProvider();
    Setting::set('consent_required_countries', json_encode(['DE']));

    expect(chain(IpVerdict::unknown())->inspect('1.2.3.4')->country)->toBe('EG');
});

it('does not degrade a clean verdict when no provider is configured at all', function () {
    Http::fake();
    // No IpProvider row at all — nothing to retry, so this must cache clean
    // for the full hour rather than the 300s degraded TTL.

    $verdict = chain(new IpVerdict(country: 'US'))->inspect('1.2.3.4');

    expect($verdict->degraded)->toBeFalse();
    Http::assertNothingSent();
});

it('degrades when every enabled provider is currently braked or capped', function () {
    Http::fake();
    // Breaker already open: circuit_opened_at plus the default 10-minute
    // cooldown is still in the future, so IpProvider::active() excludes this
    // row and the foreach never runs — the un-degraded-verdict bug this
    // guards against.
    chainProvider(['circuit_opened_at' => now()]);

    $verdict = chain(new IpVerdict(country: 'US'))->inspect('1.2.3.4');

    expect($verdict->degraded)->toBeTrue()
        ->and($verdict->country)->toBe('US');
    Http::assertNothingSent();
});
