<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** What `IpProviderSeeder` inserts, and therefore the only values this may overwrite. */
    private const SEEDED_CAP = 1000;

    private const SEEDED_TTL = 86400;

    /** The account's real IPQualityScore tier. */
    private const TIER_CAP = 200;

    /**
     * A vendor fraud score moves on weekly timescales, so a one-day TTL re-bills
     * the same address every day for an answer that did not change. At seven
     * days a 200/day cap covers roughly 1,400 distinct addresses of standing
     * coverage instead of 200 — the same spend, seven times the reach.
     *
     * Only the raw provider layer is held longer. The merged `ip:v2:` verdict
     * still expires hourly, so a stale country is never served; that split is
     * exactly what the three cache layers exist for.
     */
    private const TIER_TTL = 604800;

    /**
     * Both edits to this row live in one file on purpose. Split across two
     * migrations, a partial rollback leaves a 200-request cap sitting on a
     * seven-day TTL — a combination neither migration intended.
     *
     * Guarded on the seeded values for the reason
     * `promote_vpn_proxy_ip_to_block` gives: `IpProviderSeeder` inserts missing
     * rows and leaves existing ones alone so it can never reset an operator's
     * tuning, which is also why it cannot make this change. An operator who has
     * already set their own cap made a decision, and a migration is not the
     * place to overrule it.
     */
    public function up(): void
    {
        DB::table('ip_providers')
            ->where('key', 'ipqualityscore')
            ->where('daily_cap', self::SEEDED_CAP)
            ->where('ttl_seconds', self::SEEDED_TTL)
            ->update([
                'daily_cap' => self::TIER_CAP,
                'ttl_seconds' => self::TIER_TTL,
            ]);
    }

    public function down(): void
    {
        DB::table('ip_providers')
            ->where('key', 'ipqualityscore')
            ->where('daily_cap', self::TIER_CAP)
            ->where('ttl_seconds', self::TIER_TTL)
            ->update([
                'daily_cap' => self::SEEDED_CAP,
                'ttl_seconds' => self::SEEDED_TTL,
            ]);
    }
};
