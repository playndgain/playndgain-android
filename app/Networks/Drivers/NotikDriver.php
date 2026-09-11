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
use Illuminate\Http\Request;
use Illuminate\Support\Uri;

/**
 * Query-string key auth over a page-at-a-time catalogue, and a postback they
 * document rather than one we guessed — docs/networks/notik.md.
 *
 * Catalogue: `GET /api/v2/get-offers/all`, follow `offers.next_page_url`, put
 * the credentials back on every hop. Tracking links carry `{user_id}`; the
 * click/session slot they echo is `s1`.
 *
 * Postback (their names): `txn_id`, `s1`, `payout`, `user_id`, `conversion_ip`,
 * `rewarded_txn_id` on a chargeback. They sign `hash` as HMAC-SHA1 of the
 * callback URL with `hash` stripped, using the app secret. Chargebacks arrive
 * as a negative `payout` plus the original `txn_id` in `rewarded_txn_id`.
 * Firing address they publish: `158.69.116.45`.
 */
class NotikDriver extends AbstractDriver
{
    use NormalizesFields;

    private const ENDPOINT = 'https://notik.me/api/v2/get-offers/all';

    /** Their click/session slot — iframe docs, postback `s1`. */
    private const SUBID_PARAM = 's1';

    /**
     * The placeholder tokens catalogue links arrive with.
     * Notik API responses ship `[user_id]` (or `[USER_ID]`, `{user_id}`, `USER_ID_HERE`).
     */
    private const USER_PLACEHOLDERS = [
        '[USER_ID]' => '{click_id}',
        '[user_id]' => '{click_id}',
        '{USER_ID}' => '{click_id}',
        '{user_id}' => '{click_id}',
        'USER_ID_HERE' => '{click_id}',
        '{{userid}}' => '{click_id}',
    ];

    public static function key(): string
    {
        return 'notik';
    }

    public static function connector(): Connector
    {
        return new Connector('Notik', ConnectorCategory::Direct, website: 'https://notik.me');
    }

    /**
     * @return array<string, array{label: string, required?: bool, secret?: bool}>
     */
    public static function credentialSchema(): array
    {
        return [
            'api_key' => ['label' => 'API Key', 'required' => true, 'secret' => true],
            'pub_id' => ['label' => 'Publisher ID', 'required' => true],
            'app_id' => ['label' => 'App ID', 'required' => true],
            'postback_secret' => ['label' => 'Postback Secret', 'required' => true, 'secret' => true],
        ];
    }

    public function testConnection(): ConnectionResult
    {
        $response = $this->http()->get(self::ENDPOINT, $this->authParams());

        if ($response->failed()) {
            return ConnectionResult::fail("Notik returned HTTP {$response->status()}.");
        }

        $offers = $response->json('offers.data');

        return ConnectionResult::ok('Connected.', is_array($offers) ? count($offers) : 0);
    }

    /**
     * Notik paginates and hands back the next page as a full URL, so the loop
     * follows what it is given rather than counting pages itself.
     *
     * @return iterable<OfferDTO>
     */
    public function fetchOffers(): iterable
    {
        $url = $this->authorized(self::ENDPOINT);

        while ($url !== null) {
            $response = $this->http()->timeout(180)->get($url)->throw();

            foreach ($this->rowsFrom($response->json()) as $row) {
                $offer = $this->toOffer($row);

                if ($offer !== null) {
                    yield $offer;
                }
            }

            $next = $response->json('offers.next_page_url') ?? $response->json('next_page_url');

            // `next_page_url` carries the cursor and nothing else, so the
            // credentials go back on before the next hop — otherwise page two
            // is a 401 and the catalogue silently ends after page one.
            $url = is_string($next) && $next !== '' ? $this->authorized($next) : null;
        }
    }

    public function parsePostback(Request $request): ?PostbackDTO
    {
        $transId = $this->nullableString($request->input('txn_id'));
        $payout = $request->input('payout');

        if ($transId === null || ! is_numeric($payout)) {
            return null;
        }

        // Chargebacks keep a new `txn_id` and put the credit we already stored
        // in `rewarded_txn_id`. Reverse looks up by `network_txid`, so that
        // original id is the one that has to travel. A missing original with a
        // negative payout still reverses — their docs say the sign is the
        // signal — but then `reverse()` will not find a row until an admin
        // attaches it.
        $originalTxId = $this->nullableString($request->input('rewarded_txn_id'));
        $amount = (float) $payout;
        $reversed = $originalTxId !== null || $amount < 0;

        return new PostbackDTO(
            networkTxId: $originalTxId ?? $transId,
            clickId: $this->nullableString($request->input(self::SUBID_PARAM)),
            payout: $reversed ? -abs($amount) : abs($amount),
            status: $reversed ? 'reversed' : 'credited',
            offerExternalId: $this->nullableString($request->input('offer_id')),
            offerName: $this->nullableString($request->input('offer_name')),
            // No `endUserId`: `user_id` is the value we put on their link, which
            // on the catalogue is often still their `{user_id}` macro and on
            // the embed is the publisher userid. The processor already has the
            // publisher's identity on the click.
            ip: $this->nullableString($request->input('conversion_ip')),
            raw: $request->all(),
        );
    }

