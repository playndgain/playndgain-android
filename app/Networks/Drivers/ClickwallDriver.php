<?php

namespace App\Networks\Drivers;

use App\Enums\ConnectorCategory;
use App\Models\Offer;
use App\Networks\AbstractDriver;
use App\Networks\Connector;
use App\Networks\Drivers\Concerns\NormalizesFields;
use App\Networks\DTO\ConnectionResult;
use App\Networks\DTO\OfferDTO;
use App\Networks\DTO\PostbackDTO;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Unpaginated catalogue, a hosted iframe, and a postback whose parameter names
 * are ours to choose rather than theirs to send.
 *
 * Four things about this network are unlike the others here:
 *
 *   - **The postback contract is a specification we hand over.** Clickwall
 *     substitutes macros into whatever URL is saved on the app. Paste exactly
 *     this into Apps → Edit Settings → Postback URL:
 *
 *     ```
 *     https://{host}/postback/clickwall?user={user_id}&reward={amount}
 *       &payout={payout}&tx={txid}&oid={offer_id}&offer_name={offer_name}
 *       &status={status}&ip={user_ip}
 *     ```
 *
 *     They then append `&sig=…` themselves. Do not add a `sig` macro — they
 *     do not publish one.
 *   - **`payout` is the USD figure and `amount` is not.** `{amount}` /
 *     `reward` is the publisher's own virtual currency. `{payout}` is "your
 *     actual earnings in USD". Crediting the ledger with `reward` would be
 *     off by whatever coin rate they configured — a thousand times over for
 *     a publisher on 1000 coins to the dollar. `reward` is still read, but
 *     only as a string, because it is the third term of the signature.
 *   - It signs, and the recipe is documented rather than guessed:
 *     `HMAC-SHA256("user_id={user_id}&oid={offer_id}&amount={amount}&tx_id={txid}", secret)`.
 *     The secret is the 64-character Postback Key on the app card, not the
 *     API key. The hash does **not** cover `{payout}` — that is their design.
 *   - A reversal is `status=rejected` carrying the original `{txid}`. Missing
 *     status is treated as `completed`, which is what their own PHP example
 *     does.
 *
 * They also host an iframe wall (`/app/iframe/{APP_ID}/{USER_ID}`). That is
 * the embed path, not this driver's: `{USER_ID}` on that URL is the embed
 * click, same slot as `user` on the postback. Replace `YOUR_APP_ID` on the
 * seeded wall URL before framing it.
 *
 * API access is request-and-approve (Tools → Apps → Request API). The iframe
 * and postbacks work without it; the catalogue does not.
 *
 * Docs: publisher dashboard (Web Offerwall, Postback, Signature, API Access)
 * and https://docs.clickwall.net/
 */
class ClickwallDriver extends AbstractDriver
{
    use NormalizesFields;

    private const ENDPOINT = 'https://clickwall.net/api/';

    /** The slot their click URL echoes back to us as `{user_id}`. */
    private const SUBID_PARAM = 'user_id';

    /** The placeholders their catalogue links arrive with. */
    private const SUBID_PLACEHOLDERS = [
        '[USER_ID]' => '{click_id}',
        '[user_id]' => '{click_id}',
    ];

    /**
     * `UK` is not an ISO-3166-1 alpha-2 code, and their own docs use it —
     * `country_code=UK`. It passes `parseCountries()`'s two-letter shape
     * check untouched, so without this the offer is stored as targeting a
     * country nothing geolocates to: every British user is filtered out of
     * an offer bought for them, quietly and forever.
     */
    private const COUNTRY_ALIASES = [
        'UK' => 'GB',
    ];

    /**
     * `Windows` / `macOS` / `PC` are theirs and `parseDevices()` does not
     * know them — it matches `desktop` and `web`. Left unmapped, a
     * Windows-only offer stores an empty device list, and an empty list
     * means *every* device: the offer is shown to the phones it cannot pay
     * for.
     */
    private const DEVICE_ALIASES = [
        'windows' => 'desktop',
        'win' => 'desktop',
        'macos' => 'desktop',
        'mac' => 'desktop',
        'pc' => 'desktop',
    ];

    /**
     * `app` and `game` are theirs and not ours. Both are CPI inventory —
     * falling through to `cpa` would mis-label the shelf, not fail the sync.
     *
     * @var array<string, string>
     */
    private const TYPES = [
        'app' => 'cpi',
        'game' => 'cpi',
    ];

    public static function key(): string
    {
        return 'clickwall';
    }

    public static function connector(): Connector
    {
        return new Connector('Clickwall', ConnectorCategory::Direct, website: 'https://clickwall.net');
    }

    /**
     * @return array<string, string>
     */
    public static function postbackQuery(): array
    {
        return [
            'user' => '{user_id}',
            'reward' => '{amount}',
            'payout' => '{payout}',
            'tx' => '{txid}',
            'oid' => '{offer_id}',
            'offer_name' => '{offer_name}',
            'status' => '{status}',
            'ip' => '{user_ip}',
        ];
    }

