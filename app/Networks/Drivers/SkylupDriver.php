<?php

namespace App\Networks\Drivers;

use App\Models\Offer;
use App\Networks\AbstractDriver;
use App\Networks\Drivers\Concerns\NormalizesFields;
use App\Networks\DTO\ConnectionResult;
use App\Networks\DTO\OfferDTO;
use App\Networks\DTO\PostbackDTO;
use App\Support\Plain;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * One query-string key, one unpaginated Swaarm feed, and a postback whose
 * parameter names are ours to choose rather than theirs to send.
 *
 * Skylup is Swaarm underneath. The Swagger they publish is stale (v1.2,
 * `apiKey`). The catalogue they handed us is v1.3 and `api_key`. A missing
 * or misspelled key is HTTP 403 `Publisher does not exist.` — not an empty
 * list. Reading that as "no offers left" deactivates every Skylup tile we
 * have.
 *
 * Four things about this network are unlike the others here:
 *
 *   - **`payout` is the default-action price and is often $0.** The money
 *     lives on `eventTypes`. A tile priced from the offer field would show
 *     Hero Wars at $0.0075 against $0.15 of payable events. Goals are the
 *     MONETIZATION events; the tile is their sum when any survive.
 *   - **`WEB` is not desktop.** `parseDevices()` maps any token containing
 *     `web` to `desktop`, which would hide a third of this catalogue from
 *     phones. `WEB` is stored as every device. `CHROME OS` / `MACOS` are
 *     aliased to `desktop` because parse does not know them and an empty
 *     list means every device — the expensive direction.
 *   - **The postback contract is a specification we hand over.** Swaarm
 *     substitutes `#{macros}` into whatever URL is saved on the publisher.
 *     The `#` is part of the token and must not be stripped. Paste
 *     `SkylupDriver::postbackUrl()`. They sign nothing; the source-IP
 *     allowlist is the gate. Firing addresses are unpublished, so the
 *     seeded list stays empty and any-source stays on until an AM list is
 *     pasted.
 *   - **`#{id}` is unique per fire.** A REJECTED postback mints a new one.
 *     `reverse()` looks up `network_txid`, so the key is
 *     `{clickId}:{offerId}:{eventId|default}` — the same on credit and
 *     reject. Confirm against a live chargeback; if they reuse `#{id}`,
 *     switch to that.
 *
 * Docs: docs/networks/skylup.md
 */
class SkylupDriver extends AbstractDriver
{
    use NormalizesFields;

    private const ENDPOINT = 'https://feed.skylup.swaarm-clients.com/feed/v1.3/ads';

    /** The slot Swaarm echoes back as `#{click.publisher.clickId}`. */
    private const SUBID_PARAM = 'pub_click_id';

    /**
     * `UK` is not ISO-3166-1 alpha-2. Left as-is it targets a country
     * nothing geolocates to, and every British user is filtered out of an
     * offer bought for them.
     */
    private const COUNTRY_ALIASES = [
        'UK' => 'GB',
    ];

    /**
     * `parseDevices()` does not know these. Left raw they store `[]`, which
     * means every device: a Chrome-OS survey is shown to iPhones it cannot
     * pay for.
     */
    private const DEVICE_ALIASES = [
        'chrome os' => 'desktop',
        'chromeos' => 'desktop',
        'macos' => 'desktop',
        'mac os' => 'desktop',
    ];

    /** Two hundred ISO-2 codes is "worldwide", not a targeting list. */
    private const WORLDWIDE_COUNTRY_THRESHOLD = 200;

    public static function key(): string
    {
        return 'skylup';
    }

    /**
     * @return array<string, string>
     */
    public static function postbackQuery(): array
    {
        return [
            'click_id' => '#{click.publisher.clickId}',
            'payout' => '#{payout.theyGetInDollars}',
            'txid' => '#{id}',
            'status' => '#{status.state}',
            'offer_id' => '#{offer.offerId}',
            'offer_name' => '#{offer.name}',
            'event_id' => '#{offer.eventTypeId}',
            'event_name' => '#{eventType.name}',
            'ip' => '#{click.user.connection.ip}',
        ];
    }

