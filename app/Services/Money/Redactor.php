<?php

namespace App\Services\Money;

use Illuminate\Support\Str;

/**
 * One rule for what counts as a secret, shared by the outbound URL redactor and
 * the inbound postback log.
 *
 * The two used to disagree. `UrlRedactor` masked anything containing `secret`
 * or `key` plus the four signature names; the postback log dropped exactly
 * three keys from the body and nothing at all from the query, which is where
 * most partners put their shared secret. A log an admin reads across every
 * publisher is the last place a partner credential should survive in the clear.
 *
 * Values are masked rather than dropped. A dropped key is indistinguishable
 * from a key the partner never sent, and "they did not send the secret" is a
 * different support conversation from "they sent it and it was wrong".
 */
final class Redactor
{
    public const MASK = 'REDACTED';

    /** Words too short or too common to match as a substring without catching innocent names. */
    private const SENSITIVE_WORDS = ['signature', 'sig', 'hash', 'token'];

    /**
     * Deliberately a name rule, not a value rule. A value that looks random is
     * not evidence of a secret, and a secret that looks like a word is still a
     * secret; the parameter name is the only thing a partner reliably tells us.
     *
     * The four short words are matched per word rather than as a substring, so
     * `x-ayetstudios-security-hash` is caught and `design` is not. Ayet's HMAC
     * header is the reason: it is the one credential in this codebase that
     * arrives with the partner's brand in the middle of the name.
     */
    public static function isSensitive(string $key): bool
    {
        $key = strtolower(rawurldecode($key));

        if (Str::contains($key, ['secret', 'key', 'password'])) {
            return true;
        }

        return array_intersect(preg_split('/[-_.]/', $key) ?: [], self::SENSITIVE_WORDS) !== [];
    }

    /**
     * Mask every sensitive key, at any depth, keeping the shape intact.
     *
     * Keys survive so the row still records what arrived; header values are
     * lists rather than scalars, so a masked value keeps its list.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function keys(array $data): array
    {
        $redacted = [];

        foreach ($data as $key => $value) {
            if (self::isSensitive((string) $key)) {
                $redacted[$key] = self::mask($value);

                continue;
            }

            $redacted[$key] = is_array($value) ? self::keys($value) : $value;
        }

        return $redacted;
    }

    private static function mask(mixed $value): mixed
    {
        return is_array($value)
            ? array_map(fn (mixed $item): mixed => self::mask($item), $value)
            : self::MASK;
    }
}
