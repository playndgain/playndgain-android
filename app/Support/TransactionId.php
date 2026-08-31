<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Prefixed and readable in support conversations, random suffix so nothing
 * about volume or ordering is inferable. `conversions.trans_id` carries a
 * unique index; a collision at 16 random alphanumerics is not a practical
 * concern, and the index catches it if it ever happens.
 */
final class TransactionId
{
    public static function generate(?string $offerExternalId = null): string
    {
        return 'OFFER'.($offerExternalId ?? '').'-'.Str::random(16);
    }
}
