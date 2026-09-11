<?php

namespace App\Networks\Support;

/**
 * Comparing a shared postback secret, once, in the one way that is safe.
 *
 * Two properties, and the second is the one that gets forgotten:
 *
 *   - `hash_equals` rather than `===`, so the comparison takes the same time
 *     whatever the first wrong byte was.
 *   - **Blank expected fails closed.** A network row saved without its secret
 *     yet would otherwise compare `'' === ''` against a request that sent
 *     nothing and authenticate every caller on earth. The half-configured
 *     network is exactly when this is reached.
 */
final class PostbackSecret
{
    public static function matches(?string $expected, ?string $presented): bool
    {
        $expected = (string) $expected;
        $presented = (string) $presented;

        if ($expected === '' || $presented === '') {
            return false;
        }

        return hash_equals($expected, $presented);
    }

    /**
     * True when any of the presented values matches — a connector that accepts
     * its secret in a header *or* a query parameter still compares once per
     * candidate, in constant time, and never short-circuits on a blank one.
     *
     * @param  list<?string>  $presented
     */
    public static function matchesAny(?string $expected, array $presented): bool
    {
        $matched = false;

        foreach ($presented as $candidate) {
            $matched = self::matches($expected, $candidate) || $matched;
        }

        return $matched;
    }
}
