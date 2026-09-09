<?php

use App\Enums\ProxyType;
use App\Fraud\MaxMindIpIntelligence;

require_once __DIR__.'/FraudFixtures.php';

it('reads the proxy classification fields when the database is on disk', function () {
    if (! is_file(MaxMindIpIntelligence::file(MaxMindIpIntelligence::PROXY_DB))) {
        $this->markTestSkipped('IP2Proxy database not provisioned in this environment.');
    }

    // Found rather than hardcoded. `geoip:update` replaces this file weekly and
    // LITE listings churn, so pinning a specific address writes a test that
    // fails in some future week with no code change behind it. The loop never
    // stops at the first hit — it scans the whole sample so the assertions
    // below have an aggregate to check, not one lucky row.
    $reader = new MaxMindIpIntelligence;

    mt_srand(7);

    $listedCount = 0;
    $usageTypeSeen = 0;

    for ($i = 0; $i < 8000; $i++) {
        $candidate = mt_rand(1, 223).'.'.mt_rand(0, 255).'.'.mt_rand(0, 255).'.'.mt_rand(1, 254);

        if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            continue;
        }

        $verdict = $reader->inspect($candidate);

        if (! $verdict->isProxy) {
            continue;
        }

        $listedCount++;

        // Shape, not values, on every hit. A listed address always carries a
        // class and never a score: the LITE file answers FRAUD_SCORE with the
        // vendor's "please upgrade" sentence, not a number, and storing that
        // sentence would be storing marketing copy as data.
        expect($verdict->proxyType)->toBeInstanceOf(ProxyType::class)
            ->and($verdict->riskScore)->toBeNull();

        // usageType and threat are genuinely null for plenty of listed rows —
        // asserting "always set" would be a flaky test against real data.
        // What must never happen, on a row that does carry a value, is the
        // raw sentinel leaking through: `field()` is supposed to collapse
        // both IP2Proxy's "-" and its "please upgrade" sentence to null
        // before either reaches the verdict.
        foreach ([$verdict->usageType, $verdict->threat] as $value) {
            if ($value === null) {
                continue;
            }

            expect($value)->not->toBe('')
                ->and($value)->not->toBe('-')
                ->and($value)->not->toContain('unavailable in selected');
        }

        if ($verdict->usageType !== null) {
            $usageTypeSeen++;
        }
    }

    // The database file is on disk but the sample turned up nothing listed.
    // From inside this loop that is indistinguishable between "this week's
    // LITE snapshot is thin" and "proxy() regressed to always report
    // is_proxy = false" — a broken cast on $record['isProxy'], a flipped
    // comparison. Skipping here would let the second case pass CI green,
    // which is worse than no test at all, so this fails instead. The
    // is_file() guard above is the only legitimate skip in this test.
    expect($listedCount)->toBeGreaterThan(0);

    // At least one listed address must carry a non-null usageType. IP2Proxy
    // populates it for every listed row this database's classes cover, so an
    // all-null result means a typo'd field key or a broken field() helper,
    // not real sparseness — the one regression `->not->toBe('-')` could not
    // catch on its own, because null also satisfies "not equal to '-'".
    expect($usageTypeSeen)->toBeGreaterThan(0);
});

it('degrades to unknown rather than throwing when no database is present', function () {
    config()->set('playndgain.geoip_path', storage_path('app/geoip-absent'));

    $verdict = (new MaxMindIpIntelligence)->inspect('194.226.224.226');

    expect($verdict->country)->toBeNull()
        ->and($verdict->proxyType)->toBeNull()
        ->and($verdict->usageType)->toBeNull()
        ->and($verdict->threat)->toBeNull();
});
