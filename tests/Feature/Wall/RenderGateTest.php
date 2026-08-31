<?php

use App\Enums\FraudAction;
use App\Enums\FraudStage;
use App\Enums\ProxyType;
use App\Fraud\ChainedIpIntelligence;
use App\Fraud\Contracts\IpIntelligence;
use App\Fraud\IpVerdict;
use App\Fraud\Providers\IpProviderRegistry;
use App\Fraud\Providers\ProxyCheckProvider;
use App\Models\Consent;
use App\Models\FraudEvent;
use App\Models\FraudRule;
use App\Models\IpProvider;
use App\Providers\FraudServiceProvider;
use App\Services\Wall\WallGate;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/WallFixtures.php';

// `wallGet`, `wallClick`, `wallFixture`, `wallOffer` and `wallConsentCountries`
// all already live in WallFixtures.php — use them. `wallGet` in particular
// carries a real device User-Agent, which the wall filters on.

function gateVerdict(IpVerdict $verdict): void
{
    app()->register(FraudServiceProvider::class);

    app()->instance(IpIntelligence::class, new class($verdict) implements IpIntelligence
    {
        public function __construct(private readonly IpVerdict $verdict) {}

        public function inspect(string $ip, bool $consented = false): IpVerdict
        {
            return $this->verdict;
        }
    });
}

/**
 * @param  array<string, mixed>  $config
 */
function gateRule(string $key, FraudAction $action = FraudAction::Block, array $config = []): FraudRule
{
    return FraudRule::query()->create([
        'key' => $key,
        'name' => $key,
        'description' => $key,
        'stage' => FraudStage::PreClick,
        'enabled' => true,
        'action' => $action,
        'score_weight' => 40,
        'config' => $config,
    ]);
}

it('refuses the wall to a VPN and says so in a way the user can act on', function () {
    [$app] = wallFixture();
    gateVerdict(new IpVerdict(country: 'US', isProxy: true, proxyType: ProxyType::Vpn));
    gateRule('vpn_proxy_ip');

    $response = wallGet($app);

    $response->assertForbidden()
        ->assertSee(__('wall.notice.blocked_proxy.title'))
        // Never the rule key, never the ASN, never the provider.
        ->assertDontSee('vpn_proxy_ip');

    expect(FraudEvent::query()->where('rule_key', 'vpn_proxy_ip')->count())->toBe(1);
});

it('blocks every reload of the same address but writes only one audit row per hour', function () {
    [$app] = wallFixture();
    gateVerdict(new IpVerdict(country: 'US', isProxy: true, proxyType: ProxyType::Vpn));
    gateRule('vpn_proxy_ip');

    wallGet($app)->assertForbidden();
    wallGet($app)->assertForbidden();

    expect(FraudEvent::query()->where('rule_key', 'vpn_proxy_ip')->count())->toBe(1);
});

it('does not conflate two blocked events whose end user id and IP differ only in where a colon falls', function () {
    [$app] = wallFixture();
    gateVerdict(new IpVerdict(country: 'US', isProxy: true, proxyType: ProxyType::Vpn));
    gateRule('vpn_proxy_ip');

    // Both `endUserId` (colons are a legal character — WallGate::endUserId's
    // own regex allows them) and an IPv6 `ip` contain `:`, so naive
    // `endUserId:ip` concatenation is ambiguous: "ab:2001" + "db8::1" and
    // "ab" + "2001:db8::1" join to the identical string.
    test()->withServerVariables(['REMOTE_ADDR' => 'db8::1'])
        ->withHeaders(['User-Agent' => WALL_UA])
        ->get(wallUrl($app, 'ab:2001'))
        ->assertForbidden();

    test()->withServerVariables(['REMOTE_ADDR' => '2001:db8::1'])
        ->withHeaders(['User-Agent' => WALL_UA])
        ->get(wallUrl($app, 'ab'))
        ->assertForbidden();

    expect(FraudEvent::query()->where('rule_key', 'vpn_proxy_ip')->count())->toBe(2);
});

