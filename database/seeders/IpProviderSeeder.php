<?php

namespace Database\Seeders;

use App\Models\IpProvider;
use Illuminate\Database\Seeder;

/**
 * Both adapters, disabled and unconfigured.
 *
 * Rows are seeded rather than creatable from the panel for the same reason
 * fraud rules are: a row whose key has no driver class behind it does nothing,
 * silently, which is worse than not having it.
 *
 * Re-running inserts missing providers and leaves existing rows alone, so it
 * never clears a key or reverses a cap an operator set.
 */
class IpProviderSeeder extends Seeder
{
    /** @var list<array{key: string, name: string, priority: int, ttl_seconds: int, daily_cap: int}> */
    private const PROVIDERS = [
        // Free tier, no card, 1000 lookups a day — so it is the one that can be
        // switched on today, and it goes first.
        //
        // `daily_cap` is seeded deliberately rather than left null (spec §8:
        // "null means uncapped, which is a choice an operator has to make
        // deliberately"). A clean local verdict is inconclusive by design, so
        // ordinary traffic escalates at close to one lookup per distinct
        // address — an unbounded row is not a safety net for a fraud tail, it
        // is an unbounded bill for normal render volume. 1000 matches
        // proxycheck's own free-tier ceiling, so it never refuses a request
        // the vendor would have accepted anyway; ipqualityscore gets the same
        // starting number for lack of a better one. An operator raises or
        // clears it once traffic shows what the real ceiling should be.
        ['key' => 'proxycheck', 'name' => 'ProxyCheck.io', 'priority' => 10, 'ttl_seconds' => 86400, 'daily_cap' => 1000],
        // 200/day is the account's real tier, and the seven-day TTL is what
        // makes it go anywhere: a vendor fraud score moves weekly, so a one-day
        // cache re-bills the same address daily for an unchanged answer. At
        // seven days the same 200 covers roughly 1,400 distinct addresses.
        // Only the raw provider layer is held longer — the merged verdict still
        // expires hourly, so a stale country is never served.
        ['key' => 'ipqualityscore', 'name' => 'IPQualityScore', 'priority' => 20, 'ttl_seconds' => 604800, 'daily_cap' => 200],
    ];

    public function run(): void
    {
        foreach (self::PROVIDERS as $provider) {
            IpProvider::query()->firstOrCreate(['key' => $provider['key']], $provider);
        }
    }
}
