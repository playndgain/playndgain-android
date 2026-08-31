<?php

namespace App\Services\Money;

use Illuminate\Support\Str;

/**
 * Postback logs are visible to the publisher in their own panel and to admins
 * across every publisher. An unredacted log is a credential leak between
 * accounts, so the signature and anything named like a secret never reach
 * storage in the clear.
 */
final class UrlRedactor
{
    public const MASK = 'REDACTED';

    public static function redact(string $url): string
    {
        return (string) preg_replace_callback(
            '/([?&])([^=&#]+)=([^&#]*)/',
            fn (array $match): string => self::isSensitive($match[2])
                ? $match[1].$match[2].'='.self::MASK
                : $match[0],
            $url
        );
    }

    private static function isSensitive(string $parameter): bool
    {
        $parameter = strtolower(rawurldecode($parameter));

        return Str::contains($parameter, ['secret', 'key'])
            || in_array($parameter, ['signature', 'sig', 'hash', 'token'], true);
    }
}
