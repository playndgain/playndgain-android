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
 * Paginated Offer API, a click URL we have to build (the catalogue has none),
 * and a postback they fire as a fixed JSON/GET payload with no signature.
 *
 * Four things about this network are unlike the others here:
 *
 *   - **Auth is one Bearer token that is two credentials jammed together:**
 *     `Authorization: Bearer {pub_id}:{api_key}`. The docs write it that way
 *     (`<pub-id:api-key>`). Splitting them across two headers, or sending
 *     only the key, is a 401 that looks like a typo.
 *   - **The catalogue has no tracking URL.** Clicks go through their redirect
 *     (`/redirect?pubId=&offerId=&userId=`). `userId` is documented as "your
 *     internal user ID or click ID", so it is our `{click_id}`. They also
 *     publish a JWT redirect they call more secure; we do not sign at click
 *     time, and the query form is the documented fallback.
 *   - **GET is a macro template, POST is a fixed JSON body.** Their
 *     dashboard replaces `{{payout}}` (and the rest) in the URL. A bare
 *     `/postback/klink` on GET arrives empty and 422s. Paste
 *     `KlinkDriver::postbackUrl()`. POST sends the same keys as JSON with
 *     no macros. There is no hash. The six published firing addresses
 *     (seeded on the network row) are the whole of the authentication.
 *     `{{payoutToUser}}` is their coin figure after a multiplier — ignore
 *     it; `{{payout}}` is the USD we ledger.
 *   - **A chargeback mints a new `conversionId`.** The original credit's id
 *     arrives as `reversedConversionId`. `reverse()` looks up `network_txid`,
 *     so the reversal DTO has to carry the original id or the clawback
 *     misses and we keep the money.
 *
 * They also host an iframe wall (`offerwall.klinkfinance.com/wall`). That is
 * the embed path, not this driver's: `user_id` on that URL is the embed
 * click, same slot as `userId` on the postback.
 *
 * Docs: https://publisher.klinklabs.com/docs/guides/publisher/offer-api
 */
class KlinkDriver extends AbstractDriver
{
    use NormalizesFields;

    private const ENDPOINT = 'https://klink-quest.klink.finance/api/v1/publisher/offers';

    private const REDIRECT = 'https://klink-quest.klink.finance/redirect';

    private const SUBID_PARAM = 'userId';

    /** Their published page size ceiling. */
    private const PAGE_SIZE = 500;

    public static function key(): string
    {
        return 'klink';
    }

    public static function connector(): Connector
    {
        return new Connector('Klink', ConnectorCategory::Direct, website: 'https://publisher.klinklabs.com');
    }

    /**
     * @return array<string, string>
     */
    public static function postbackQuery(): array
    {
        return [
            'payout' => '{{payout}}',
            'status' => '{{status}}',
            'userId' => '{{userId}}',
            'offerId' => '{{offerId}}',
            'offerName' => '{{offerName}}',
            'eventId' => '{{eventId}}',
            'eventName' => '{{eventName}}',
            'taskId' => '{{taskId}}',
            'eventType' => '{{eventType}}',
            'conversionId' => '{{conversionId}}',
            'reversedConversionId' => '{{reversedConversionId}}',
            'k1' => '{{k1}}',
            'k2' => '{{k2}}',
            'k3' => '{{k3}}',
            'k4' => '{{k4}}',
            'k5' => '{{k5}}',
            'ip' => '{{ip}}',
        ];
    }

    /**
     * @return array<string, array{label: string, required?: bool, secret?: bool}>
     */
    public static function credentialSchema(): array
    {
        return [
            'pub_id' => ['label' => 'Publisher ID', 'required' => true],
            'api_key' => ['label' => 'API key', 'required' => true, 'secret' => true],
        ];
    }

    public function testConnection(): ConnectionResult
    {
        $response = $this->call(1, 1);

        if ($response->failed()) {
            return ConnectionResult::fail("Klink returned HTTP {$response->status()}: ".$this->rejection($response));
        }

        if (! $this->succeeded($response)) {
            return ConnectionResult::fail('Klink rejected the request: '.$this->rejection($response));
        }

        $total = $response->json('total');

        return ConnectionResult::ok('Connected.', is_numeric($total) ? (int) $total : 0);
    }

    /**
     * @return iterable<OfferDTO>
     */
    public function fetchOffers(): iterable
    {
        $page = 1;
        $totalPages = 1;

        do {
            [$rows, $totalPages] = $this->page($page);

            foreach ($rows as $row) {
                $offer = $this->offer($row);

                if ($offer !== null) {
                    yield $offer;
                }
            }

            $page++;
        } while ($rows !== [] && $page <= $totalPages);
    }

