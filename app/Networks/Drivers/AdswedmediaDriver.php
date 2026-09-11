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
 * Site key + site secret in the query string.
 *
 * The catalogue call and its field mapping are ported from a working
 * integration: offer links arrive with `USER_ID_HERE` and `PUBLIC-KEY`
 * placeholders that the driver fills in. The postback half needs verifying
 * against a live fire.
 */
class AdswedmediaDriver extends AbstractDriver
{
    use NormalizesFields;

    private const ENDPOINT = 'https://adswedmedia.com/api/v1/offers';

    private const ICON_BASE = 'https://adswedmedia.com/asset/images/offers/';

    public static function key(): string
    {
        return 'adswedmedia';
    }

    public static function connector(): Connector
    {
        return new Connector('Adswedmedia', ConnectorCategory::Direct, website: 'https://adswedmedia.com');
    }

    /**
     * @return array<string, array{label: string, required?: bool, secret?: bool}>
     */
    public static function credentialSchema(): array
    {
        return [
            'site_key' => ['label' => 'Site Key', 'required' => true],
            'site_secret' => ['label' => 'Site Secret', 'required' => true, 'secret' => true],
        ];
    }

    public function testConnection(): ConnectionResult
    {
        $response = $this->http()->get(self::ENDPOINT, $this->authParams());

        if ($response->failed()) {
            return ConnectionResult::fail("Adswedmedia returned HTTP {$response->status()}.");
        }

        $offers = $response->json('offers');

        return ConnectionResult::ok('Connected.', is_array($offers) ? count($offers) : 0);
    }

    /**
     * @return iterable<OfferDTO>
     */
    public function fetchOffers(): iterable
    {
        $rows = $this->http()
            ->timeout(180)
            ->get(self::ENDPOINT, $this->authParams())
            ->throw()
            ->json('offers');

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row) || ! isset($row['id'])) {
                continue;
            }

            $events = array_values(array_filter($row['events'] ?? [], 'is_array'));
            $image = $this->nullableString($row['image'] ?? null);

            yield new OfferDTO(
                externalId: (string) $row['id'],
                name: (string) ($row['title'] ?? 'Untitled offer'),
                trackingUrl: $this->withSubIdMacro((string) ($row['url'] ?? '')),
                payout: (float) ($row['payout'] ?? 0),
                description: $this->nullableString($row['description'] ?? null),
                // The catalogue carries a bare filename, not a URL.
                iconUrl: $image === null ? null : self::ICON_BASE.$image,
                countries: $this->parseCountries($row['countries'] ?? []),
                devices: $this->parseDevices($row['devices'] ?? []),
                categories: $this->categoryNames($row['categories'] ?? null),
                isMultiReward: count($events) > 1,
                goals: $this->goalList($events, 'description', 'payout'),
            );
        }
    }

    public function parsePostback(Request $request): ?PostbackDTO
    {
        $transId = $this->nullableString($request->input('transaction_id'));
        $payout = $request->input('payout');

        if ($transId === null || ! is_numeric($payout)) {
            return null;
        }

        // ASSUMPTION: status 2 is the reversal.
        $reversed = (string) $request->input('status', '1') === '2';
        $amount = abs((float) $payout);

        return new PostbackDTO(
            networkTxId: $transId,
            clickId: $this->nullableString($request->input('user_id')),
            payout: $reversed ? -$amount : $amount,
            status: $reversed ? 'reversed' : 'credited',
            offerExternalId: $this->nullableString($request->input('offer_id')),
            offerName: $this->nullableString($request->input('offer_name')),
            ip: $this->nullableString($request->input('ip')),
            raw: $request->all(),
        );
    }

    public function verifyPostback(Request $request): bool
    {
        $secret = $this->credential('site_secret');

        // Fails closed — the secret is a required credential, so a blank one is
        // a misconfiguration, not permission to skip the check.
        if (blank($secret)) {
            return false;
        }

        return hash_equals($secret, (string) $request->input('secret', ''));
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
            'site_key' => (string) $this->credential('site_key'),
            'site_secret' => (string) $this->credential('site_secret'),
        ];
    }

    /**
     * The catalogue ships template links: `PUBLIC-KEY` identifies the site and
     * `USER_ID_HERE` is the slot our click id belongs in.
     */
    private function withSubIdMacro(string $link): string
    {
        $link = str_replace(
            ['USER_ID_HERE', 'PUBLIC-KEY'],
            ['{click_id}', (string) $this->credential('site_key')],
            $link,
        );

        if (str_contains($link, '{click_id}')) {
            return $link;
        }

        return $link.(str_contains($link, '?') ? '&' : '?').'user_id={click_id}';
    }
}