/**
 * Fix 2: `VpnProxyIpRule`'s unclassified fallback ("proxyType === null" but
 * `isProxy` true) is real vendor behaviour, not a hypothetical — this is what
 * `ProxyCheckProvider::parse()` actually produces for a `type: "Business"`
 * response, because `normaliseType()` deliberately leaves Business unmapped
 * (a corporate ISP allocation, not hosting) while `isProxy` is read from the
 * vendor's own boolean independently of `type`. Before the fix, that shape
 * fell through the fallback into a render block — an office worker shown the
 * "disable your VPN" screen for a VPN they do not have. The fallback is worth
 * keeping at click time (see the click-time assertion in IpRulesTest.php);
 * it must not refuse a render.
 */
it('does not refuse the render for a ProxyCheck "Business" verdict, but still writes a fraud_event', function () {
    [$app] = wallFixture();

    Http::fake(['proxycheck.io/*' => Http::response([
        'status' => 'ok',
        '203.0.113.9' => ['proxy' => 'yes', 'type' => 'Business', 'isocode' => 'US'],
    ])]);

    $verdict = app(ProxyCheckProvider::class)->lookup('203.0.113.9', IpProvider::query()->create([
        'key' => 'proxycheck', 'name' => 'ProxyCheck.io', 'enabled' => true, 'api_key' => 'k',
    ]));

    expect($verdict->isProxy)->toBeTrue()->and($verdict->proxyType)->toBeNull();

    gateVerdict($verdict);
    gateRule('vpn_proxy_ip');

    $response = wallGet($app);

    $response->assertOk()->assertDontSee(__('wall.notice.blocked_proxy.title'));

    expect(FraudEvent::query()->where('rule_key', 'vpn_proxy_ip')->count())->toBe(1);
});

it('serves the wall when the same rule is only flagging', function () {
    [$app] = wallFixture();
    gateVerdict(new IpVerdict(country: 'US', isProxy: true, proxyType: ProxyType::Vpn));
    gateRule('vpn_proxy_ip', FraudAction::Flag);

    wallGet($app)->assertOk();
});

it('ignores a blocking rule that is not render-gate eligible', function () {
    [$app] = wallFixture();
    gateVerdict(new IpVerdict(country: 'US'));
    // Blocking, pre-click, and utterly unsuited to render: it would count a
    // tab open as a click. `max: 0` forces it to trigger on every render —
    // with no clicks logged, `0 < 0` is false — so the only thing keeping
    // the wall open is the `RenderGateRule` filter this test is named for.
    gateRule('click_velocity', config: ['max' => 0]);

    wallGet($app)->assertOk();
});

it('ignores a render-gate rule that is disabled', function () {
    [$app] = wallFixture();
    gateVerdict(new IpVerdict(country: 'US', isProxy: true, proxyType: ProxyType::Vpn));
    gateRule('vpn_proxy_ip')->update(['enabled' => false]);

    wallGet($app)->assertOk();
});

it('ignores a render-gate rule staged for post-conversion', function () {
    [$app] = wallFixture();
    gateVerdict(new IpVerdict(country: 'US', isProxy: true, proxyType: ProxyType::Vpn));
    gateRule('vpn_proxy_ip')->update(['stage' => FraudStage::PostConversion]);

    wallGet($app)->assertOk();
});

/**
 * Fix 3: `WallContext::$verdict` used to be carried for a reason that turned
 * out false and read by nothing — `protectionBlock()` built a fresh
 * `FraudContext` on every call, and every eligible rule re-derived its own
 * verdict through `inspect()`. That is one extra cache read per gate rule on
 * every wall impression, not only blocked ones. Five rules enabled here
 * means the unfixed code calls `inspect()` six times (once in
 * `WallGate::context()`, once per rule); this proves it is called once.
 */
it('resolves the ip verdict once for a render that evaluates several gate rules', function () {
    [$app] = wallFixture();

    $intelligence = new class implements IpIntelligence
    {
        public int $calls = 0;

        public function inspect(string $ip, bool $consented = false): IpVerdict
        {
            $this->calls++;

            // Clean on every axis these rules check, so none of them trigger
            // and protectionBlock() runs the whole enabled set rather than
            // returning after the first.
            return new IpVerdict(country: 'US');
        }
    };

    app()->register(FraudServiceProvider::class);
    app()->instance(IpIntelligence::class, $intelligence);

    gateRule('datacenter_ip');
    gateRule('vpn_proxy_ip');
    gateRule('tor_ip');
    gateRule('residential_proxy_ip');
    gateRule('ip_risk_score');

    wallGet($app)->assertOk();

    expect($intelligence->calls)->toBe(1);
});

