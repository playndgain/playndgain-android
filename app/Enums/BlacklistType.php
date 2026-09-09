<?php

namespace App\Enums;

enum BlacklistType: string
{
    case Offer = 'offer';
    case Country = 'country';
    case Device = 'device';
    case Network = 'network';

    /**
     * Publishers block offers, geos and devices. Network is admin-only —
     * naming a supplier is how a publisher goes around us.
     *
     * @return list<self>
     */
    public static function publisherCases(): array
    {
        // Named rather than filtered from cases(): a case added later stays
        // admin-only until someone decides publishers should see it.
        return [self::Offer, self::Country, self::Device];
    }
}
