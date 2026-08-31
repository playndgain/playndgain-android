<?php

namespace App\Filament\Publisher\Support;

use App\Models\Publisher;
use App\Services\Money\Money;

/**
 * What an offer is worth to the publisher reading it — docs/08 §5 and §16.
 *
 * `offers.payout` is what the network pays *us*. The publisher's number is that
 * figure after their rev-share, and it is the only one any surface in this
 * panel may print. The leak this class exists to prevent is not one page
 * showing the gross — it is one page showing the gross while another shows the
 * net, which hands the reader our margin by subtraction and turns the next
 * renewal into a rate negotiation.
 *
 * Every publisher-facing price goes through here rather than each page
 * formatting its own, because a second formatter is a second place to forget
 * the share.
 */
final class PublisherPayout
{
    /**
     * Post-rev-share, in USD.
     *
     * Null and zero are different answers. Null is "this session has no
     * publisher" — an admin looking at the panel — and there is no share to
     * apply. Zero is a real publisher on a zero share, which is a
     * configuration to see, not to hide.
     */
    public static function net(string|float|int $gross, ?Publisher $publisher = null): ?string
    {
        $publisher ??= CurrentPublisher::get();

        return $publisher === null
            ? null
            : Money::share($gross, Money::format($publisher->rev_share, 2));
    }

    /**
     * The same figure as a label.
     *
     * Em dash, not $0.0000: with no publisher behind the session a zero reads
     * as "this offer pays nothing" rather than "this is not your panel".
     */
    public static function label(string|float|int $gross, ?Publisher $publisher = null): string
    {
        $net = self::net($gross, $publisher);

        return $net === null ? '—' : '$'.number_format((float) $net, 4);
    }
}
