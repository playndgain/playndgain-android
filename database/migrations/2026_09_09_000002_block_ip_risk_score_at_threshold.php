<?php

use App\Enums\FraudAction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** What `FraudRuleSeeder` inserts, and therefore the only state this may overwrite. */
    private const SEEDED_THRESHOLD = 85;

    private const SEEDED_WEIGHT = 25;

    /**
     * Owner's decision, recorded in
     * docs/superpowers/specs/2026-09-09-fraud-detection-design.md §4.6.
     *
     * Well below the ~75 IPQualityScore itself treats as suspicious, and this
     * rule is a `RenderGateRule` — over threshold refuses the whole wall, not
     * one offer. Ordinary mobile carrier, CGNAT and recycled residential
     * addresses land in the 25–50 band, so this will show an empty wall to some
     * real people. That was raised with those specifics and chosen anyway,
     * together with the score distribution on the Protection page so the cost
     * is visible the same day rather than inferred from publisher complaints.
     *
     * It is one admin toggle from off and one config edit from a higher number.
     * Written down so a later reader does not mistake it for an oversight.
     */
    private const THRESHOLD = 27;

    private const WEIGHT = 40;

    /**
     * Threshold and action in one file: rolled back separately, a threshold of
     * 27 could be left sitting at `block` after the action reverted, or 85 left
     * blocking — neither is a state either half intended.
     *
     * Guarded on the seeded values, like
     * `promote_vpn_proxy_ip_to_block`. `FraudRuleSeeder` insert-onlys so that it
     * never resets a tuned threshold, which is why it cannot make this change;
     * an operator who has already moved this rule made a decision.
     */
    public function up(): void
    {
        $this->retune(self::SEEDED_THRESHOLD, FraudAction::Flag, self::THRESHOLD, FraudAction::Block, self::WEIGHT);
    }

    public function down(): void
    {
        $this->retune(self::THRESHOLD, FraudAction::Block, self::SEEDED_THRESHOLD, FraudAction::Flag, self::SEEDED_WEIGHT);
    }

    /**
     * `config` is a JSON column and the guard has to read the decoded threshold,
     * so this reads the row rather than filtering on it in SQL — a JSON path
     * predicate that behaves the same on MySQL and SQLite is not worth the
     * portability risk for a single-row update.
     */
    private function retune(int $from, FraudAction $fromAction, int $to, FraudAction $toAction, int $weight): void
    {
        $rule = DB::table('fraud_rules')->where('key', 'ip_risk_score')->first();

        if ($rule === null || $rule->action !== $fromAction->value) {
            return;
        }

        $config = json_decode((string) $rule->config, true);

        if (! is_array($config) || ($config['threshold'] ?? null) !== $from) {
            return;
        }

        $config['threshold'] = $to;

        DB::table('fraud_rules')->where('key', 'ip_risk_score')->update([
            'action' => $toAction->value,
            'score_weight' => $weight,
            'config' => json_encode($config),
        ]);
    }
};
