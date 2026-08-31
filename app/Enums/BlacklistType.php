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
        return array_values(array_filter(
            self::cases(),
            fn (self $type): bool => $type !== self::Network,
        ));
    }
}
