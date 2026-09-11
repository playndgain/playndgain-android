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
 * App id plus an md5 token over the credential pair.
 *
 * Catalogue (`GET /api/static`, `token = md5(apiKey + secretKey)`, nested
 * `goals[].payouts[0].payout`) is their published Static API. Postback is a
 * URL we write: they substitute `{{macros}}` and echo the dashboard secret
 * as a query value. ClaimBits / Vie compare that string; there is no HMAC.
 */
class MonlixDriver extends AbstractDriver
{
    use NormalizesFields;

    private const ENDPOINT = 'https://api.monlix.com/api/static';

    private const SUBID_PARAM = 'subid';

    public static function key(): string
    {
        return 'monlix';
    }

    public static function connector(): Connector
    {
        return new Connector('Monlix', ConnectorCategory::Direct, website: 'https://monlix.com');
    }

    /**
     * @return array<string, string>
     */
    public static function postbackQuery(): array
    {
        return [
            'transaction_id' => '{{transactionId}}',
            'payout' => '{{payout}}',
            'status' => '{{status}}',
            'subid' => '{{subId}}',
            'user_id' => '{{userId}}',
            'offer_name' => '{{taskName}}',
            'ip' => '{{userIp}}',
            'secret' => '{{secretKey}}',
        ];
    }

    /**
     * @return array<string, array{label: string, required?: bool, secret?: bool}>
     */
    public static function credentialSchema(): array
    {
        return [
            'api_key' => ['label' => 'App ID / API Key', 'required' => true],
            'secret_key' => ['label' => 'Secret Key', 'required' => true, 'secret' => true],
        ];
    }

    public function testConnection(): ConnectionResult
    {
        $response = $this->http()->get(self::ENDPOINT, $this->authParams());

        if ($response->failed()) {
            return ConnectionResult::fail("Monlix returned HTTP {$response->status()}.");
        }

        $campaigns = $response->json('campaigns');

        return ConnectionResult::ok('Connected.', is_array($campaigns) ? count($campaigns) : 0);
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
            ->json('campaigns');

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row) || ! isset($row['id'])) {
                continue;
            }

            $goals = array_values(array_filter($row['goals'] ?? [], 'is_array'));

            yield new OfferDTO(
                externalId: (string) $row['id'],
                name: (string) ($row['name'] ?? 'Untitled offer'),
                trackingUrl: $this->withSubIdMacro((string) ($row['url'] ?? '')),
                payout: (float) ($row['payout'] ?? 0),
                description: $this->nullableString($row['description'] ?? null),
                iconUrl: $this->nullableString($row['image'] ?? null),
                countries: $this->parseCountries($row['countries'] ?? []),
                // `os` arrives as a comma-joined string on some campaigns and a
                // list on others.
                devices: $this->parseDevices($row['os'] ?? []),
                categories: $this->categoryNames($row['categories'] ?? null),
                isMultiReward: count($goals) > 1,
                // Monlix keeps the figure one level down, under a list that
                // has held exactly one entry in every payload seen so far.
                goals: $this->goalList($goals, 'name', 'payouts.0.payout'),
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

        // Docs: status 1 = valid, 2 = invalid (chargeback). Payout stays a
        // positive USD figure; we flip the sign from status, same as Vie.
        $reversed = (string) $request->input('status', '1') === '2';
        $amount = abs((float) $payout);

        return new PostbackDTO(
            networkTxId: $transId,
            clickId: $this->nullableString($request->input(self::SUBID_PARAM)),
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
        $secret = $this->credential('secret_key');

        // Fails closed — a blank required secret is a misconfiguration, not
        // permission to skip the check. They send the dashboard value as
        // `secret` because that is the query key on `postbackQuery()`.
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
     * The token is what stops the app id alone being enough to pull a
     * competitor's catalogue.
     *
     * @return array<string, string>
     */
    private function authParams(): array
    {
        $apiKey = (string) $this->credential('api_key');

        return [
            'appid' => $apiKey,
            'token' => md5($apiKey.(string) $this->credential('secret_key')),
        ];
    }

    private function withSubIdMacro(string $link): string
    {
        return $link.(str_contains($link, '?') ? '&' : '?').self::SUBID_PARAM.'={click_id}';
    }
}