    /**
     * @return array<string, array{label: string, required?: bool, secret?: bool}>
     */
    public static function credentialSchema(): array
    {
        // Three values, three places, not interchangeable: the app id is
        // public (it sits in the iframe URL), the API key reads the
        // catalogue after an admin approves access, and the postback key
        // only verifies signatures. Labelling where each one comes from is
        // cheaper than finding out from a run of rejected conversions that
        // the wrong field was pasted.
        return [
            'app_id' => ['label' => 'App ID (Apps section)', 'required' => true],
            'api_key' => [
                'label' => 'API key (Tools → Apps, after approval — catalogue only)',
                'required' => false,
                'secret' => true,
            ],
            'secret_key' => [
                'label' => 'Postback key (app card — signs postbacks)',
                'required' => true,
                'secret' => true,
            ],
        ];
    }

    public function testConnection(): ConnectionResult
    {
        if (blank($this->credential('api_key'))) {
            return ConnectionResult::fail('Clickwall API key is missing. Request API access from Tools → Apps, or switch this network to Embedded wall only.');
        }

        $response = $this->http()->get(self::ENDPOINT, $this->authParams());

        if ($response->failed()) {
            return ConnectionResult::fail("Clickwall returned HTTP {$response->status()}: ".$this->rejection($response));
        }

        if (! $this->succeeded($response)) {
            return ConnectionResult::fail('Clickwall rejected the request: '.$this->rejection($response));
        }

        $count = $response->json('offers_count');
        $offers = $response->json('offers');

        return ConnectionResult::ok(
            'Connected.',
            is_numeric($count) ? (int) $count : (is_array($offers) ? count($offers) : 0),
        );
    }

    /**
     * One app is one call — their API is unpaginated and every documented
     * parameter except the keys is a filter.
     *
     * Called with no country/os filters on purpose. Those would narrow the
     * catalogue to a subset, and the wall does its own targeting off the
     * stored allow-lists.
     *
     * @return iterable<OfferDTO>
     */
    public function fetchOffers(): iterable
    {
        if (blank($this->credential('api_key'))) {
            throw new RuntimeException('Clickwall API key is missing.');
        }

        $response = $this->http()
            ->timeout(180)
            ->get(self::ENDPOINT, $this->authParams())
            ->throw();

        $rows = $response->json('offers');

        // Failing loudly rather than yielding nothing. A driver that returns
        // an empty catalogue tells the sync job the network has no offers
        // left, and the sweep deactivates every Clickwall offer we have —
        // the collapse guard only catches that above twenty offers. Throwing
        // puts the reason in `last_sync_error` where someone will read it.
        if (! $this->succeeded($response) || ! is_array($rows)) {
            throw new RuntimeException('Clickwall rejected the offers request: '.$this->rejection($response));
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $offer = $this->offer($row);

            if ($offer !== null) {
                yield $offer;
            }
        }
    }

    public function parsePostback(Request $request): ?PostbackDTO
    {
        $clickId = $this->nullableString($request->input('user'));
        $txId = $this->nullableString($request->input('tx'));
        $payout = $request->input('payout');

        // All three are ours to require: the template above sends them, and
        // the hash is computed over the click and the transaction. A fire
        // missing any one cannot be verified, so parsing it would only
        // produce something unattributable. `payout` is USD; `reward` is
        // coins and is not read for money.
        if ($clickId === null || $txId === null || ! is_numeric($payout)) {
            return null;
        }

        // `status` is the contract's own signal and the payout's sign agrees
        // with it; either alone would do, and reading both means a reversal
        // that arrives with only one of them still reverses. Getting this
        // backwards credits a chargeback.
        $reversed = strtolower((string) $request->input('status')) === 'rejected'
            || (float) $payout < 0;

        $amount = abs((float) $payout);

        return new PostbackDTO(
            // ASSUMPTION: each payable event of a multi-event offer fires
            // with its own `{txid}`. Their docs call it "a unique
            // transaction ID for this conversion" and describe no per-event
            // key, so this is the one detail to confirm against a live
            // multi-event fire. If they reuse one id across an offer's
            // events, the second event is dropped as a duplicate — a step
            // the publisher earned and does not see. Suffixing the offer id
            // here would fix that and break the reversal instead, because a
            // reversal quotes only the transaction id: this codebase would
            // rather miss a step than fail to claw back a chargeback.
            networkTxId: $txId,
            clickId: $clickId,
            payout: $reversed ? -$amount : $amount,
            status: $reversed ? 'reversed' : 'credited',
            offerExternalId: $this->nullableString($request->input('oid')),
            offerName: $this->nullableString($request->input('offer_name')),
            // No `endUserId`: `user` is Clickwall echoing our click id back,
            // not an identifier of theirs. The end user is the click's, and
            // the processor already reads it from there.
            ip: $this->nullableString($request->input('ip')),
            raw: $request->all(),
        );
    }

