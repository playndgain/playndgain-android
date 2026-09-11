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

/**
 * Query-string key auth against the v3 offers endpoint.
 *
 * The catalogue call and its field mapping are ported from a working AdGate
 * integration, so endpoint, auth and offer shape are known-good. The postback
 * half is not — AdGate signs nothing, and the parameter names below follow its
 * published macro list. Verify before the network takes live traffic.
 */
class AdGateMediaDriver extends AbstractDriver
{
    use NormalizesFields;

    private const ENDPOINT = 'https://api.adgatemedia.com/v3/offers';

    /** AdGate exposes five sub-id slots; the first carries our click id. */
    private const SUBID_PARAM = 's1';

    public static function key(): string
    {
        return 'adgatemedia';
    }

    public static function connector(): Connector
    {
        return new Connector('AdGateMedia', ConnectorCategory::Direct, website: 'https://adgatemedia.com');
    }

    /**
     * @return array<string, array{label: string, required?: bool, secret?: bool}>
     */
    public static function credentialSchema(): array
    {
        return [
            'affiliate_id' => ['label' => 'Affiliate ID', 'required' => true],
            'api_key' => ['label' => 'API Key', 'required' => true, 'secret' => true],
            'wall_code' => ['label' => 'Wall Code', 'required' => true],
            'postback_key' => ['label' => 'Postback Secret', 'required' => false, 'secret' => true],
        ];
    }

    public function testConnection(): ConnectionResult
    {
        $response = $this->http()->get(self::ENDPOINT, $this->authParams());

        if ($response->failed()) {
            return ConnectionResult::fail("AdGate Media returned HTTP {$response->status()}.");
        }

        $offers = $response->json('data');

        return ConnectionResult::ok('Connected.', is_array($offers) ? count($offers) : 0);
    }

