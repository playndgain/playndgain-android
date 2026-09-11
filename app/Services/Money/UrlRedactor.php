<?php

namespace App\Services\Money;

/**
 * Postback logs are visible to the publisher in their own panel and to admins
 * across every publisher. An unredacted log is a credential leak between
 * accounts, so the signature and anything named like a secret never reach
 * storage in the clear.
 *
 * The rule itself lives in `Redactor`, which the inbound postback log uses on
 * arrays. This class is the same rule applied to a URL's query string.
 */
final class UrlRedactor
{
    public const MASK = Redactor::MASK;

    public static function redact(string $url): string
    {
        return (string) preg_replace_callback(
            '/([?&])([^=&#]+)=([^&#]*)/',
            fn (array $match): string => Redactor::isSensitive($match[2])
                ? $match[1].$match[2].'='.self::MASK
                : $match[0],
            $url
        );
    }
}
