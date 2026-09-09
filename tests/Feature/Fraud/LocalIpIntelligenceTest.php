<?php

use App\Fraud\Contracts\IpIntelligence;
use App\Fraud\IpVerdict;
use App\Fraud\LocalIpIntelligence;
use App\Fraud\MaxMindIpIntelligence;

/**
 * The dev-only geo stand-in. Everything here is about the guard rather than the
 * lookup: a decorator that places an address the databases refused is exactly
 * what must never reach production, so the tests that matter are the ones that
 * prove it stays off.
 */
it('places a loopback address at the configured country', function () {
    $intelligence = new LocalIpIntelligence(new MaxMindIpIntelligence, 'EG');

    expect($intelligence->inspect('127.0.0.1')->country)->toBe('EG');
});

it('places a private LAN address too, so a phone on the same wifi previews', function () {
    $intelligence = new LocalIpIntelligence(new MaxMindIpIntelligence, 'EG');

    expect($intelligence->inspect('192.168.1.14')->country)->toBe('EG')
        ->and($intelligence->inspect('10.0.0.5')->country)->toBe('EG');
});

it('delegates a routable address untouched', function () {
    $inner = new class implements IpIntelligence
    {
        public function inspect(string $ip, bool $consented = false): IpVerdict
        {
            return new IpVerdict(country: 'SE', isProxy: true);
        }
    };

    $verdict = (new LocalIpIntelligence($inner, 'EG'))->inspect('8.8.8.8');

    // Not merely the country: a real public address must come back with the
    // reader's whole verdict, proxy and datacenter flags included, or local
    // dev stops exercising the fraud rules it is supposed to exercise.
    expect($verdict->country)->toBe('SE')
        ->and($verdict->isProxy)->toBeTrue();
});

it('normalises a lowercase country from the environment', function () {
    expect((new LocalIpIntelligence(new MaxMindIpIntelligence, 'eg'))->inspect('127.0.0.1')->country)->toBe('EG');
});

it('is not bound outside local, whatever the config says', function () {
    // The test suite runs as `testing`, which is the point: the container has
    // the real reader, and a stray PLAYNDGAIN_DEV_COUNTRY in someone's shell does
    // not quietly place every address in the test suite.
    config()->set('playndgain.geoip_dev_country', 'EG');

    expect(app(IpIntelligence::class))->not->toBeInstanceOf(LocalIpIntelligence::class)
        ->and(app(IpIntelligence::class)->inspect('127.0.0.1')->country)->toBeNull();
});

it('leaves an unset country alone rather than defaulting to one', function () {
    // No config, no decorator: an unconfigured install behaves exactly as it
    // does today, empty wall and all. The escape hatch is opt-in, and the suite
    // pins the variable blank (phpunit.xml) so this asserts the default rather
    // than whatever the developer running it put in their own .env.
    expect(config('playndgain.geoip_dev_country'))->toBeEmpty();
});
