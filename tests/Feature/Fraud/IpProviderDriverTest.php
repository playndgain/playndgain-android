<?php

use App\Enums\ProxyType;
use App\Fraud\Providers\IpasisProvider;
use App\Fraud\Providers\IpProviderRegistry;
use App\Fraud\Providers\IpQualityScoreProvider;
use App\Fraud\Providers\ProxyCheckProvider;
use App\Models\IpProvider;
use Illuminate\Support\Facades\Http;

function driverConfig(string $key, array $attributes = []): IpProvider
{
    return tap(IpProvider::query()->firstOrNew(['key' => $key]), function (IpProvider $provider) use ($key, $attributes): void {
        $provider->fill([
            'key' => $key, 'name' => $key, 'enabled' => true, 'api_key' => 'test-key', ...$attributes,
        ])->save();
    });
}

it('maps a proxycheck answer onto a verdict', function () {
    Http::fake(['proxycheck.io/*' => Http::response([
        'status' => 'ok',
        '1.2.3.4' => [
            'asn' => 'AS24940', 'provider' => 'Hetzner', 'country' => 'Germany',
            'isocode' => 'DE', 'proxy' => 'yes', 'type' => 'VPN', 'risk' => 72,
        ],
    ])]);

    $verdict = app(ProxyCheckProvider::class)->lookup('1.2.3.4', driverConfig('proxycheck'));

    expect($verdict->country)->toBe('DE')
        ->and($verdict->asn)->toBe(24940)
        ->and($verdict->organization)->toBe('Hetzner')
        ->and($verdict->isProxy)->toBeTrue()
        ->and($verdict->proxyType)->toBe(ProxyType::Vpn)
        ->and($verdict->riskScore)->toBe(72)
        ->and($verdict->source)->toBe('proxycheck');
});

it('maps an ipqualityscore answer onto a verdict', function () {
    Http::fake(['ipqualityscore.com/*' => Http::response([
        'success' => true, 'country_code' => 'US', 'ASN' => 15169, 'ISP' => 'Google',
        'proxy' => true, 'vpn' => false, 'tor' => true, 'fraud_score' => 93,
    ])]);

    $verdict = app(IpQualityScoreProvider::class)->lookup('1.2.3.4', driverConfig('ipqualityscore'));

    expect($verdict->country)->toBe('US')
        ->and($verdict->proxyType)->toBe(ProxyType::Tor)
        ->and($verdict->riskScore)->toBe(93)
        ->and($verdict->source)->toBe('ipqualityscore');
});

it('maps an ipasis answer onto a verdict', function () {
    Http::fake(['api.ipasis.com/*' => Http::response([
        'country' => 'nl',
        'asn' => ['asn' => 'AS211252', 'name' => 'Bot Hosting LLC'],
        'company' => ['name' => 'Hosting Company'],
        'privacy' => [
            'vpn' => false,
            'proxy' => true,
            'tor' => true,
            'relay' => false,
            'hosting' => true,
            'abuse' => true,
        ],
    ])]);

    $verdict = app(IpasisProvider::class)->lookup('1.2.3.4', driverConfig('ipasis'));

    expect($verdict->country)->toBe('NL')
        ->and($verdict->asn)->toBe(211252)
        ->and($verdict->organization)->toBe('Hosting Company')
        ->and($verdict->isDatacenter)->toBeTrue()
        ->and($verdict->isProxy)->toBeTrue()
        ->and($verdict->proxyType)->toBe(ProxyType::Tor)
        ->and($verdict->threat)->toBe('ABUSE')
        ->and($verdict->riskScore)->toBeNull()
        ->and($verdict->source)->toBe('ipasis');
});

it('sends the ipasis key as a query parameter and strips surrounding whitespace', function () {
    Http::fake(['api.ipasis.com/*' => Http::response([
        'country' => 'US',
        'privacy' => [],
    ])]);

    app(IpasisProvider::class)->lookup('1.2.3.4', driverConfig('ipasis', [
        'api_key' => "  ab/cd+ef  \n",
    ]));

    Http::assertSent(function ($request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_starts_with($request->url(), 'https://api.ipasis.com/v1/lookup?')
            && ($query['key'] ?? null) === 'ab/cd+ef'
            && ($query['ip'] ?? null) === '1.2.3.4';
    });
});

