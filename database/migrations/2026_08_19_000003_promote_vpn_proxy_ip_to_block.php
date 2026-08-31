<?php

use App\Enums\FraudAction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * `FraudRuleSeeder` inserts missing rules and leaves existing rows alone,
     * so that it never resets a threshold an operator tuned. That is the right
     * behaviour and it is also why the seeder cannot make this change: every
     * database that has been seeded already holds `vpn_proxy_ip` at `flag`.
     *
     * Guarded on the current value. An operator who has already moved this rule
     * to `score` made a decision, and a migration is not the place to overrule
     * it.
     */
    public function up(): void
    {
        DB::table('fraud_rules')
            ->where('key', 'vpn_proxy_ip')
            ->where('action', FraudAction::Flag->value)
            ->update(['action' => FraudAction::Block->value, 'score_weight' => 40]);
    }

    public function down(): void
    {
        DB::table('fraud_rules')
            ->where('key', 'vpn_proxy_ip')
            ->where('action', FraudAction::Block->value)
            ->update(['action' => FraudAction::Flag->value, 'score_weight' => 20]);
    }
};
