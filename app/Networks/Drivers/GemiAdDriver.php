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
 * One placement, one catalogue call, three credentials — and a postback whose
 * parameter names are ours to choose rather than theirs to send.
 *
 * Four things about this network are unlike the others here:
 *
 *   - **The postback contract is a specification we hand over, not an
 *     assumption we make.** GemiAd fires whatever URL is saved in the
 *     placement's settings, substituting macros into it, so the query keys
 *     below are the ones this driver reads because they are the ones we wrote.
 *     Paste exactly this into Placement settings → Postback URL:
 *
 *     ```
 *     https://{host}/postback/gemiad?userId={USER_ID}&offerId={OFFER_ID}
 *       &offerName={OFFER_NAME}&eventName={EVENT_NAME}&payout={PAYOUT}
 *       &txid={TXID}&status={STATUS}&ip={IPADDR}&hash={HASH}
 *     ```
 *
 *     `{PAYOUT}` and not `{REWARD}`: payout is USD, which is what
 *     `PostbackDTO` is denominated in. Reward is the same conversion run
 *     through the placement's exchange rate into the publisher's own currency,
 *     and crediting our ledger with it would be off by whatever that rate is.
 *   - It signs, and the recipe is documented rather than guessed:
 *     `sha256(userId + offerId + txId + secretKey)`. The secret is on Profile
 *     Settings, not on the placement — see `credentialSchema()`.
 *   - A reversal is a `status=rejected` fire carrying the original `{TXID}` and
 *     a negative payout, so the chargeback lands on the key its own credit
 *     produced with no composite needed.
 *   - Their catalogue endpoint is rate limited to 5 requests per minute per
 *     placement, and answers a sixth with HTTP 429. One sweep is one request
 *     and the sync interval is measured in tens of minutes, so the only way to
 *     trip it is a human leaning on Test connection.
 *
 * ponytail: their docs publish the endpoint as plain `http`, and this driver
 * calls it over `https` — the API key rides in the query string, where http
 * would put it in front of every hop between here and them. If they turn out
 * not to terminate TLS, `testConnection()` fails at the admin's button rather
 * than silently, and the ceiling is one constant.
 */
class GemiAdDriver extends AbstractDriver
{
    use NormalizesFields;

    private const ENDPOINT = 'https://api.gemiwall.com/api/offers/static';

    /** The slot their click URL echoes back to us as `{USER_ID}`. */
    private const SUBID_PARAM = 'userId';

    /** The placeholder their catalogue links arrive with. */
    private const SUBID_PLACEHOLDERS = [
        '[USER_ID]' => '{click_id}',
    ];

    /**
     * `UK` is not an ISO-3166-1 alpha-2 code, and their own documentation uses
     * it — `["US", "UK", "CA"]`. It passes `parseCountries()`'s two-letter shape
     * check untouched, so without this the offer is stored as targeting a
     * country nothing geolocates to: every British user is filtered out of an
     * offer bought for them, quietly and forever.
     */
    private const COUNTRY_ALIASES = [
        'UK' => 'GB',
    ];

    public static function key(): string
    {
        return 'gemiad';
    }

    public static function connector(): Connector
    {
        return new Connector('GemiAd', ConnectorCategory::Direct, website: 'https://gemiwall.com');
    }

    /**
     * @return array<string, string>
     */
    public static function postbackQuery(): array
    {
        return [
            'userId' => '{USER_ID}',
            'offerId' => '{OFFER_ID}',
            'offerName' => '{OFFER_NAME}',
            'eventName' => '{EVENT_NAME}',
            'payout' => '{PAYOUT}',
            'txid' => '{TXID}',
            'status' => '{STATUS}',
            'ip' => '{IPADDR}',
            'hash' => '{HASH}',
        ];
    }

    /**
     * @return array<string, array{label: string, required?: bool, secret?: bool}>
     */
    public static function credentialSchema(): array
    {
        // The two keys live in different places and are not interchangeable:
        // the API key is on the placement (Placement settings), the secret is
        // on the account (Profile Settings) and exists only to verify postback
        // hashes. Labelling where each one comes from is cheaper than finding
        // out from a 401 that the wrong field was pasted.
        return [
            'placement_id' => ['label' => 'Placement ID', 'required' => true],
            'api_key' => ['label' => 'API key (placement settings)', 'required' => true, 'secret' => true],
            'secret_key' => [
                'label' => 'Secret key (profile settings — signs postbacks)',
                'required' => true,
                'secret' => true,
            ],
        ];
    }