it('treats an ipasis relay as a proxy without misclassifying it as a vpn', function () {
    Http::fake(['api.ipasis.com/*' => Http::response([
        'country' => 'US',
        'privacy' => ['relay' => true],
    ])]);

    $verdict = app(IpasisProvider::class)->lookup('1.2.3.4', driverConfig('ipasis'));

    expect($verdict->isProxy)->toBeTrue()
        ->and($verdict->proxyType)->toBeNull();
});

it('records an ipasis authentication failure and opens its breaker', function () {
    Http::fake(['api.ipasis.com/*' => Http::response([], 401)]);

    $config = driverConfig('ipasis', ['circuit_failures' => 1]);
    $verdict = app(IpasisProvider::class)->lookup('1.2.3.4', $config);

    expect($verdict)->toBeNull()
        ->and($config->fresh()->last_error)->toBe('HTTP 401')
        ->and($config->fresh()->breakerOpen())->toBeTrue();
});

it('sends the ipqualityscore key as a query parameter and strips surrounding whitespace', function () {
    // Their own docs offer this form specifically so a key with `/` or `+`
    // is not parsed as extra path segments — which they answer as
    // "Invalid or unauthorized key" rather than as a malformed URL.
    Http::fake(['ipqualityscore.com/*' => Http::response([
        'success' => true, 'country_code' => 'US', 'proxy' => false, 'fraud_score' => 10,
    ])]);

    app(IpQualityScoreProvider::class)->lookup('1.2.3.4', driverConfig('ipqualityscore', [
        'api_key' => "  ab/cd+ef  \n",
    ]));

    Http::assertSent(function ($request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_starts_with($request->url(), 'https://ipqualityscore.com/api/json/ip?')
            && ($query['key'] ?? null) === 'ab/cd+ef'
            && ($query['ip'] ?? null) === '1.2.3.4'
            && ! str_contains((string) parse_url($request->url(), PHP_URL_PATH), 'ab/cd');
    });
});

it('returns null and records the failure when the provider errors', function () {
    Http::fake(['proxycheck.io/*' => Http::response('', 500)]);

    $config = driverConfig('proxycheck', ['circuit_failures' => 1]);
    $verdict = app(ProxyCheckProvider::class)->lookup('1.2.3.4', $config);

    expect($verdict)->toBeNull()
        ->and($config->fresh()->breakerOpen())->toBeTrue()
        ->and($config->fresh()->last_error)->not->toBeNull();
});

it('counts a successful lookup against the daily cap', function () {
    Http::fake(['proxycheck.io/*' => Http::response(['status' => 'ok', '1.2.3.4' => ['proxy' => 'no']])]);

    $config = driverConfig('proxycheck');
    app(ProxyCheckProvider::class)->lookup('1.2.3.4', $config);

    expect($config->spent())->toBe(1);
});

it('resolves a driver by key', function () {
    $registry = app(IpProviderRegistry::class);

    expect($registry->resolve('proxycheck'))->toBeInstanceOf(ProxyCheckProvider::class)
        ->and($registry->resolve('ipasis'))->toBeInstanceOf(IpasisProvider::class)
        ->and($registry->resolve('nope'))->toBeNull();
});

it('does not classify a business connection as a datacenter', function () {
    Http::fake(['proxycheck.io/*' => Http::response([
        'status' => 'ok',
        '1.2.3.4' => ['proxy' => 'no', 'type' => 'Business'],
    ])]);

    $verdict = app(ProxyCheckProvider::class)->lookup('1.2.3.4', driverConfig('proxycheck'));

    expect($verdict->isDatacenter)->toBeFalse()
        ->and($verdict->proxyType)->toBeNull();
});

it('reads a compromised server as a threat, not a proxy type', function () {
    Http::fake(['proxycheck.io/*' => Http::response([
        'status' => 'ok',
        '1.2.3.4' => ['proxy' => 'no', 'type' => 'Compromised Server'],
    ])]);

    $verdict = app(ProxyCheckProvider::class)->lookup('1.2.3.4', driverConfig('proxycheck'));

    expect($verdict->threat)->toBe('COMPROMISED_SERVER')
        ->and($verdict->proxyType)->toBeNull();
});

