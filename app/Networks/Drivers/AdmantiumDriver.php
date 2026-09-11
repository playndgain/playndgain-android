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
 * App-id auth, everything prefixed `offer_`.
 *
 * The network trades as Admantum; the driver key stays `admantium` because
 * seeded rows and the postback route segment already use it.
 *
 * The catalogue call and its field mapping are ported from a working
 * integration. The postback half follows the shape this class of network
 * commonly ships and needs verifying against a live fire.
 */
class AdmantiumDriver extends AbstractDriver
{
    use NormalizesFields;

    private const ENDPOINT = 'https://admantum.com/api/v3/offers';

    /**
     * The catalogue is fetched for a placeholder user, and every returned link
     * carries that id. Swapping it for our macro is what makes a link ours.
     */
    private const CATALOGUE_UID = '1';

    public static function key(): string
    {
        return 'admantium';
    }

    public static function connector(): Connector
    {
        return new Connector('Admantium', ConnectorCategory::Direct, website: 'https://admantum.com');
    }

    /**
     * @return array<string, array{label: string, required?: bool, secret?: bool}>
     */
    public static function credentialSchema(): array
    {
        return [
            'appid' => ['label' => 'App ID', 'required' => true],
            'postback_secret' => ['label' => 'Postback Secret', 'required' => false, 'secret' => true],
        ];
    }

    public function testConnection(): ConnectionResult
    {
        $response = $this->http()->get(self::ENDPOINT, $this->authParams());

        if ($response->failed()) {
            return ConnectionResult::fail("Admantum returned HTTP {$response->status()}.");
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
            if (! is_array($row) || ! isset($row['offer_id'])) {
                continue;
            }

            $events = array_values(array_filter($row['offer_events'] ?? [], 'is_array'));
            $instructions = is_array($row['offer_instructions'] ?? null) ? $row['offer_instructions'] : [];

            yield new OfferDTO(
                externalId: (string) $row['offer_id'],
                name: (string) ($row['offer_title'] ?? 'Untitled offer'),
                trackingUrl: $this->withSubIdMacro((string) ($row['offer_link'] ?? '')),
                payout: (float) ($row['offer_payout'] ?? 0),
                description: $this->nullableString($row['offer_description'] ?? null),
                instructions: $instructions === [] ? null : implode("\n", array_map('strval', $instructions)),
                iconUrl: $this->nullableString($row['offer_image'] ?? null),
                countries: $this->parseCountries($row['offer_countries'] ?? []),
                devices: $this->parseDevices($row['offer_devices'] ?? []),
                categories: $this->categoryNames($row['offer_categories'] ?? null),
                isMultiReward: count($events) > 1,
                goals: $this->goalList($events, 'event_name', 'event_payout'),
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

        // ASSUMPTION: status 2 is the reversal. Backwards here credits a
        // chargeback.
        $reversed = (string) $request->input('status', '1') === '2';
        $amount = abs((float) $payout);

        return new PostbackDTO(
            networkTxId: $transId,
            clickId: $this->nullableString($request->input('uid')),
            payout: $reversed ? -$amount : $amount,
            status: $reversed ? 'reversed' : 'credited',
            offerExternalId: $this->nullableString($request->input('offer_id')),
            offerName: $this->nullableString($request->input('offer_name')),
            endUserId: $this->nullableString($request->input('user_id')),
            ip: $this->nullableString($request->input('ip')),
            raw: $request->all(),
        );
    }

    public function verifyPostback(Request $request): bool
    {
        $secret = $this->credential('postback_secret');

        // Optional credential: unset means the source-IP allowlist is the guard.
        // Set means it must match — a configured secret is never skipped.
        if (blank($secret)) {
            return true;
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
            'appid' => (string) $this->credential('appid'),
            'uid' => self::CATALOGUE_UID,
        ];
    }

    /**
     * ASSUMPTION: the link comes back carrying the uid we asked for, as either
     * `uid=1` or `user=1`. If a catalogue ever arrives with neither, the macro
     * is appended so the click still carries an id rather than silently
     * dropping it — an untracked click is an unpaid conversion.
     */
    private function withSubIdMacro(string $link): string
    {
        $replaced = str_replace(
            ['uid='.self::CATALOGUE_UID, 'user='.self::CATALOGUE_UID],
            ['uid={click_id}', 'user={click_id}'],
            $link,
        );

        if ($replaced !== $link) {
            return $replaced;
        }

        return $link.(str_contains($link, '?') ? '&' : '?').'uid={click_id}';
    }
}