    public function testConnection(): ConnectionResult
    {
        $response = $this->http()->get(self::ENDPOINT, $this->authParams());

        // Their rejections are honest HTTP: 400 a missing placement, 401 a bad
        // key, 404 a placement that is not there or a publisher that is not
        // active, 429 the five-a-minute limit. The body says which, and the
        // admin staring at the button is the person who can fix it.
        if ($response->failed()) {
            return ConnectionResult::fail("GemiAd returned HTTP {$response->status()}: ".$this->rejection($response));
        }

        if (! $this->succeeded($response)) {
            return ConnectionResult::fail('GemiAd rejected the request: '.$this->rejection($response));
        }

        $offers = $response->json('offers');

        return ConnectionResult::ok('Connected.', is_array($offers) ? count($offers) : 0);
    }

    /**
     * One placement is one call — their static endpoint is unpaginated.
     *
     * ponytail: read into memory rather than streamed off disk. One placement's
     * catalogue fits; if one stops fitting, stream the `offers` array.
     *
     * @return iterable<OfferDTO>
     */
    public function fetchOffers(): iterable
    {
        $response = $this->http()
            ->timeout(180)
            ->get(self::ENDPOINT, $this->authParams())
            ->throw();

        $rows = $response->json('offers');

        // Failing loudly rather than yielding nothing. A driver that returns an
        // empty catalogue tells the sync job the network has no offers left, and
        // the sweep deactivates every GemiAd offer we have — the collapse guard
        // only catches that above twenty offers. Throwing puts the reason in
        // `last_sync_error` where someone will read it.
        if (! $this->succeeded($response) || ! is_array($rows)) {
            throw new RuntimeException('GemiAd rejected the offers request: '.$this->rejection($response));
        }

        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['id'])) {
                continue;
            }

            $events = array_values(array_filter($row['events'] ?? [], 'is_array'));

            yield new OfferDTO(
                externalId: (string) $row['id'],
                name: (string) ($row['name'] ?? 'Untitled offer'),
                trackingUrl: $this->withSubIdMacro((string) ($row['url'] ?? '')),
                // `payout` is documented as the total for completing the offer
                // and their own example adds up to it, so it stays the authority
                // rather than being recomputed from the events below.
                payout: (float) ($row['payout'] ?? 0),
                // Localized: `{"en": "..."}`. English only — the wall has no
                // other locale to fall back to yet.
                description: $this->nullableString(data_get($row, 'description.en')),
                iconUrl: $this->nullableString($row['icon'] ?? null),
                previewUrl: $this->nullableString($row['previewUrl'] ?? null),
                countries: $this->countries($row['country'] ?? []),
                devices: $this->parseDevices($row['device'] ?? []),
                // A single word — app, web, survey, click or game — which is the
                // shelf a user would recognise on the wall.
                categories: $this->categoryNames($row['category'] ?? null),
                // Their cpi/cpa/cpl overlap ours; cpc and cpe do not, and both
                // fall to `cpa` rather than failing the sync over a label.
                conversionType: $this->conversionType($row['trackingType'] ?? null),
                isMultiReward: count($events) > 1,
                // Each event's localized action is the step's label, and a step
                // with no payout is an intermediate one their docs warn about —
                // it prices at zero rather than being dropped, because the user
                // still has to do it.
                goals: $this->goalList($events, 'action.en', 'payout'),
                dailyCap: is_numeric($row['dailyCap'] ?? null) ? (int) $row['dailyCap'] : null,
            );
        }
    }

    public function parsePostback(Request $request): ?PostbackDTO
    {
        $clickId = $this->nullableString($request->input('userId'));
        $txId = $this->nullableString($request->input('txid'));
        $payout = $request->input('payout');

        // All three are ours to require: the template above sends them, and the
        // hash is computed over two of them. A fire missing any one cannot be
        // verified, so parsing it would only produce something unattributable.
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
            // ASSUMPTION: each payable event of a multi-event offer fires with
            // its own `{TXID}`. Their docs say a transaction id is "the same ID
            // used for reversals" and describe no per-event key, so this is the
            // one detail to confirm against a live multi-event fire. If they
            // reuse one id across an offer's events, the second event is
            // dropped as a duplicate — which is a step the publisher earned and
            // does not see. Suffixing the event id here would fix that and
            // break the reversal instead, because a reversal quotes only the
            // transaction id: this codebase would rather miss a step than fail
            // to claw back a chargeback.
            networkTxId: $txId,
            clickId: $clickId,
            payout: $reversed ? -$amount : $amount,
            status: $reversed ? 'reversed' : 'credited',
            offerExternalId: $this->nullableString($request->input('offerId')),
            // The event that fired names the conversion better than the offer
            // does — "Complete tutorial" rather than "Download App" for the
            // second of two fires on the same offer.
            offerName: $this->nullableString($request->input('eventName'))
                ?? $this->nullableString($request->input('offerName')),
            // No `endUserId`: `userId` is GemiAd echoing our click id back, not
            // an identifier of theirs. The end user is the click's, and the
            // processor already reads it from there.
            ip: $this->nullableString($request->input('ip')),
            raw: $request->all(),
        );
    }

    public function verifyPostback(Request $request): bool
    {
        $secret = $this->credential('secret_key');

        // Fails closed. The secret is a required credential, so a blank one is
        // a misconfiguration, not permission to skip the check.
        if (blank($secret)) {
            return false;
        }

        // Documented, not guessed: sha256(userId + offerId + txId + secretKey).
        // The source-IP allowlist the controller enforces first is the second
        // gate, not the alternative to this one — GemiAd fires from a single
        // published address, seeded onto the network row.
        $expected = hash('sha256',
            (string) $request->input('userId')
            .(string) $request->input('offerId')
            .(string) $request->input('txid')
            .$secret
        );

        return hash_equals($expected, strtolower((string) $request->input('hash', '')));
    }

    public function buildTrackingUrl(Offer $offer, string $clickId): string
    {
        // Through the same helper the catalogue went through, not a bare
        // str_replace. A stored link with no macro in it — hand-edited, or
        // synced before this driver normalized links — would otherwise be
        // handed to the user untouched, and every conversion off it comes back
        // with a blank subid that matches no click. Nobody is paid and nothing
        // logs an error.
        return str_replace('{click_id}', $clickId, $this->withSubIdMacro($offer->tracking_url));
    }

    /**
     * @return array<string, string>
     */
    private function authParams(): array
    {
        return [
            'placementId' => (string) $this->credential('placement_id'),
            'apiKey' => (string) $this->credential('api_key'),
        ];
    }

    private function succeeded(Response $response): bool
    {
        return $response->json('success') === true;
    }

    /** Whatever GemiAd put in the body instead of a catalogue, for the operator. */
    private function rejection(Response $response): string
    {
        $message = $this->nullableString($response->json('error'))
            ?? $this->nullableString($response->json('message'));

        return $message ?? 'no error message given.';
    }

    /**
     * Their country list, with the codes that are not ISO put right before
     * `parseCountries()` decides what to keep.
     *
     * @return list<string>
     */
    private function countries(mixed $countries): array
    {
        $codes = collect(is_array($countries) ? $countries : [$countries])
            ->map(fn (mixed $code): string => strtoupper(trim((string) (is_scalar($code) ? $code : ''))))
            ->map(fn (string $code): string => self::COUNTRY_ALIASES[$code] ?? $code)
            ->all();

        return $this->parseCountries($codes);
    }

    /** Idempotent: a link that already carries the macro is left alone. */
    private function withSubIdMacro(string $link): string
    {
        $link = str_replace(
            array_keys(self::SUBID_PLACEHOLDERS),
            array_values(self::SUBID_PLACEHOLDERS),
            $link,
        );

        if (str_contains($link, '{click_id}')) {
            return $link;
        }

        return $link.(str_contains($link, '?') ? '&' : '?').self::SUBID_PARAM.'={click_id}';
    }
}
