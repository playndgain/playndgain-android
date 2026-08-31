<?php

namespace App\Fraud\Rules;

use App\Enums\FraudStage;
use App\Fraud\Contracts\Rule;
use App\Fraud\FraudContext;
use App\Fraud\RuleResult;
use App\Models\BannedEndUser;
use Illuminate\Database\Eloquent\Builder;

/**
 * User banned by the publisher or auto-banned by us — docs/05 §3.
 */
class BannedEndUserRule implements Rule
{
    public static function key(): string
    {
        return 'banned_end_user';
    }

    public static function stage(): FraudStage
    {
        return FraudStage::PreClick;
    }

    public function evaluate(FraudContext $context, array $config): RuleResult
    {
        // Scoped to the publisher who owns the traffic. End user IDs are not
        // globally unique — `user_1` on two publishers is two people — so a
        // global lookup would ban a stranger. A null `app_id` is a ban across
        // every app that publisher owns.
        $ban = BannedEndUser::query()
            ->withoutGlobalScopes()
            ->where('publisher_id', $context->publisherId())
            ->where('end_user_id', $context->endUserId)
            ->where(fn (Builder $query) => $query
                ->whereNull('app_id')
                ->orWhere('app_id', $context->appId()))
            ->active()
            ->first();

        if ($ban === null) {
            return RuleResult::pass();
        }

        return RuleResult::trigger('end user is banned', [
            'ban_id' => $ban->getKey(),
            'banned_by' => $ban->banned_by->value,
            'ban_reason' => $ban->reason,
            'ban_scope' => $ban->app_id === null ? 'publisher' : 'app',
            'expires_at' => $ban->expires_at?->toIso8601String(),
            'publisher_id' => $context->publisherId(),
            'end_user_id' => $context->endUserId,
        ]);
    }
}
