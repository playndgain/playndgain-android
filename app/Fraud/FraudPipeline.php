<?php

namespace App\Fraud;

use App\Enums\FraudAction;
use App\Enums\FraudStage;
use App\Models\FraudEvent;
use App\Models\FraudRule;
use Illuminate\Support\Facades\Log;
use Throwable;

class FraudPipeline
{
    public function __construct(private readonly RuleRegistry $registry) {}

    /**
     * **Every enabled rule runs**, even after a block is decided. Stopping at
     * the first block loses the rest of the signal, and the full picture is
     * what tuning depends on.
     */
    public function evaluate(FraudStage $stage, FraudContext $context): Decision
    {
        $score = 0;
        $blocked = false;
        $reason = null;
        $fired = [];

        $rules = FraudRule::query()
            ->where('stage', $stage)
            ->where('enabled', true)
            ->get();

        foreach ($rules as $row) {
            $rule = $this->registry->resolve($row->key);

            if ($rule === null) {
                // An admin can create a rule row from the panel for a key that
                // has no class. Skip it loudly rather than taking the wall down.
                Log::warning('Fraud rule row has no registered class', ['key' => $row->key]);

                continue;
            }

            try {
                $result = $rule->evaluate($context, $row->config ?? []);
            } catch (Throwable $e) {
                // A rule that throws must not block a click or swallow a
                // conversion. It is a signal that failed, not a verdict.
                Log::error('Fraud rule threw', ['key' => $row->key, 'error' => $e->getMessage()]);

                continue;
            }

            if (! $result->triggered) {
                continue;
            }

            $fired[] = $row->key;
            $score += (int) $row->score_weight;

            FraudEvent::record($row, $stage, $context, $result);

            if ($row->action === FraudAction::Block && ! $blocked) {
                $blocked = true;
                $reason = $row->key;
            }
        }

        return new Decision($blocked, min($score, 100), $reason, $fired);
    }
}
