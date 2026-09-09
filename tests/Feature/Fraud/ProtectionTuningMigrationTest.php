<?php

use App\Enums\FraudAction;
use App\Enums\FraudStage;
use App\Models\FraudRule;
use App\Models\IpProvider;
use Illuminate\Support\Facades\DB;

/**
 * The two tuning migrations — spec §4.3.
 *
 * Both seeders insert-only, so they can never reset a threshold an operator
 * tuned — which is also why neither can make these changes on an install that
 * has already been seeded. The migration does that half, the seeder covers a
 * fresh install, and the guard is what keeps the migration from overruling an
 * operator who already moved the value themselves.
 */
function runTuningMigration(string $file): object
{
    return require database_path("migrations/{$file}.php");
}

const QUOTA_MIGRATION = '2026_09_09_000001_tune_ipqualityscore_quota';
const RISK_MIGRATION = '2026_09_09_000002_block_ip_risk_score_at_threshold';

it('moves an already-seeded ipqualityscore row onto the real tier', function () {
    seedProviderRow(1000, 86400);

    runTuningMigration(QUOTA_MIGRATION)->up();

    $row = IpProvider::query()->where('key', 'ipqualityscore')->sole();

    expect((int) $row->daily_cap)->toBe(200)->and((int) $row->ttl_seconds)->toBe(604800);
});

it('does not overrule an operator who already set their own cap', function () {
    seedProviderRow(5000, 86400);

    runTuningMigration(QUOTA_MIGRATION)->up();

    $row = IpProvider::query()->where('key', 'ipqualityscore')->sole();

    expect((int) $row->daily_cap)->toBe(5000)->and((int) $row->ttl_seconds)->toBe(86400);
});

/** Both edits live in one migration, so a rollback can never half-revert the row. */
it('reverses both halves of the quota change together', function () {
    seedProviderRow(1000, 86400);

    $migration = runTuningMigration(QUOTA_MIGRATION);
    $migration->up();
    $migration->down();

    $row = IpProvider::query()->where('key', 'ipqualityscore')->sole();

    expect((int) $row->daily_cap)->toBe(1000)->and((int) $row->ttl_seconds)->toBe(86400);
});

it('promotes a seeded ip_risk_score row to block at the new threshold', function () {
    seedRiskRule(85, FraudAction::Flag);

    runTuningMigration(RISK_MIGRATION)->up();

    $rule = FraudRule::query()->where('key', 'ip_risk_score')->sole();

    expect($rule->action)->toBe(FraudAction::Block)
        ->and($rule->config['threshold'])->toBe(27);
});

it('leaves a threshold an operator already tuned alone', function () {
    seedRiskRule(60, FraudAction::Flag);

    runTuningMigration(RISK_MIGRATION)->up();

    $rule = FraudRule::query()->where('key', 'ip_risk_score')->sole();

    expect($rule->action)->toBe(FraudAction::Flag)
        ->and($rule->config['threshold'])->toBe(60);
});

it('leaves a rule an operator already moved to score alone', function () {
    seedRiskRule(85, FraudAction::Score);

    runTuningMigration(RISK_MIGRATION)->up();

    expect(FraudRule::query()->where('key', 'ip_risk_score')->sole()->action)->toBe(FraudAction::Score);
});

it('reverses cleanly, and only its own change', function () {
    seedRiskRule(85, FraudAction::Flag);

    $migration = runTuningMigration(RISK_MIGRATION);
    $migration->up();
    $migration->down();

    $rule = FraudRule::query()->where('key', 'ip_risk_score')->sole();

    expect($rule->action)->toBe(FraudAction::Flag)
        ->and($rule->config['threshold'])->toBe(85);
});

function seedRiskRule(int $threshold, FraudAction $action): void
{
    DB::table('fraud_rules')->where('key', 'ip_risk_score')->delete();

    FraudRule::query()->create([
        'key' => 'ip_risk_score',
        'name' => 'IP risk score',
        'description' => 'x',
        'stage' => FraudStage::PreClick,
        'enabled' => true,
        'action' => $action,
        'score_weight' => 25,
        'config' => ['threshold' => $threshold],
    ]);
}

function seedProviderRow(int $cap, int $ttl): void
{
    DB::table('ip_providers')->where('key', 'ipqualityscore')->delete();

    DB::table('ip_providers')->insert([
        'key' => 'ipqualityscore',
        'name' => 'IPQualityScore',
        'enabled' => false,
        'priority' => 20,
        'daily_cap' => $cap,
        'ttl_seconds' => $ttl,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}
