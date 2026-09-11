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
 * POSTed credentials, single-step and multi-step offers in one catalogue.
 *
 * The catalogue call and its field mapping are ported from a working
 * integration. The postback half follows Lootably's published macro list and
 * needs verifying against a live fire.
 */
class LootablyDriver extends AbstractDriver
{
    private const ENDPOINT = 'https://api.lootably.com/api/v2/offers/get';

    use NormalizesFields;

    public static function key(): string
    {
        return 'lootably';
    }

    public static function connector(): Connector
    {
        return new Connector('Lootably', ConnectorCategory::Direct, website: 'https://lootably.com');
    }

    /**
     * @return array<string, array{label: string, required?: bool, secret?: bool}>
     */
    public static function credentialSchema(): array
    {
        return [
            'api_key' => ['label' => 'API Key', 'required' => true, 'secret' => true],
            'placement_id' => ['label' => 'Placement ID', 'required' => true],
        ];
    }

    public function testConnection(): ConnectionResult
    {
        $response = $this->http()->post(self::ENDPOINT, $this->authPayload());

        if ($response->failed()) {
            return ConnectionResult::fail("Lootably returned HTTP {$response->status()}.");
        }

        $offers = $response->json('data.offers');

        return ConnectionResult::ok('Connected.', is_array($offers) ? count($offers) : 0);
    }

    /**
     * @return iterable<OfferDTO>
     */
    public function fetchOffers(): iterable
    {
        $rows = $this->http()
            ->timeout(180)
            ->post(self::ENDPOINT, $this->authPayload())
            ->throw()
            ->json('data.offers');

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row) || ! isset($row['offerID'])) {
                continue;
            }

            // A variable-revenue offer has no price we can pay a publisher a
            // share of. Skipped rather than stored at a guessed zero.
            if (($row['revenue'] ?? null) === 'variable') {
                continue;
            }

            $multistep = ($row['type'] ?? null) === 'multistep';
            $goals = array_values(array_filter($row['goals'] ?? [], 'is_array'));

            yield new OfferDTO(
                externalId: (string) $row['offerID'],
                name: (string) ($row['name'] ?? 'Untitled offer'),
                trackingUrl: $this->withSubIdMacro((string) ($row['link'] ?? '')),
                // Multi-step offers price each goal; the offer is worth all of
                // them completed.
                payout: $multistep ? $this->sumPayouts($goals, 'revenue') : (float) ($row['revenue'] ?? 0),
                description: $this->nullableString($row['description'] ?? null),
                iconUrl: $this->nullableString($row['image'] ?? null),
                countries: $this->parseCountries($row['countries'] ?? []),
                devices: $this->parseDevices($row['devices'] ?? []),
                categories: $this->categoryNames($row['categories'] ?? null),
                isMultiReward: $multistep && count($goals) > 1,
                // Only a multistep offer's goals are steps. A single-step
                // offer carries one goal that restates the offer itself.
                goals: $multistep ? $this->goalList($goals, 'description', 'revenue') : [],
            );
        }
    }

    public function parsePostback(Request $request): ?PostbackDTO
    {
        $transId = $this->nullableString($request->input('transactionID'));
        $payout = $request->input('revenue');

        if ($transId === null || ! is_numeric($payout)) {
            return null;
        }

        // Lootably reverses by re-firing the same transaction id with a
        // negative revenue, which is why the id alone is the idempotency key
        // and the sign carries the meaning.
        $amount = (float) $payout;
        $reversed = $amount < 0;

        return new PostbackDTO(
            networkTxId: $transId,
            clickId: $this->nullableString($request->input('userID')),
            payout: $reversed ? -abs($amount) : abs($amount),
            status: $reversed ? 'reversed' : 'credited',
            offerExternalId: $this->nullableString($request->input('offerID')),
            offerName: $this->nullableString($request->input('offerName')),
            ip: $this->nullableString($request->input('ip')),
            raw: $request->all(),
        );
    }

    public function verifyPostback(Request $request): bool
    {
        $secret = $this->credential('api_key');

        // Fails closed — the key is a required credential, so a blank one is a
        // misconfiguration, not permission to skip the check.
        if (blank($secret)) {
            return false;
        }

        // ASSUMPTION: Lootably signs `transactionID + revenue` with the API key.
        // A wrong recipe rejects every postback, which looks like an outage.
        $expected = hash_hmac('sha256', (string) $request->input('transactionID').(string) $request->input('revenue'), $secret);

        return hash_equals($expected, (string) $request->input('hash', ''));
    }

    public function buildTrackingUrl(Offer $offer, string $clickId): string
    {
        return str_replace('{click_id}', $clickId, $offer->tracking_url);
    }

    /**
     * @return array<string, string>
     */
    private function authPayload(): array
    {
        return [
            'apiKey' => (string) $this->credential('api_key'),
            'placementID' => (string) $this->credential('placement_id'),
        ];
    }

    /**
     * Links arrive with the placement's own user slot — either a `{userID}`
     * template or a literal `userID=1` from the catalogue fetch.
     */
    private function withSubIdMacro(string $link): string
    {
        $link = str_replace(['{userID}', 'userID=1'], ['{click_id}', 'userID={click_id}'], $link);

        if (str_contains($link, '{click_id}')) {
            return $link;
        }

        return $link.(str_contains($link, '?') ? '&' : '?').'userID={click_id}';
    }
}