    /**
     * The v3 catalogue arrives in a single response — AdGate does not paginate
     * this endpoint. A large wall is a multi-megabyte body, which is why the
     * timeout is raised well above the 30s default.
     *
     * ponytail: read into memory rather than streamed off disk. One wall's
     * catalogue fits; if a future wall does not, stream the `data` array.
     *
     * @return iterable<OfferDTO>
     */
    public function fetchOffers(): iterable
    {
        $rows = $this->http()
            ->timeout(180)
            ->get(self::ENDPOINT, $this->authParams())
            ->throw()
            ->json('data');

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row) || ! isset($row['id'])) {
                continue;
            }

            $events = array_values(array_filter($row['events'] ?? [], 'is_array'));
            $thingsToKnow = is_array($row['things_to_know'] ?? null) ? $row['things_to_know'] : [];
            $creatives = is_array($row['creatives'] ?? null) ? $row['creatives'] : [];

            yield new OfferDTO(
                externalId: (string) $row['id'],
                name: (string) ($row['name'] ?? 'Untitled offer'),
                trackingUrl: $this->withSubIdMacro((string) ($row['click_url'] ?? '')),
                // AdGate prices per event, not per offer. Completing every event
                // is what the offer is worth to us.
                payout: $this->sumPayouts($events),
                description: $this->nullableString($row['description'] ?? null),
                instructions: $thingsToKnow === [] ? null : implode("\n", array_map('strval', $thingsToKnow)),
                iconUrl: $this->nullableString($creatives['icon'] ?? null),
                countries: $this->parseCountries($this->countryCodes($row)),
                devices: $this->parseDevices($this->deviceNames($row['device_targeting'] ?? null)),
                categories: $this->categoryNames($row['categories'] ?? null),
                isMultiReward: count($events) > 1,
                goals: $this->goalList($events, 'name', 'payout'),
            );
        }
    }

    public function parsePostback(Request $request): ?PostbackDTO
    {
        $clickId = $this->nullableString($request->input(self::SUBID_PARAM));
        $payout = $request->input('payout');

        if ($clickId === null || ! is_numeric($payout)) {
            return null;
        }

        $offerId = $this->nullableString($request->input('offer_id'));

        // ASSUMPTION: AdGate signals a reversal with a negative payout rather
        // than a status flag. Reading it backwards would credit a chargeback.
        $amount = (float) $payout;
        $reversed = $amount < 0;

        return new PostbackDTO(
            // AdGate does not always send a transaction id. Click + offer is the
            // next-best idempotency key: a second fire for the same pair is the
            // duplicate we need to reject.
            networkTxId: $this->nullableString($request->input('transaction_id')) ?? $clickId.':'.$offerId,
            clickId: $clickId,
            payout: $reversed ? -abs($amount) : abs($amount),
            status: $reversed ? 'reversed' : 'credited',
            offerExternalId: $offerId,
            offerName: $this->nullableString($request->input('offer_name')),
            endUserId: $this->nullableString($request->input('user_id')),
            ip: $this->nullableString($request->input('ip')),
            raw: $request->all(),
        );
    }

    public function verifyPostback(Request $request): bool
    {
        $expected = $this->credential('postback_key');

        // AdGate signs nothing. When a shared token is configured into the
        // postback macro we check it; when it is not, the source-IP allowlist
        // the ingress controller enforces first is the only guarantee there is.
        if (blank($expected)) {
            return true;
        }

        return hash_equals($expected, (string) $request->input('key', ''));
    }

    public function buildTrackingUrl(Offer $offer, string $clickId): string
    {
        return str_replace('{click_id}', $clickId, $offer->tracking_url);
    }

    /**
     * @return array<string, string>
     */
    private function authParams(): array
    {
        return [
            'aff' => (string) $this->credential('affiliate_id'),
            'api_key' => (string) $this->credential('api_key'),
            'wall_code' => (string) $this->credential('wall_code'),
        ];
    }

    /**
     * AdGate hands back a click_url whose sub-id slot is usually already there
     * and empty (`…&s1=`), so the macro fills that slot rather than being added
     * as a second, ignored copy of the parameter.
     *
     * Which slot it fills has to be decided by name. Deciding it by shape — "the
     * URL ends in `=`, so the macro goes on the end" — lands on whatever empty
     * parameter happens to be last, and AdGate hands back `…&s1=&s5=` and plain
     * trailing `&foo=` too. The macro then goes to the wrong slot, `s1` stays
     * empty, and every click reaches AdGate with no sub-id: the postback comes
     * back with a blank `s1`, `parsePostback()` returns null, and the conversion
     * is silently unattributed. Nobody gets paid for it and nothing logs an
     * error.
     *
     * `s1` is ours, so an existing value is overwritten rather than kept — a
     * catalogue link carrying AdGate's own placeholder there is not a sub-id we
     * can attribute.
     *
     * ponytail: pairs are split and rejoined byte-for-byte rather than run
     * through parse_str/http_build_query, because that round trip rewrites
     * parameters we do not own — it mangles a dotted key into an underscored one
     * and re-encodes every value. Silently munging someone else's tracking
     * parameter is the same class of bug as the one above. Two ceilings, neither
     * of which AdGate reaches: a repeated key collapses to its last value, and a
     * URL fragment would ride along inside the last value. If either starts
     * arriving, keep the pairs as a list and split the fragment off first.
     */
    private function withSubIdMacro(string $link): string
    {
        [$base, $query] = array_pad(explode('?', $link, 2), 2, '');

        /** @var array<string, string> $params */
        $params = [];

        foreach (array_filter(explode('&', $query), fn (string $pair): bool => $pair !== '') as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $params[$key] = $value;
        }

        $params[self::SUBID_PARAM] = '{click_id}';

        return $base.'?'.collect($params)
            ->map(fn (string $value, string $key): string => $key.'='.$value)
            ->implode('&');
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function countryCodes(array $row): array
    {
        $countries = data_get($row, 'geo_targeting.countries');

        if (! is_array($countries)) {
            return [];
        }

        return collect($countries)
            ->map(fn (mixed $country): ?string => is_array($country)
                ? $this->nullableString($country['country_code'] ?? null)
                : $this->nullableString($country))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * `device_targeting` splits into platforms (smartphone, tablet, desktop)
     * and operating systems (Android, iOS). Both feed the same vocabulary;
     * `parseDevices` throws away whatever does not map.
     *
     * @return list<string>
     */
    private function deviceNames(mixed $targeting): array
    {
        if (! is_array($targeting)) {
            return [];
        }

        $names = collect(['platforms', 'operating_systems'])
            ->flatMap(fn (string $bucket): array => is_array($targeting[$bucket] ?? null) ? $targeting[$bucket] : [])
            ->map(fn (mixed $entry): ?string => is_array($entry)
                ? $this->nullableString($entry['name'] ?? null)
                : $this->nullableString($entry))
            ->filter()
            ->map(fn (string $name): string => strtolower($name))
            ->all();

        // Phone- and tablet-targeted offers name no OS. They run on both.
        if (array_intersect($names, ['smartphone', 'tablet']) !== []) {
            $names[] = 'android';
            $names[] = 'ios';
        }

        return collect($names)->unique()->values()->all();
    }
}