    /**
     * @return array<string, array{label: string, required?: bool, secret?: bool}>
     */
    public static function credentialSchema(): array
    {
        return [
            'api_key' => ['label' => 'Feed API key', 'required' => true, 'secret' => true],
        ];
    }

    public function testConnection(): ConnectionResult
    {
        $response = $this->http()->get(self::ENDPOINT, $this->authParams());

        if ($response->failed()) {
            return ConnectionResult::fail("Skylup returned HTTP {$response->status()}: ".$this->rejection($response));
        }

        $rows = $response->json('ads');

        if (! is_array($rows)) {
            return ConnectionResult::fail('Skylup rejected the request: '.$this->rejection($response));
        }

        return ConnectionResult::ok('Connected.', count($rows));
    }

    /**
     * One call — their feed is unpaginated and already scoped by the
     * publisher's Feed API configurator. Filters we sent would drop inventory
     * the wall is supposed to target itself.
     *
     * @return iterable<OfferDTO>
     */
    public function fetchOffers(): iterable
    {
        $response = $this->http()
            ->timeout(180)
            ->get(self::ENDPOINT, $this->authParams());

        $rows = $response->json('ads');

        // Failing loudly rather than yielding nothing. A 403 "Publisher does
        // not exist." read as an empty catalogue deactivates every Skylup
        // offer we have; the collapse guard only catches that above twenty.
        if ($response->failed() || ! is_array($rows)) {
            throw new RuntimeException('Skylup rejected the offers request: '.$this->rejection($response));
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
        $clickId = $this->nullableString($request->input('click_id'));
        $payout = $request->input('payout');

        if ($clickId === null || ! is_numeric($payout)) {
            return null;
        }

        $status = strtoupper((string) $request->input('status'));
        $reversed = $status === 'REJECTED' || (float) $payout < 0;
        $amount = abs((float) $payout);

        $offerId = $this->nullableString($request->input('offer_id')) ?? '';
        $eventId = $this->nullableString($request->input('event_id')) ?? 'default';

        return new PostbackDTO(
            // ASSUMPTION: a REJECTED fire mints a new `#{id}`. The composite
            // is what lets `reverse()` find the credit. If a live chargeback
            // reuses `#{id}`, switch this to `txid` and drop the composite.
            networkTxId: $clickId.':'.$offerId.':'.$eventId,
            clickId: $clickId,
            payout: $reversed ? -$amount : $amount,
            status: match (true) {
                $reversed => 'reversed',
                $status === 'PENDING' => 'pending',
                default => 'credited',
            },
            offerExternalId: $this->nullableString($request->input('offer_id')),
            offerName: $this->nullableString($request->input('event_name'))
                ?? $this->nullableString($request->input('offer_name')),
            ip: $this->nullableString($request->input('ip')),
            raw: $request->all(),
        );
    }

    public function verifyPostback(Request $request): bool
    {
        // Swaarm documents no signature and no shared postback token. The
        // source-IP allowlist the controller enforces first is the whole of
        // this network's authentication. Empty still rejects when any-source
        // is off.
        return true;
    }

    public function buildTrackingUrl(Offer $offer, string $clickId): string
    {
        return str_replace('{click_id}', $clickId, $this->withSubIdMacro($offer->tracking_url));
    }

    /**
     * @return array<string, string>
     */
    private function authParams(): array
    {
        // `api_key`, not Swagger's `apiKey`. The query string is
        // case-sensitive; the wrong name is HTTP 403.
        return ['api_key' => (string) $this->credential('api_key')];
    }

    /** Whatever they put in the body instead of a catalogue, for the operator. */
    private function rejection(Response $response): string
    {
        $fromJson = $this->nullableString($response->json('error'))
            ?? $this->nullableString($response->json('message'))
            ?? $this->nullableString($response->json('reason'));

        if ($fromJson !== null) {
            return $fromJson;
        }

        $body = trim($response->body());

        if ($body !== '' && ! str_starts_with($body, '{') && ! str_starts_with($body, '[')) {
            return $body;
        }

        return 'no error message given.';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function offer(array $row): ?OfferDTO
    {
        $id = $this->nullableString($row['id'] ?? null);
        $clickUrl = $this->nullableString($row['click_url'] ?? null);
        $currency = strtoupper((string) ($row['payout_currency'] ?? 'USD'));
        $status = strtolower((string) ($row['status'] ?? 'active'));
        $tags = array_values(array_filter(
            is_array($row['tags'] ?? null) ? $row['tags'] : [],
            'is_string',
        ));

        if ($id === null
            || $clickUrl === null
            || $currency !== 'USD'
            || $status !== 'active'
            || in_array('HIDDEN', $tags, true)
            || in_array('No_Traffic', $tags, true)) {
            return null;
        }

        $events = collect(is_array($row['eventTypes'] ?? null) ? $row['eventTypes'] : [])
            ->filter(fn (mixed $event): bool => is_array($event))
            ->filter(fn (array $event): bool => strtoupper((string) ($event['classification'] ?? '')) === 'MONETIZATION')
            ->filter(function (array $event): bool {
                $eventCurrency = data_get($event, 'payout.currency');

                return $eventCurrency === null || strtoupper((string) $eventCurrency) === 'USD';
            })
            ->values()
            ->all();

        $goals = $this->goalList($events, 'name', 'payout.value');
        $payout = $goals !== []
            ? (float) collect($goals)->sum('payout')
            : (float) ($row['payout'] ?? 0);

        if ($payout <= 0 && $goals === []) {
            return null;
        }

        return new OfferDTO(
            externalId: $id,
            name: (string) ($row['name'] ?? 'Untitled offer'),
            trackingUrl: $this->withSubIdMacro($clickUrl),
            payout: $payout,
            description: $this->plain($row['description'] ?? null),
            instructions: $this->plain($row['additional_information'] ?? null)
                ?? $this->plain($row['kpi'] ?? null),
            iconUrl: $this->nullableString($row['creatives_url'] ?? null),
            previewUrl: $this->nullableString($row['preview_url'] ?? null),
            countries: $this->countries(data_get($row, 'targeting.allowedGeoTargeting.countries')),
            devices: $this->devices(data_get($row, 'targeting.allowedDeviceTargeting.os')),
            osMinVersion: $this->plain(data_get($row, 'targeting.allowedDeviceTargeting.minOsVersion')),
            categories: $this->categoryNames($tags),
            conversionType: $this->conversionType($row['leadflow'] ?? null),
            isMultiReward: count($goals) > 1,
            goals: $goals,
            dailyCap: is_numeric($row['daily_leads'] ?? null) ? (int) $row['daily_leads'] : null,
        );
    }

    /**
     * @return list<string>
     */
    private function countries(mixed $countries): array
    {
        $codes = collect(is_array($countries) ? $countries : [$countries])
            ->map(fn (mixed $code): string => strtoupper(trim((string) (is_scalar($code) ? $code : ''))))
            ->map(fn (string $code): string => self::COUNTRY_ALIASES[$code] ?? $code)
            ->all();

        $parsed = $this->parseCountries($codes);

        return count($parsed) >= self::WORLDWIDE_COUNTRY_THRESHOLD ? [] : $parsed;
    }

    /**
     * @return list<string>
     */
    private function devices(mixed $os): array
    {
        $name = strtolower(trim((string) (is_scalar($os) ? $os : '')));

        if ($name === '' || $name === 'web') {
            return [];
        }

        return $this->parseDevices([self::DEVICE_ALIASES[$name] ?? $name]);
    }

    /** Idempotent: a link that already carries the macro is left alone. */
    private function withSubIdMacro(string $link): string
    {
        if ($link === '' || str_contains($link, '{click_id}')) {
            return $link;
        }

        return $link.(str_contains($link, '?') ? '&' : '?').self::SUBID_PARAM.'={click_id}';
    }

    private function plain(mixed $value): ?string
    {
        $text = Plain::text($this->nullableString($value));

        return $this->nullableString($text);
    }
}
