<?php

use App\Enums\ProxyType;
use App\Fraud\IpVerdict;

it('parses the IP2Proxy proxy classes and rejects everything else', function () {
    expect(ProxyType::parse('VPN'))->toBe(ProxyType::Vpn)
        ->and(ProxyType::parse('res'))->toBe(ProxyType::Residential)
        ->and(ProxyType::parse('-'))->toBeNull()
        ->and(ProxyType::parse(null))->toBeNull()
        ->and(ProxyType::parse('NOPE'))->toBeNull();
});

it('fills gaps from a provider answer but never clears a local positive', function () {
    $local = new IpVerdict(
        country: 'DE',
        asn: 24940,
        isDatacenter: true,
        isProxy: true,
        proxyType: ProxyType::Datacenter,
    );

    $provider = new IpVerdict(
        country: 'FR',
        organization: 'Hetzner Online GmbH',
        isDatacenter: false,
        isProxy: false,
        proxyType: ProxyType::Residential,
        riskScore: 91,
    );

    $merged = $local->merge($provider, 'ipqualityscore');

    // Present locally, so the provider does not get to overwrite it.
    expect($merged->country)->toBe('DE')
        ->and($merged->isDatacenter)->toBeTrue()
        ->and($merged->isProxy)->toBeTrue()
        ->and($merged->proxyType)->toBe(ProxyType::Datacenter)
        // Absent locally, so the provider fills it.
        ->and($merged->organization)->toBe('Hetzner Online GmbH')
        ->and($merged->riskScore)->toBe(91)
        ->and($merged->source)->toBe('ipqualityscore');
});

it('marks a verdict degraded without losing what it already knows', function () {
    $verdict = (new IpVerdict(country: 'EG', isProxy: true))->degraded();

    expect($verdict->degraded)->toBeTrue()
        ->and($verdict->country)->toBe('EG')
        ->and($verdict->isProxy)->toBeTrue();
});

it('remembers under the key it is given', function () {
    $verdict = IpVerdict::remember('ip:local:v2:9.9.9.9', 60, fn () => new IpVerdict(country: 'US'));

    expect($verdict->country)->toBe('US')
        ->and(cache()->get('ip:local:v2:9.9.9.9'))->toEqual($verdict);
});
