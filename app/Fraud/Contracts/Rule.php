<?php

namespace App\Fraud\Contracts;

use App\Enums\FraudStage;
use App\Fraud\FraudContext;
use App\Fraud\RuleResult;

/**
 * One fraud rule.
 *
 * docs/05 §2 calls this `FraudRule`, which is already the Eloquent model
 * holding the admin-tunable row. The row carries `enabled`, `action`,
 * `score_weight` and `config`; the class carries the logic. Naming the
 * interface `Rule` keeps both importable in the same file.
 *
 * Thresholds arrive as `$config` from the database on every call — a rule must
 * never read a threshold from a constant, or changing it needs a deploy.
 */
interface Rule
{
    /** Matches `fraud_rules.key`. */
    public static function key(): string;

    public static function stage(): FraudStage;

    /**
     * @param  array<string, mixed>  $config
     */
    public function evaluate(FraudContext $context, array $config): RuleResult;
}
