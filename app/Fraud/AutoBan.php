<?php

namespace App\Fraud;

use App\Enums\BannedBy;
use App\Models\BannedEndUser;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The ban half of `auto_ban_threshold` — docs/05 §5.
 *
 * Separate from the rule because the write has three rules of its own that
 * have nothing to do with the threshold, and all three are about not making
 * things permanently worse by accident.
 */
final class AutoBan
{
    /**
     * @param  int  $blocks  Blocking events counted, for the reason string.
     * @param  int  $hours  The window they were counted in.
     */
    public static function apply(FraudContext $context, int $blocks, int $hours): ?BannedEndUser
    {
        $keys = [
            'publisher_id' => $context->publisherId(),
            'app_id' => $context->appId(),
            'end_user_id' => $context->endUserId,
        ];

        try {
            $existing = BannedEndUser::query()->withoutGlobalScopes()->where($keys)->first();

            // Never soften an existing ban. A publisher's manual ban and a
            // permanent one are human decisions; overwriting either with a
            // seven-day system ban would quietly unban someone in a week.
            if ($existing !== null && ($existing->banned_by === BannedBy::Publisher || $existing->expires_at === null)) {
                return $existing;
            }

            $ban = $existing ?? new BannedEndUser($keys);

            $ban->forceFill([
                'banned_by' => BannedBy::System,
                'reason' => "auto: {$blocks} blocked events in {$hours}h",

                // Auto-bans expire after seven days. A permanent automatic ban
                // on a shared mobile IP eventually bans a real user forever and
                // nobody ever finds out why.
                'expires_at' => now()->addDays(BannedEndUser::AUTO_BAN_DAYS),
            ])->save();

            return $ban;
        } catch (Throwable $e) {
            // Two postbacks for the same end user racing the unique index. The
            // pipeline swallows a throwing rule entirely, so letting this
            // escape would lose the block as well as the ban.
            Log::error('Auto-ban write failed', $keys + ['error' => $e->getMessage()]);

            return null;
        }
    }
}
