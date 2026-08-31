<?php

namespace App\Models;

use App\Enums\FraudAction;
use App\Enums\FraudActionTaken;
use App\Enums\FraudStage;
use App\Fraud\FraudContext;
use App\Fraud\RuleResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class FraudEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'stage' => FraudStage::class,
        'action_taken' => FraudActionTaken::class,
        'context' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Nothing blocks silently. Every trigger writes the rule key, the stage,
     * the subject and the inputs the rule saw — so "why didn't my user get
     * credited" is one query away rather than a re-run of the pipeline.
     */
    public static function record(
        FraudRule $rule,
        FraudStage $stage,
        FraudContext $context,
        RuleResult $result,
    ): self {
        $subject = $context->subject();

        return static::create([
            'rule_key' => $rule->key,
            'stage' => $stage,
            'subject_type' => $subject?->getMorphClass() ?? '',
            'subject_id' => $subject?->getKey() ?? 0,
            'app_id' => $context->appId(),
            'publisher_id' => $context->publisherId(),
            'end_user_id' => $context->endUserId,
            'action_taken' => match ($rule->action) {
                FraudAction::Block => FraudActionTaken::Blocked,
                FraudAction::Flag => FraudActionTaken::Flagged,
                FraudAction::Score => FraudActionTaken::Scored,
            },
            'context' => ['reason' => $result->reason] + $result->context,
            'created_at' => now(),
        ]);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Publisher, $this> */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(Publisher::class);
    }

    /** @return BelongsTo<App, $this> */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }
}
