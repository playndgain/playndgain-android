<?php

namespace App\Networks\Contracts;

use App\Models\Offer;
use App\Networks\DTO\ConnectionResult;
use App\Networks\DTO\OfferDTO;
use App\Networks\DTO\PostbackDTO;
use Illuminate\Http\Request;

/**
 * One interface, many drivers, zero special cases leaking into the rest of the
 * app. Twelve networks disagree about auth, offer shape, targeting, postback
 * format, and how a reversal is signalled — **none of that may appear outside
 * `app/Networks/`**.
 *
 * Drivers are constructed with their Network model, so credentials come from
 * the encrypted column and never from config or `.env`.
 */
interface NetworkDriver
{
    /** Machine key. Matches networks.driver and the postback route segment. */
    public static function key(): string;

    /**
     * Credential fields this driver needs, for the admin form.
     *
     * @return array<string, array{label: string, required?: bool, secret?: bool}>
     */
    public static function credentialSchema(): array;

    /**
     * Query macros an admin pastes after `/postback/{key}`.
     *
     * Empty when the network fires a fixed payload at the bare URL, so the
     * displayed URL is the path and nothing else. Every pair is one
     * `parsePostback()` reads — dropping a macro from the dashboard template
     * fails silently.
     *
     * @return array<string, string> our query key => their substitution token
     */
    public static function postbackQuery(): array;

    /**
     * Absolute URL an admin copies into the network dashboard — the postback
     * route for this driver with its macro query string. The admin panel calls
     * it on the driver class, so it is part of the contract rather than an
     * implementation detail of AbstractDriver.
     */
    public static function postbackUrl(): string;

    /** Cheap auth check for the admin "Test connection" button. */
    public function testConnection(): ConnectionResult;

    /**
     * Full catalogue. Handles the network's own pagination internally.
     *
     * @return iterable<OfferDTO>
     */
    public function fetchOffers(): iterable;

    /**
     * Is there a catalogue to pull at all?
     *
     * False for a channel whose offers are entered by hand. The sync job reads
     * this before it does anything: an empty catalogue from a network that
     * never had one would deactivate every offer on it.
     */
    public function syncsCatalogue(): bool;

    /** Parse an inbound postback into our shape. Null when unparseable. */
    public function parsePostback(Request $request): ?PostbackDTO;

    /** Is this request genuinely from the network? */
    public function verifyPostback(Request $request): bool;

    /** Put our click_id into the network's subid slot. */
    public function buildTrackingUrl(Offer $offer, string $clickId): string;
}