    public function verifyPostback(Request $request): bool
    {
        $secret = $this->credential('secret_key');

        // Fails closed. The secret is a required credential, so a blank one
        // is a misconfiguration, not permission to skip the check.
        if (blank($secret)) {
            return false;
        }

        // Documented, not guessed. The values are concatenated as the
        // strings they sent — casting `amount` to float and back turns
        // their `1.50` into `1.5` and every postback fails a signature
        // that was never wrong, which looks exactly like an outage on
        // their side.
        $payload = 'user_id='.(string) $request->input('user')
            .'&oid='.(string) $request->input('oid')
            .'&amount='.(string) $request->input('reward')
            .'&tx_id='.(string) $request->input('tx');

        $expected = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, strtolower((string) $request->input('sig', '')));
    }

    public function buildTrackingUrl(Offer $offer, string $clickId): string
    {
        // Through the same helper the catalogue went through, not a bare
        // str_replace. A stored link with no slot in it — hand-edited, or
        // synced before this driver normalized links — would otherwise be
        // handed to the user untouched, and every conversion off it comes
        // back with a blank subid that matches no click. Nobody is paid
        // and nothing logs an error.
        return str_replace('{click_id}', $clickId, $this->withSubIdMacro($offer->tracking_url));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function offer(array $row): ?OfferDTO
    {
        $offerId = $this->nullableString($row['id'] ?? null);

        if ($offerId === null) {
            return null;
        }

        $events = array_values(array_filter($row['events'] ?? [], 'is_array'));

        return new OfferDTO(
            externalId: $offerId,
            name: (string) ($row['name'] ?? 'Untitled offer'),
            trackingUrl: $this->withSubIdMacro((string) ($row['tracking_url'] ?? '')),
            // `payout` is documented as USD ("your actual earnings") and
            // their homepage sample is `1.50`. Their own events usually
            // add up to it, so it stays the authority rather than being
            // recomputed from them.
            //
            // ASSUMPTION: confirm a live row whose events do not add up
            // (the dashboard sample is `payout: 80` against `$0.80` of
            // events). If that sample is real, `payout` is coins and we
            // would show a $80 tile for an $0.80 offer.
            payout: (float) ($row['payout'] ?? 0),
            description: $this->nullableString($row['description'] ?? null),
            iconUrl: $this->nullableString($row['image_url'] ?? null),
            countries: $this->countries($row['countries'] ?? null),
            devices: $this->devices($row['operating_system'] ?? null),
            categories: $this->categoryNames($row['type'] ?? null),
            conversionType: $this->offerType($row['type'] ?? null),
            isMultiReward: count($events) > 1,
            goals: $this->goalList($events, 'event_name', 'event_payout'),
        );
    }

    /**
     * @return array<string, string>
     */
    private function authParams(): array
    {
        return [
            'app_id' => (string) $this->credential('app_id'),
            'api_key' => (string) $this->credential('api_key'),
        ];
    }

    private function succeeded(Response $response): bool
    {
        return $response->json('success') === true;
    }

    /** Whatever Clickwall put in the body instead of a catalogue, for the operator. */
    private function rejection(Response $response): string
    {
        return $this->nullableString($response->json('message'))
            ?? $this->nullableString($response->json('error'))
            ?? 'no error message given.';
    }

    /**
     * Their country list, with the codes that are not ISO put right before
     * `parseCountries()` decides what to keep.
     *
     * @return list<string>
     */
    private function countries(mixed $countries): array
    {
        $codes = collect(is_array($countries) ? $countries : (preg_split('/[,\s|]+/', (string) ($countries ?? '')) ?: []))
            ->map(fn (mixed $code): string => strtoupper(trim((string) (is_scalar($code) ? $code : ''))))
            ->map(fn (string $code): string => self::COUNTRY_ALIASES[$code] ?? $code)
            ->all();

        return $this->parseCountries($codes);
    }

    /**
     * Their OS list, with the desktop names translated before
     * `parseDevices()` decides what to keep.
     *
     * @return list<string>
     */
    private function devices(mixed $systems): array
    {
        $names = collect(is_array($systems) ? $systems : (preg_split('/[,\s|]+/', (string) ($systems ?? '')) ?: []))
            ->map(fn (mixed $system): string => strtolower(trim((string) (is_scalar($system) ? $system : ''))))
            ->map(fn (string $system): string => self::DEVICE_ALIASES[$system] ?? $system)
            ->all();

        return $this->parseDevices($names);
    }

    private function offerType(mixed $type): string
    {
        $normalized = strtolower(trim((string) ($this->nullableString($type) ?? '')));

        return $this->conversionType(self::TYPES[$normalized] ?? $normalized);
    }

    /**
     * Their click link with the click-id slot opened up.
     *
     * Some links put `[USER_ID]` in the path and a token after it; some end
     * on `[user_id]`. A link that arrives without either gets the macro
     * appended as a query parameter rather than a path segment — appending
     * a segment would sit after their tracking token and they would not
     * see it. Idempotent: a link already carrying the macro is left alone.
     */
    private function withSubIdMacro(string $link): string
    {
        $link = str_replace(
            array_keys(self::SUBID_PLACEHOLDERS),
            array_values(self::SUBID_PLACEHOLDERS),
            $link,
        );

        if ($link === '' || str_contains($link, '{click_id}')) {
            return $link;
        }

        return $link.(str_contains($link, '?') ? '&' : '?').self::SUBID_PARAM.'={click_id}';
    }
}
