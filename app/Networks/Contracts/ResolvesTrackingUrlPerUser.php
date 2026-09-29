<?php

namespace App\Networks\Contracts;

use App\Models\Offer;
use App\Models\OfferClick;

/**
 * For a network whose tracking links are minted per user, so the link stored
 * at sync time belongs to somebody else and must never reach a real user.
 *
 * `ClickController` asks this instead of `buildTrackingUrl()` when the driver
 * implements it. `buildTrackingUrl()` stays for the admin and publisher
 * previews, which are not users and may follow the stored link.
 */
interface ResolvesTrackingUrlPerUser
{
    /**
     * The link for this click's user, carrying the click id, or null when the
     * network will not give this user this offer right now. Null is never a
     * cue to fall back to the stored link.
     */
    public function resolveTrackingUrl(Offer $offer, OfferClick $click): ?string;
}
