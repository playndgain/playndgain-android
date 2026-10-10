<?php

namespace App\Services\Wall;

use App\Models\Offer;

/**
 * An offer's targeted countries, as the detail view and the SDK API both show
 * them. One helper so the HTML sheet and the native sheet cannot disagree on
 * which codes are real or what they are called.
 */
final class OfferCountries
{
    /**
     * ISO-2 or nothing. `offers.countries` is a JSON column fed by network
     * catalogues and by an admin typing into a tag field, and a code that is
     * not two letters cannot match `WallContext::$country` anyway.
     *
     * @return list<string>
     */
    public static function of(Offer $offer): array
    {
        return array_values(array_filter(
            (array) ($offer->countries ?? []),
            fn (mixed $code): bool => is_string($code) && preg_match('/^[A-Za-z]{2}$/', $code) === 1,
        ));
    }

    /**
     * `intl` already knows every country's name in both locales the wall
     * ships. Without the extension the code itself is still a truthful label.
     */
    public static function name(string $code): string
    {
        return class_exists(\Locale::class)
            ? (\Locale::getDisplayRegion('-'.$code, app()->getLocale()) ?: $code)
            : $code;
    }
}
