<?php

namespace App\Support;

use App\Models\Offer;

/**
 * The events a multi-reward offer pays on, in the order the user completes them.
 *
 * Drivers already normalise `offers.goals` to `[{name, payout}]` in USD. A
 * single-step offer stores nothing — the offer *is* the event — so callers that
 * need a list to render get one synthetic row rather than an empty panel.
 */
final class OfferEvents
{
    /**
     * Stored events only. Empty when the offer pays once.
     *
     * @return list<array{name: string, payout: float}>
     */
    public static function raw(Offer $offer): array
    {
        $events = [];

        foreach ((array) ($offer->goals ?? []) as $goal) {
            if (! is_array($goal) || blank($goal['name'] ?? null)) {
                continue;
            }

            $events[] = [
                'name' => (string) $goal['name'],
                'payout' => (float) ($goal['payout'] ?? 0),
            ];
        }

        return $events;
    }

    /**
     * What a human reads. One row when the offer has no stored events.
     *
     * @return list<array{name: string, payout: float}>
     */
    public static function of(Offer $offer, string $fallbackName = 'Complete the offer'): array
    {
        $events = self::raw($offer);

        return $events !== [] ? $events : [[
            'name' => $fallbackName,
            'payout' => (float) $offer->payout,
        ]];
    }

    public static function count(Offer $offer): int
    {
        return count(self::of($offer));
    }
}