it('sends an unconsented user to the consent screen rather than blocking them', function () {
    [$app] = wallFixture();
    wallConsentCountries(['DE']);
    gateVerdict(new IpVerdict(country: 'DE', isProxy: true, proxyType: ProxyType::Tor));
    gateRule('tor_ip');

    $response = wallGet($app);

    // The consent gate comes first: writing a fraud event here would record
    // this user's IP and end user ID before they had answered.
    $response->assertOk()->assertDontSee(__('wall.notice.blocked_proxy.title'));

    expect(FraudEvent::query()->count())->toBe(0);
});

it('escalates at click for a consent-country user whose verdict expired', function () {
    [$app, $network] = wallFixture();
    $offer = wallOffer($network);
    wallConsentCountries(['DE']);
    // `ip` and `user_agent_hash` are NOT NULL with no default (see the
    // `consents` migration) — the brief's snippet omitted them.
    Consent::query()->create([
        'app_id' => $app->id, 'end_user_id' => 'USER_1', 'granted' => true,
        'ip' => '203.0.113.1', 'user_agent_hash' => hash('sha256', WALL_UA),
    ]);

    $seen = [];
    app()->instance(IpIntelligence::class, new class($seen) implements IpIntelligence
    {
        /** @param array<int, bool> $seen */
        public function __construct(public array &$seen) {}

        public function inspect(string $ip, bool $consented = false): IpVerdict
        {
            $this->seen[] = $consented;

            return new IpVerdict(country: 'DE');
        }
    });

    wallClick($app, $offer);

    // The first call cannot escalate — the country it returns is what decides
    // whether consent was needed. The second one can.
    expect(app(IpIntelligence::class)->seen)->toContain(true);
});

it('never sends a consent-country address to a provider behind a test-mode country override', function () {
    Http::fake();

    // Test mode + `?country=` is exactly what `WallContext::$countryOverridden`
    // warns is reachable by "anyone holding the wall URL" — the displayed
    // country (US) is not a consent country, so the consent screen never
    // appears, even though the real IP (DE, below) is one.
    [$app] = wallFixture(['test_mode' => true]);
    wallConsentCountries(['DE']);

    IpProvider::query()->create([
        'key' => 'proxycheck', 'name' => 'ProxyCheck.io', 'enabled' => true, 'api_key' => 'k',
    ]);

    $local = new class implements IpIntelligence
    {
        public function inspect(string $ip, bool $consented = false): IpVerdict
        {
            return new IpVerdict(country: 'DE');
        }
    };

    app()->instance(IpIntelligence::class, new ChainedIpIntelligence($local, app(IpProviderRegistry::class)));

    wallGet($app, 'USER_1', ['country' => 'US'])->assertOk();

    Http::assertNothingSent();
});

it('recomputes country from the escalated verdict when no override is in play', function () {
    [$app] = wallFixture();

    // The first inspect() (consented: false) is what context() sees; the
    // second (consented: true) is what a granted consent unlocks.
    app()->instance(IpIntelligence::class, new class implements IpIntelligence
    {
        public function inspect(string $ip, bool $consented = false): IpVerdict
        {
            return $consented ? new IpVerdict(country: 'EG') : IpVerdict::unknown();
        }
    });

    $context = partnerContext($app);
    expect($context->country)->toBeNull();

    $escalated = app(WallGate::class)->escalate($context);

    expect($escalated->country)->toBe('EG')
        ->and($escalated->verdict->country)->toBe('EG');
});

it('keeps the test-mode override rather than leaking the escalated verdict\'s real country', function () {
    [$app] = wallFixture(['test_mode' => true]);

    app()->instance(IpIntelligence::class, new class implements IpIntelligence
    {
        public function inspect(string $ip, bool $consented = false): IpVerdict
        {
            // Whatever consent unlocks, the override is what the publisher
            // asked to preview and must not be overwritten by it.
            return new IpVerdict(country: $consented ? 'FR' : 'DE');
        }
    });

    $context = partnerContext($app, extra: ['country' => 'US']);
    expect($context->country)->toBe('US')->and($context->countryOverridden)->toBeTrue();

    $escalated = app(WallGate::class)->escalate($context);

    expect($escalated->country)->toBe('US');
});
