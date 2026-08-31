<?php

namespace App\Services\Wall;

/**
 * `apps.anonymize_ip` and the privacy-deletion path, docs/03 §3 and §4.
 */
class IpAnonymizer
{
    /** `offer_clicks.ip` is NOT NULL, so an erased click stores this instead. */
    public const REDACTED = '0.0.0.0';

    /**
     * Drops the host part and keeps the network part: enough to keep a country
     * lookup and a velocity rule working, not enough to identify a household.
     */
    public static function mask(string $ip): string
    {
        // Packed form rather than string surgery on the text: `fe80::1` and
        // `2001:db8:0:0:0:0:0:1` are the same address written two ways, and
        // splitting either on ':' gets the prefix wrong.
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return self::REDACTED;
        }

        $masked = strlen($packed) === 16
            // /48 — the routing prefix an ISP hands out. Everything below it
            // identifies the subscriber.
            ? substr($packed, 0, 6).str_repeat("\0", 10)
            : substr($packed, 0, 3)."\0";

        $text = inet_ntop($masked);

        return $text === false ? self::REDACTED : $text;
    }

    /**
     * A stable pseudonym for an erased end user. Salted with the app secret so
     * the same publisher can still see that two old rows were one person while
     * nobody can recover the ID by hashing a user list.
     */
    public static function pseudonym(string $secret, string $endUserId): string
    {
        return 'anon_'.substr(hash_hmac('sha256', $endUserId, $secret), 0, 32);
    }
}
