<?php

namespace App\Fraud\Rules;

use App\Enums\FraudActionTaken;
use App\Enums\FraudStage;
use App\Fraud\AutoBan;
use App\Fraud\Contracts\Rule;
use App\Fraud\FraudContext;
use App\Fraud\RuleResult;
use App\Models\BannedEndUser;
use App\Models\FraudEvent;

/**
 * End user accumulated too many blocking events — docs/05 §3, §5.
 *
 * The one rule with a side effect. It runs last in the seeded order, so the
 * blocks the rest of this same pipeline pass recorded are already in the
 * window it counts — a user who trips five block rules at once is banned on
 * that conversion, not the next one.
 */
class AutoBanThresholdRule implements Rule
{
    public static function key(): string
    {
        return 'auto_ban_threshold';
    }

    public static function stage(): FraudStage
    {
        return FraudStage::PostConversion;
    }

    public function evaluate(FraudContext $context, array $config): RuleResult
    {
        $threshold = (int) ($config['blocks'] ?? 5);
        $windowHours = (int) ($config['window_hours'] ?? 24);
        $since = now()->subHours($windowHours);

        $blocks = FraudEvent::query()
            ->where('publisher_id', $context->publisherId())
            ->where('end_user_id', $context->endUserId)
            ->where('action_taken', FraudActionTaken::Blocked)
            ->where('created_at', '>=', $since)
            ->count();

        // Exactly at the threshold bans; one under does not.
        if ($blocks < $threshold) {
            return RuleResult::pass();
        }

        $ban = AutoBan::apply($context, $blocks, $windowHours);

        return RuleResult::trigger('end user auto-banned after repeated blocks', [
            'blocked_events' => $blocks,
            'threshold' => $threshold,
            'window_hours' => $windowHours,
            'since' => $since->toIso8601String(),
            'publisher_id' => $context->publisherId(),
            'app_id' => $context->appId(),
            'end_user_id' => $context->endUserId,
            'ban_id' => $ban?->getKey(),
            'ban_expires_at' => $ban?->expires_at?->toIso8601String(),
            'ban_days' => BannedEndUser::AUTO_BAN_DAYS,
        ]);
    }
}
