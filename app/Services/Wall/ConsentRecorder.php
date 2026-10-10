<?php

namespace App\Services\Wall;

use App\Models\Consent;

/**
 * Writes the consent row — docs/03-offerwall.md §4.
 *
 * One home for it because two surfaces ask: the HTML consent form and the SDK
 * API. A second copy would drift on exactly the fields that prove consent was
 * given, and those are the ones a regulator reads.
 */
final class ConsentRecorder
{
    /** Written to the row so a later purpose change is visibly a different consent. */
    public const PURPOSES = ['fraud_prevention', 'reward_attribution', 'network_delivery'];

    public static function record(WallContext $context, bool $granted): void
    {
        $app = $context->app;

        Consent::query()->updateOrCreate(
            ['app_id' => $app->id, 'end_user_id' => $context->endUserId],
            [
                'granted' => $granted,
                'purposes' => self::PURPOSES,
                // Evidence of consent, so it follows the app's own IP rule
                // rather than storing more than the wall itself would.
                'ip' => $app->anonymize_ip ? IpAnonymizer::mask($context->ip) : $context->ip,
                // Where the user really is. A test-mode `?country=` preview is
                // not an answer about them; the HTML form drops it before it
                // gets here, the SDK API cannot, so it is undone here for both.
                'country' => $context->countryOverridden ? $context->verdict->country : $context->country,
                // Hashed: the row proves which client agreed without keeping a
                // fingerprintable string for the life of the account.
                'user_agent_hash' => hash('sha256', (string) $context->userAgent),
            ],
        );
    }
}
