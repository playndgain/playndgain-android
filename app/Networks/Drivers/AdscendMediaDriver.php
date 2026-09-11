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
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;

/**
 * HTTP basic auth — publisher id as the user, API key as the password.
 *
 * The catalogue call and its field mapping are ported from a working Adscend
 * integration. The postback half follows Adscend's published macro list and
 * still needs verifying against a live fire.
 */
class AdscendMediaDriver extends AbstractDriver
{
    use NormalizesFields;

    /** Adscend numbers its target systems; these are the ones a wall sees. */
    private const TARGET_SYSTEMS = [
        31 => ['desktop'],
        30 => ['android', 'ios'],
        40 => ['android'],
        56 => ['android'],
        50 => ['ios'],
        51 => ['ios'],
        52 => ['ios'],
    ];

    private const SUBID_PARAM = 'sub1';

    public static function key(): string
    {
        return 'adscendmedia';
    }

    public static function connector(): Connector
    {
        return new Connector('AdscendMedia', ConnectorCategory::Direct, website: 'https://adscendmedia.com');
    }

    /**
     * @return array<string, array{label: string, required?: bool, secret?: bool}>
     */
    public static function credentialSchema(): array
    {
        return [
            'pub_id' => ['label' => 'Publisher ID', 'required' => true],
            'api_key' => ['label' => 'API Key', 'required' => true, 'secret' => true],
            'postback_key' => ['label' => 'Postback Secret', 'required' => false, 'secret' => true],
        ];
    }

    public function testConnection(): ConnectionResult
    {
        $response = $this->authorized()->get($this->endpoint());

        if ($response->failed()) {
            return ConnectionResult::fail("Adscend Media returned HTTP {$response->status()}.");
        }

        $offers = $response->json('offers');

        return ConnectionResult::ok('Connected.', is_array($offers) ? count($offers) : 0);
    }

    /**
     * ponytail: read into memory rather than streamed off disk. A publisher's
     * catalogue fits; if one stops fitting, stream the `offers` array.
     *
     * @return iterable<OfferDTO>
     */
    public function fetchOffers(): iterable
    {
        $rows = $this->authorized()
            ->timeout(180)
            ->get($this->endpoint())
            ->throw()
            ->json('offers');

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row) || ! isset($row['offer_id'])) {
                continue;
            }

            $events = array_values(array_filter($row['events'] ?? [], 'is_array'));

            yield new OfferDTO(
                externalId: (string) $row['offer_id'],
                name: (string) ($row['name'] ?? 'Untitled offer'),
                trackingUrl: $this->withSubIdMacro((string) ($row['click_url'] ?? '')),
                // Adscend prices the offer as a whole and itemizes the steps
                // underneath, so the offer's own payout is the authority.
                payout: (float) ($row['payout'] ?? 0),
                description: $this->nullableString($row['description'] ?? null),
                iconUrl: $this->creativeUrl($row['creatives'] ?? null),
                countries: $this->parseCountries($row['countries'] ?? []),
                devices: $this->devicesFor($row['target_system'] ?? null),
                categories: $this->categoryNames($row['category_id'] ?? null),
                isMultiReward: count($events) > 1,
                goals: $this->goalList($events, 'event_name', 'event_revenue'),
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

        // ASSUMPTION: Adscend reverses by re-firing with a negative payout.
        $amount = (float) $payout;
        $reversed = $amount < 0;

        return new PostbackDTO(
            networkTxId: $this->nullableString($request->input('transaction_id')) ?? $clickId.':'.$offerId,
            clickId: $clickId,
            payout: $reversed ? -abs($amount) : abs($amount),
            status: $reversed ? 'reversed' : 'credited',
            offerExternalId: $offerId,
            offerName: $this->nullableString($request->input('offer_name')),
            endUserId: $this->nullableString($request->input('user_id') ?? $request->input('member_id')),
            ip: $this->nullableString($request->input('ip')),
            raw: $request->all(),
        );
    }

    public function verifyPostback(Request $request): bool
    {
        $expected = $this->credential('postback_key');

        // Adscend signs nothing. A shared token in the postback macro is checked
        // when configured; otherwise the source-IP allowlist is the only guard.
        if (blank($expected)) {
            return true;
        }

        return hash_equals($expected, (string) $request->input('key', ''));
    }

    public function buildTrackingUrl(Offer $offer, string $clickId): string
    {
        return str_replace('{click_id}', $clickId, $offer->tracking_url);
    }

    private function endpoint(): string
    {
        return sprintf(
            'https://api.adscendmedia.com/v1/publisher/%s/offers.json',
            (string) $this->credential('pub_id'),
        );
    }

    private function authorized(): PendingRequest
    {
        return $this->http()->withBasicAuth(
            (string) $this->credential('pub_id'),
            (string) $this->credential('api_key'),
        );
    }

    private function withSubIdMacro(string $link): string
    {
        return $link.(str_contains($link, '?') ? '&' : '?').self::SUBID_PARAM.'={click_id}';
    }

    /**
     * An unrecognised target system means "we do not know what this runs on",
     * and an empty device list is our vocabulary for "all of them".
     *
     * @return list<string>
     */
    private function devicesFor(mixed $targetSystem): array
    {
        return self::TARGET_SYSTEMS[(int) (is_numeric($targetSystem) ? $targetSystem : 0)] ?? [];
    }

    /** Creatives arrive keyed by size; any one of them is the icon we show. */
    private function creativeUrl(mixed $creatives): ?string
    {
        if (! is_array($creatives) || $creatives === []) {
            return null;
        }

        $first = $creatives[array_key_first($creatives)] ?? null;

        return is_array($first) ? $this->nullableString($first['url'] ?? null) : $this->nullableString($first);
    }
}
