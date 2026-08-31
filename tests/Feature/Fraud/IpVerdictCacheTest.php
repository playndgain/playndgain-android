<?php

use App\Fraud\IpVerdict;
use App\Fraud\MaxMindIpIntelligence;
use App\Fraud\NullIpIntelligence;
use Illuminate\Support\Facades\Cache;

it('reuses a cached verdict instead of looking it up twice', function () {
    $calls = 0;

    $lookup = function () use (&$calls): IpVerdict {
        $calls++;

        return new IpVerdict(country: 'EG');
    };

    expect(IpVerdict::remember('ip:local:v2:1.2.3.4', 3600, $lookup)->country)->toBe('EG')
        ->and(IpVerdict::remember('ip:local:v2:1.2.3.4', 3600, $lookup)->country)->toBe('EG')
        ->and($calls)->toBe(1);
});

it('looks up again when the cached payload is not a verdict', function () {
    // What a store hands back for an entry serialised before the class was
    // autoloadable, or before a change to its shape. Left unguarded the return
    // type raises a TypeError on every wall request from that IP for an hour.
    Cache::put('ip:local:v2:9.9.9.9', unserialize('O:8:"IpGarbag":0:{}', ['allowed_classes' => false]), 3600);

    $verdict = IpVerdict::remember('ip:local:v2:9.9.9.9', 3600, fn (): IpVerdict => new IpVerdict(country: 'US'));

    expect($verdict)->toBeInstanceOf(IpVerdict::class)
        ->and($verdict->country)->toBe('US')
        // And the bad entry is replaced, not re-read on the next request.
        ->and(Cache::get('ip:local:v2:9.9.9.9'))->toBeInstanceOf(IpVerdict::class);
});

it('keys both bindings the same so swapping one reads the other\'s entry', function () {
    app(NullIpIntelligence::class)->inspect('5.5.5.5');

    expect(Cache::get('ip:local:v2:5.5.5.5'))->toBeInstanceOf(IpVerdict::class)
        ->and(app(MaxMindIpIntelligence::class)->inspect('5.5.5.5')->country)->toBeNull();
});