it('still classifies hosting as a datacenter', function () {
    // Regression guard for the fix above: only `Hosting` stays mapped to DCH.
    Http::fake(['proxycheck.io/*' => Http::response([
        'status' => 'ok',
        '1.2.3.4' => ['proxy' => 'yes', 'type' => 'Hosting'],
    ])]);

    $verdict = app(ProxyCheckProvider::class)->lookup('1.2.3.4', driverConfig('proxycheck'));

    expect($verdict->isDatacenter)->toBeTrue()
        ->and($verdict->proxyType)->toBe(ProxyType::Datacenter);
});

it('clamps an out-of-range risk score into 0-100', function () {
    Http::fake(['proxycheck.io/*' => Http::response([
        'status' => 'ok',
        '1.2.3.4' => ['proxy' => 'no', 'risk' => 150],
    ])]);

    $verdict = app(ProxyCheckProvider::class)->lookup('1.2.3.4', driverConfig('proxycheck'));

    expect($verdict->riskScore)->toBe(100);
});

it('names the vendor rejection reason rather than "unreadable" when the body parses but proxycheck refuses', function () {
    Http::fake(['proxycheck.io/*' => Http::response(['status' => 'denied'])]);

    $config = driverConfig('proxycheck');
    app(ProxyCheckProvider::class)->lookup('1.2.3.4', $config);

    expect($config->fresh()->last_error)->toBe('proxycheck: denied');
});

it('classifies every open-proxy protocol proxycheck documents as a public proxy', function (string $type) {
    // Only an explicit consumer class refuses a render (VpnProxyIpRule), so an
    // open proxy left unmapped is a rendered wall for a detected exit node.
    Http::fake(['proxycheck.io/*' => Http::response([
        'status' => 'ok',
        '1.2.3.4' => ['proxy' => 'yes', 'type' => $type],
    ])]);

    $verdict = app(ProxyCheckProvider::class)->lookup('1.2.3.4', driverConfig('proxycheck'));

    expect($verdict->proxyType)->toBe(ProxyType::Public);
})->with(['SOCKS', 'SOCKS4', 'SOCKS4A', 'SOCKS5', 'SOCKS5H', 'Shadowsocks', 'HTTP', 'HTTPS', 'OpenVPN']);

it('leaves a wireless carrier allocation unclassified', function () {
    // Most of the platform's real traffic. Mapping it to any proxy class
    // refuses the wall to a phone on mobile data.
    Http::fake(['proxycheck.io/*' => Http::response([
        'status' => 'ok',
        '1.2.3.4' => ['proxy' => 'no', 'type' => 'Wireless'],
    ])]);

    $verdict = app(ProxyCheckProvider::class)->lookup('1.2.3.4', driverConfig('proxycheck'));

    expect($verdict->proxyType)->toBeNull()
        ->and($verdict->isDatacenter)->toBeFalse();
});

it('reads ipqualityscore\'s "N/A" as unknown rather than as an answer', function () {
    // Their sentinel for a field they could not fill. Stored verbatim, a
    // country of "N/A" matches no country list and passes every geo rule.
    Http::fake(['ipqualityscore.com/*' => Http::response([
        'success' => true, 'country_code' => 'N/A', 'ISP' => 'N/A', 'ASN' => 0,
        'proxy' => false, 'fraud_score' => 0,
    ])]);

    $verdict = app(IpQualityScoreProvider::class)->lookup('1.2.3.4', driverConfig('ipqualityscore'));

    expect($verdict->country)->toBeNull()
        ->and($verdict->organization)->toBeNull()
        // `0` is a reserved ASN, never a real answer — and a stored one would
        // outrank the real ASN a second provider still had, since merge()
        // keeps the first non-null.
        ->and($verdict->asn)->toBeNull();
});

it('reads an ipqualityscore data center connection as a datacenter', function () {
    Http::fake(['ipqualityscore.com/*' => Http::response([
        'success' => true, 'country_code' => 'us', 'ISP' => 'Amazon',
        'connection_type' => 'Data Center', 'proxy' => false, 'fraud_score' => 40,
    ])]);

    $verdict = app(IpQualityScoreProvider::class)->lookup('1.2.3.4', driverConfig('ipqualityscore'));

    expect($verdict->isDatacenter)->toBeTrue()
        ->and($verdict->country)->toBe('US');
});
