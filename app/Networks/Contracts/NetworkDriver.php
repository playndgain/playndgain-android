<?php

namespace App\Networks\Contracts;

use App\Models\Offer;
use App\Networks\Connector;
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
    /** Machine key. Matches networks.driver; the postback route carries a key per row. */
    public static function key(): string;

    /**
     * Human name, category, and the two facts the app needs about this
     * integration that are not visible from the key.
     *
     * Static and on the class because none of it varies per `networks` row —
     * a second Swaarm tenant is the same connector with different credentials.
     */
    public static function connector(): Connector;

    /**
     * Credential fields this driver needs, for the admin form.
     *
     * `secret` masks the input and keeps the value out of the rendered form
     * until the Reveal action is used. `rule` is for a field the driver has to
     * be able to refuse — the platform connectors' tenant URLs, which are the
     * only admin-typed values this application ever dials — and is a plain
     * predicate so the schema stays data rather than Filament.
     *
     * @return array<string, array{label: string, required?: bool, secret?: bool, rule?: callable(?string): bool, rule_message?: string, placeholder?: string}>
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
     * Absolute URL an admin copies into *this network's* dashboard — the
     * postback route for the row's own `postback_key` with the macro query
     * string appended.
     *
     * An instance method because the callback URL is per account, not per
     * driver: two tenants on the same platform each get their own key, and a
     * URL rendered from the driver key alone would send both partners' fires to
     * whichever row was found first.
     */
    public function postbackUrl(): string;

    /**
     * The same URL rendered for an arbitrary key, with no row involved.
     *
     * The macro template belongs to the class, so docs and tests can render the
     * thing an admin will paste before a network exists to paste it for.
     */
    public static function postbackPath(string $postbackKey): string;

    /**
     * Offers this driver refused to yield on the last `fetchOffers()` run,
     * tallied by reason.
     *
     * The sync job reads this after the generator is exhausted; an empty array
     * means either nothing was skipped or the driver never skips.
     *
     * @return array<string, array{count: int, samples: list<string>}>
     */
    public function skippedOffers(): array;

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