    public function verifyPostback(Request $request): bool
    {
        $secret = $this->credential('postback_secret');
        $hash = $this->nullableString($request->input('hash'));

        // Fails closed. The secret is a required credential, so a blank one is
        // a misconfiguration, not permission to skip the check.
        if (blank($secret) || $hash === null) {
            return false;
        }

        $url = $this->callbackUrlWithoutHash($request, $hash);

        if ($url === null) {
            return false;
        }

        // Documented: HMAC-SHA1 of the full callback URL with `hash` removed,
        // keyed by the app secret. Empty query values must stay empty — they
        // warn that treating a missing value as NULL changes the hash.
        $expected = hash_hmac('sha1', $url, $secret);

        return hash_equals($expected, strtolower($hash));
    }

    public function buildTrackingUrl(Offer $offer, string $clickId): string
    {
        // Through the same helper the catalogue went through, not a bare
        // str_replace. A stored link with no macro in it — hand-edited, or
        // synced before this driver normalized links — would otherwise be
        // handed to the user untouched, and every conversion off it comes back
        // with a blank subid that matches no click.
        return str_replace('{click_id}', $clickId, $this->withSubIdMacro($offer->tracking_url));
    }

    /**
     * @return array<string, string>
     */
    private function authParams(): array
    {
        return [
            'api_key' => (string) $this->credential('api_key'),
            'pub_id' => (string) $this->credential('pub_id'),
            'app_id' => (string) $this->credential('app_id'),
        ];
    }

    /** The same URL with our credentials in its query string. */
    private function authorized(string $url): string
    {
        return (string) Uri::of($url)->withQuery($this->authParams());
    }

    /**
     * The catalogue nests its rows under `offers.data` when paginated and
     * `data` when it is not.
     *
     * @return list<array<string, mixed>>
     */
    private function rowsFrom(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $rows = data_get($payload, 'offers.data') ?? data_get($payload, 'data') ?? [];

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function toOffer(array $row): ?OfferDTO
    {
        if (! isset($row['offer_id'])) {
            return null;
        }

        // A points-denominated payout is not USD, and nothing here knows the
        // rate. Storing it as dollars would multiply what we owe a publisher by
        // whatever the placement's exchange rate happens to be, so the row is
        // skipped and the sync job's collapse guard shouts if they all are.
        if (($row['reward_name'] ?? null) === 'Points') {
            return null;
        }

        $events = array_values(array_filter($row['events'] ?? [], 'is_array'));

        $instructions = array_values(array_filter([
            $this->nullableString($row['description1'] ?? null),
            $this->nullableString($row['description2'] ?? null),
            $this->nullableString($row['description3'] ?? null),
        ]));

        return new OfferDTO(
            externalId: (string) $row['offer_id'],
            name: (string) ($row['name'] ?? 'Untitled offer'),
            trackingUrl: $this->withSubIdMacro((string) ($row['click_url'] ?? '')),
            payout: (float) ($row['payout'] ?? 0),
            description: $this->nullableString($row['description1'] ?? null),
            instructions: $instructions === [] ? null : implode("\n", array_map(htmlspecialchars_decode(...), $instructions)),
            iconUrl: $this->nullableString($row['image_url'] ?? null),
            countries: $this->parseCountries($row['country_code'] ?? []),
            devices: $this->parseDevices($row['os'] ?? []),
            categories: $this->categoryNames($row['categories'] ?? null),
            isMultiReward: count($events) > 1,
            goals: $this->goalList($events, 'name', 'payout'),
        );
    }

    private function withSubIdMacro(string $link): string
    {
        $link = str_replace(
            array_keys(self::USER_PLACEHOLDERS),
            array_values(self::USER_PLACEHOLDERS),
            $link,
        );

        if (str_contains($link, self::SUBID_PARAM.'={click_id}')) {
            return $link;
        }

        return $link.(str_contains($link, '?') ? '&' : '?').self::SUBID_PARAM.'={click_id}';
    }

    /**
     * The URL they HMAC: scheme + host + request URI, with `hash` removed and
     * every other query value left byte-for-byte. Rebuilding the query from
     * parsed params would reorder them and turn empty into missing — both of
     * which they say fail the hash.
     */
    private function callbackUrlWithoutHash(Request $request, string $hash): ?string
    {
        $url = $request->getSchemeAndHttpHost().$request->getRequestUri();
        $suffix = '&hash='.$hash;

        if (str_ends_with($url, $suffix)) {
            return substr($url, 0, -strlen($suffix));
        }

        $only = '?hash='.$hash;

        if (str_ends_with($url, $only)) {
            return substr($url, 0, -strlen($only));
        }

        $stripped = preg_replace('/([?&])hash='.preg_quote($hash, '/').'&/', '$1', $url, 1, $count);

        return $count === 1 && is_string($stripped) ? $stripped : null;
    }
}