    public function parsePostback(Request $request): ?PostbackDTO
    {
        $clickId = $this->nullableString($request->input('userId'))
            ?? $this->nullableString($request->input('k1'));
        $txId = $this->nullableString($request->input('conversionId'));
        $payout = $request->input('payout');

        if ($clickId === null || $txId === null || ! is_numeric($payout)) {
            return null;
        }

        $status = strtolower((string) $request->input('status'));
        $reversed = strtolower((string) $request->input('eventType')) === 'chargeback'
            || $status === 'cancelled'
            || (float) $payout < 0;

        $amount = abs((float) $payout);

        // A chargeback's own `conversionId` is a new id. The credit we have
        // to claw back is `reversedConversionId`. Falling back to the new id
        // is how a fire with neither field still produces a DTO — `reverse()`
        // then misses, which is the support case, not a silent credit.
        if ($reversed) {
            $txId = $this->nullableString($request->input('reversedConversionId')) ?? $txId;
        }

        return new PostbackDTO(
            networkTxId: $txId,
            clickId: $clickId,
            payout: $reversed ? -$amount : $amount,
            // `pending` is theirs: awaiting confirmation. It must not credit.
            // `payoutToUser` is coins after their multiplier — not read.
            status: $reversed ? 'reversed' : ($status === 'pending' ? 'pending' : 'credited'),
            offerExternalId: $this->nullableString($request->input('offerId')),
            offerName: $this->nullableString($request->input('eventName'))
                ?? $this->nullableString($request->input('offerName')),
            ip: $this->nullableString($request->input('ip')),
            raw: $request->all(),
        );
    }

    public function verifyPostback(Request $request): bool
    {
        // Klink documents no signature and no shared postback token. The
        // source-IP allowlist the controller enforces first is the whole of
        // this network's authentication. Their six firing addresses are
        // seeded; an empty list still rejects when any-source is off.
        return true;
    }

    public function buildTrackingUrl(Offer $offer, string $clickId): string
    {
        return str_replace('{click_id}', $clickId, $this->withSubIdMacro($offer->tracking_url));
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function page(int $page): array
    {
        $response = $this->call($page, self::PAGE_SIZE)->throw();

        if (! $this->succeeded($response)) {
            throw new RuntimeException('Klink rejected the offers request: '.$this->rejection($response));
        }

        $rows = $response->json('data');
        $totalPages = $response->json('totalPages');

        return [
            is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [],
            is_numeric($totalPages) ? max(1, (int) $totalPages) : 1,
        ];
    }

    private function call(int $page, int $limit): Response
    {
        return $this->http()
            ->timeout(180)
            ->withToken($this->bearer())
            ->get(self::ENDPOINT, [
                'page' => $page,
                'limit' => $limit,
            ]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function offer(array $row): ?OfferDTO
    {
        $offerId = $this->nullableString($row['offerId'] ?? null);

        if ($offerId === null || ($row['isActive'] ?? true) === false) {
            return null;
        }

        $activities = collect($row['activities'] ?? [])
            ->filter(fn (mixed $activity): bool => is_array($activity))
            ->sortBy(fn (array $activity): int => (int) ($activity['order'] ?? 0))
            ->values()
            ->all();

        $goals = $this->goalList($activities, 'name', 'payout');

        return new OfferDTO(
            externalId: $offerId,
            name: $this->localized($row['name'] ?? null) ?? 'Untitled offer',
            trackingUrl: $this->trackingUrl($offerId),
            // `totalPayout` is the offer's own total and the activities
            // itemize it, so it stays the authority rather than being
            // recomputed from them.
            payout: (float) ($row['totalPayout'] ?? 0),
            description: $this->localized($row['description'] ?? null),
            iconUrl: $this->nullableString(data_get($row, 'images.logo')),
            countries: $this->parseCountries($row['countries'] ?? []),
            devices: $this->devices($row['platform'] ?? null, $row['deviceName'] ?? null),
            categories: $this->categoryNames($row['categories'] ?? []),
            isMultiReward: count($goals) > 1,
            goals: $goals,
        );
    }

    private function trackingUrl(string $offerId): string
    {
        // Braces stay unencoded — `http_build_query()` would turn
        // `{click_id}` into `%7Bclick_id%7D`, and their redirect would
        // echo the encoded form back as a click id that matches nothing.
        return self::REDIRECT
            .'?pubId='.rawurlencode((string) $this->credential('pub_id'))
            .'&offerId='.rawurlencode($offerId)
            .'&userId={click_id}';
    }

    /**
     * `deviceName` is `desktop` or `mobile`. `platform` is `web`, `android`,
     * or `ios`. `parseDevices()` does not know `mobile`, and `web` maps to
     * desktop — so a mobile-web offer passed through raw would store desktop
     * and hide the phones it was bought for.
     *
     * @return list<string>
     */
    private function devices(mixed $platform, mixed $deviceName): array
    {
        $platform = strtolower(trim((string) ($this->nullableString($platform) ?? '')));
        $device = strtolower(trim((string) ($this->nullableString($deviceName) ?? '')));

        if (in_array($platform, ['android', 'ios'], true)) {
            return $this->parseDevices($platform);
        }

        if ($device === 'mobile') {
            return ['android', 'ios'];
        }

        return $this->parseDevices(array_values(array_filter([$platform, $device])));
    }

    private function localized(mixed $value): ?string
    {
        if (is_array($value)) {
            return $this->nullableString($value['en'] ?? reset($value) ?: null);
        }

        return $this->nullableString($value);
    }

    private function bearer(): string
    {
        return (string) $this->credential('pub_id').':'.(string) $this->credential('api_key');
    }

    private function succeeded(Response $response): bool
    {
        return $response->json('success') === true;
    }

    private function rejection(Response $response): string
    {
        return $this->nullableString($response->json('message'))
            ?? $this->nullableString($response->json('error'))
            ?? 'no error message given.';
    }

    private function withSubIdMacro(string $link): string
    {
        if (str_contains($link, '{click_id}')) {
            return $link;
        }

        return $link.(str_contains($link, '?') ? '&' : '?').self::SUBID_PARAM.'={click_id}';
    }
}
