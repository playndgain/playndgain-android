<?php

namespace App\Networks\Drivers;

use App\Enums\ConnectorCategory;
use App\Models\Offer;
use App\Networks\AbstractDriver;
use App\Networks\Connector;
use App\Networks\Drivers\Concerns\ExpandsPayoutVariants;
use App\Networks\Drivers\Concerns\NormalizesFields;
use App\Networks\DTO\ConnectionResult;
use App\Networks\DTO\OfferDTO;
use App\Networks\DTO\PostbackDTO;
use App\Networks\Support\PostbackSecret;
use App\Networks\Support\TenantUrl;
use App\Services\Money\Redactor;
use App\Support\Plain;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Swaarm as a **platform**, not as one partner.
 *
 * `SkylupDriver` is this same API with the host hard-coded, and it stays as it
 * is — it is a working integration with one partner and rewriting it onto this
 * class would risk a live catalogue for no gain. What is new here is that the
 * tenant is configuration: several partners each run their own Swaarm instance,
 * so `feed_url`, the API key and the postback secret all belong to the
 * `networks` row rather than to the class, and two rows on this driver are
 * normal rather than a mistake.
 *
 * Five things are unlike the direct drivers here:
 *
 *   - **The host is typed by an admin.** That makes the catalogue pull a
 *     server-side request to wherever someone pasted, so `TenantUrl` validates
 *     it against Swaarm's own suffixes at save time *and* again here, because a
 *     row can be edited by hand.
 *   - **Both auth parameter names are sent.** The published v1.2 spec says
 *     `apiKey`; the v1.3 tenant behind Skylup answers 403 to anything but
 *     `api_key`. Sending both costs one query parameter and removes a support
 *     round-trip per tenant (D5).
 *   - **`payout` is the default-action price and is often $0.** The money lives
 *     on `eventTypes`, and only the `MONETIZATION` ones. A tile priced from the
 *     offer field alone would show a game at $0.0075 against $0.15 of payable
 *     events. `QUALITY` events are quality signals, not payouts, and are ignored.
 *   - **`WEB` is not desktop.** `parseDevices()` maps anything containing `web`
 *     to desktop, which would hide a third of a Swaarm catalogue from phones.
 *     `WEB` means every device.
 *   - **The postback contract is a specification we hand over.** Swaarm
 *     substitutes `#{macros}` into whatever URL is saved on the publisher; the
 *     `#` is part of the token and must not be stripped. They sign nothing, so
 *     the URL carries a per-account `secret` and `verifyPostback()` also accepts
 *     it as a header for tenants that support custom postback headers.
 *
 * Docs: docs/networks/swaarm.md — including the one unverified assumption that
 * matters to money (`#{id}` stability across a status change).
 */
class SwaarmDriver extends AbstractDriver
{
    use ExpandsPayoutVariants;
    use NormalizesFields;

    /** Hosts a Swaarm tenant can legitimately live on. */
    public const ALLOWED_HOSTS = ['.swaarm-clients.com', '.swaarm.com'];

    /** The slot Swaarm echoes back as `#{click.publisher.clickId}`. */
    private const SUBID_PARAM = 'pub_click_id';

    /**
     * `UK` is not ISO-3166-1 alpha-2. Left as-is it targets a country nothing
     * geolocates to, and every British user is filtered out of an offer bought
     * for them.
     */
    private const COUNTRY_ALIASES = ['UK' => 'GB'];

    /**
     * `parseDevices()` does not know these. Left raw they store `[]`, which
     * means every device: a Chrome-OS survey shown to iPhones that cannot pay.
     */
    private const DEVICE_ALIASES = [
        'chrome os' => 'desktop',
        'chromeos' => 'desktop',
        'macos' => 'desktop',
        'mac os' => 'desktop',
        'windows' => 'desktop',
    ];

    /** Two hundred ISO-2 codes is "worldwide", not a targeting list. */
    private const WORLDWIDE_COUNTRY_THRESHOLD = 200;

    private const SKIP_NON_USD = 'non-USD payout';

    private const SKIP_NO_LINK = 'missing tracking link or id';

    private const SKIP_TARGETING = 'targeting dimension the wall cannot filter on';

    public static function key(): string
    {
        return 'swaarm';
    }

    public static function connector(): Connector
    {
        return new Connector(
            'Swaarm',
            ConnectorCategory::Platform,
            supportsMultipleAccounts: true,
            // Swaarm's conversion state machine treats REJECTED as the
            // advertiser taking the conversion back, including after it was
            // approved and paid.
            rejectedAfterCreditReverses: true,
            website: 'https://www.swaarm.com',
        );
    }

    /**
     * @return array<string, array{label: string, required?: bool, secret?: bool, rule?: callable}>
     */
    public static function credentialSchema(): array
    {
        return [
            'feed_url' => [
                'label' => 'Feed URL',
                'required' => true,
                // Not secret: it is a host, an admin needs to read it back to
                // check it, and masking it would hide the one field most likely
                // to be mistyped.
                'secret' => false,
                'rule' => fn (?string $value): bool => TenantUrl::passes($value, self::ALLOWED_HOSTS),
                'rule_message' => 'The feed URL must be an https address on swaarm-clients.com or swaarm.com.',
                'placeholder' => 'https://feed.tenant.swaarm-clients.com/feed/v1.2/ads',
            ],
            'api_key' => ['label' => 'Feed API key', 'required' => true, 'secret' => true],
            'postback_secret' => ['label' => 'Postback secret', 'required' => true, 'secret' => true],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function postbackQuery(): array
    {
        return [
            'click_id' => '#{click.publisher.clickId}',
            'txid' => '#{id}',
            'payout' => '#{payout.theyGetInDollarsExact}',
            'status' => '#{status.state}',
            'offer_id' => '#{offer.offerId}',
            'offer_name' => '#{offer.name}',
            'event_id' => '#{offer.eventTypeId}',
        ];
    }

    /**
     * The macro template plus this account's own shared secret.
     *
     * The template is identical for every tenant, so the secret is the only
     * thing that tells one account's fires from another's beyond the path.
     */
    public function postbackUrl(): string
    {
        $url = static::postbackPath((string) $this->network->postback_key);
        $secret = (string) $this->credential('postback_secret');

        return $secret === '' ? $url : $url.'&secret='.rawurlencode($secret);
    }

    public function testConnection(): ConnectionResult
    {
        $response = $this->http()->get($this->endpoint(), $this->authParams());

        if ($response->failed()) {
            return ConnectionResult::fail("Swaarm returned HTTP {$response->status()}: ".$this->rejection($response));
        }

        $rows = $response->json('ads');

        if (! is_array($rows)) {
            return ConnectionResult::fail('Swaarm rejected the request: '.$this->rejection($response));
        }

        return ConnectionResult::ok('Connected.', count($rows));
    }

    /**
     * One call — the feed is unpaginated and already scoped to the publisher by
     * their Feed API configurator. Filters we sent would drop inventory the wall
     * is supposed to target itself.
     *
     * @return iterable<OfferDTO>
     */
    public function fetchOffers(): iterable
    {
        $response = $this->http()->timeout(180)->get($this->endpoint(), $this->authParams());

        $rows = $response->json('ads');

        // Failing loudly rather than yielding nothing. A 403 "Publisher does not
        // exist." read as an empty catalogue deactivates every offer this
        // account has; the collapse guard only catches that above twenty.
        if ($response->failed() || ! is_array($rows)) {
            throw new RuntimeException('Swaarm rejected the offers request: '.$this->rejection($response));
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            yield from $this->offersFor($row);
        }
    }

    public function parsePostback(Request $request): ?PostbackDTO
    {
        $clickId = $this->nullableString($request->input('click_id'));
        $payout = $request->input('payout');
        $txId = $this->nullableString($request->input('txid'));

        if ($clickId === null || $txId === null || ! is_numeric($payout)) {
            return null;
        }

        // Case-insensitive because the documented values are title case and
        // tenants have been seen sending upper. Anything unrecognised is
        // `pending`, never `credited`: paying on a word we do not know is the
        // expensive direction to be wrong in.
        $state = strtolower(trim((string) $request->input('status')));
        $amount = abs((float) $payout);

        // `Rejected` is reported as a rejection, not as a reversal. What it
        // costs is the processor's decision: on a pending row it is a refusal,
        // and on a credited one the matrix charges it back because this
        // connector declares `rejectedAfterCreditReverses`. Only an explicitly
        // negative payout is a reversal here — that is the tenant quoting the
        // amount it is taking back rather than naming a decision.
        $reversed = (float) $payout < 0;

        return new PostbackDTO(
            networkTxId: $txId,
            clickId: $clickId,
            payout: $reversed ? -$amount : $amount,
            status: match (true) {
                $reversed => 'reversed',
                $state === 'rejected' => 'rejected',
                $state === 'approved' => 'credited',
                default => 'pending',
            },
            offerExternalId: $this->nullableString($request->input('offer_id')),
            offerName: $this->nullableString($request->input('offer_name')),
            ip: $this->nullableString($request->input('ip')),
            // Redacted on the way in. `raw` is persisted on the log row and read
            // back by the replay command, and the URL we handed Swaarm carries
            // this account's secret in it.
            raw: Redactor::keys($request->all()),
        );
    }

    /**
     * Three places the secret may arrive, because tenants differ in whether
     * they support custom postback headers, and the query parameter is the one
     * every tenant can do.
     */
    public function verifyPostback(Request $request): bool
    {
        $bearer = $request->bearerToken();

        return PostbackSecret::matchesAny((string) $this->credential('postback_secret'), [
            $bearer,
            $request->header('X-Postback-Secret'),
            $this->nullableString($request->input('secret')),
        ]);
    }

    public function buildTrackingUrl(Offer $offer, string $clickId): string
    {
        return str_replace('{click_id}', $clickId, $this->withSubIdMacro($offer->tracking_url));
    }

    /**
     * Re-validated on every call rather than once at construction: a `networks`
     * row can be written by a console command or by hand, and the form rule is
     * not in that path.
     */
    private function endpoint(): string
    {
        return TenantUrl::validate($this->credential('feed_url'), self::ALLOWED_HOSTS);
    }

    /**
     * @return array<string, string>
     */
    private function authParams(): array
    {
        $key = (string) $this->credential('api_key');

        // D5. The spec documents `apiKey`; the v1.3 tenant we have seen answers
        // 403 to anything but `api_key`. Sending both is one extra parameter
        // and no support round-trip per tenant.
        return ['apiKey' => $key, 'api_key' => $key];
    }

    /**
     * One ad, however many payout variants it prices.
     *
     * @param  array<string, mixed>  $row
     * @return list<OfferDTO>
     */
    private function offersFor(array $row): array
    {
        $id = $this->nullableString($row['id'] ?? null);
        $clickUrl = $this->nullableString($row['click_url'] ?? null);

        if ($id === null || $clickUrl === null) {
            $this->skips()->skip($id ?? '(no id)', self::SKIP_NO_LINK);

            return [];
        }

        if (strtoupper((string) ($row['payout_currency'] ?? 'USD')) !== 'USD') {
            $this->skips()->skip($id, self::SKIP_NON_USD);

            return [];
        }

        $targeting = is_array($row['targeting'] ?? null) ? $row['targeting'] : [];

        if (! $this->targetingIsExpressible($targeting)) {
            $this->skips()->skip($id, self::SKIP_TARGETING);

            return [];
        }

        $events = $this->monetizationEvents($row);
        $goals = $this->goalList($events, 'name', 'payout.value');

        // The offer's own `payout` is the default action's price and is often
        // zero; the payable events are the tile.
        $basePayout = $goals !== []
            ? (float) collect($goals)->sum('payout')
            : (float) ($row['payout'] ?? 0);

        $variants = [[
            'countries' => $this->countries(data_get($targeting, 'allowedGeoTargeting.countries')),
            'devices' => $this->devices(data_get($targeting, 'allowedDeviceTargeting.os')),
            'payout' => $basePayout,
            'goals' => $goals,
        ]];

        foreach (is_array($row['targeted_payouts'] ?? null) ? $row['targeted_payouts'] : [] as $targeted) {
            if (! is_array($targeted)) {
                continue;
            }

            $variant = $this->targetedVariant($id, $targeted);

            if ($variant !== null) {
                $variants[] = $variant;
            }
        }

        return $this->expandVariants(
            $id,
            $variants,
            fn (array $variant, string $externalId): OfferDTO => $this->offer($row, $clickUrl, $variant, $externalId),
        );
    }

    /**
     * A targeted payout we can express, or null with the reason recorded.
     *
     * @param  array<string, mixed>  $targeted
     * @return array<string, mixed>|null
     */
    private function targetedVariant(string $id, array $targeted): ?array
    {
        if (strtoupper((string) ($targeted['payout_currency'] ?? 'USD')) !== 'USD') {
            $this->skips()->skip($id, self::SKIP_NON_USD);

            return null;
        }

        // A payout that applies only to certain sub-ids is priced along a
        // dimension the wall has no concept of; showing it to everyone would
        // quote a price most users cannot earn.
        if (filled($targeted['allowedSubIds'] ?? null) || filled($targeted['disallowedSubIds'] ?? null)) {
            $this->skips()->skip($id, self::SKIP_TARGETING);

            return null;
        }

        if (! $this->targetingIsExpressible($targeted)) {
            $this->skips()->skip($id, self::SKIP_TARGETING);

            return null;
        }

        return [
            'countries' => $this->countries(data_get($targeted, 'allowedGeoTargeting.countries')),
            'devices' => $this->devices(data_get($targeted, 'allowedDeviceTargeting.os')),
            'payout' => (float) ($targeted['payout'] ?? 0),
            'goals' => [],
        ];
    }

    /**
     * Countries and an OS are the only two dimensions `offers` can carry.
     * Regions, cities, zip codes, OS version bounds, device brands and every
     * deny-list are targeting the wall cannot honour.
     *
     * @param  array<string, mixed>  $targeting
     */
    private function targetingIsExpressible(array $targeting): bool
    {
        foreach (['allowedGeoTargeting.regions', 'allowedGeoTargeting.cities', 'allowedGeoTargeting.zipCodes'] as $path) {
            if (filled(data_get($targeting, $path))) {
                return false;
            }
        }

        foreach (['allowedDeviceTargeting.maxOsVersion', 'allowedDeviceTargeting.deviceBrand'] as $path) {
            if (filled(data_get($targeting, $path))) {
                return false;
            }
        }

        return $this->disallowBlockIsEmpty(
            $targeting['disallowedGeoTargeting'] ?? null,
            ['cities', 'countries', 'regions', 'zipCodes'],
        ) && $this->disallowBlockIsEmpty(
            $targeting['disallowedDeviceTargeting'] ?? null,
            ['deviceBrand', 'maxOsVersion', 'minOsVersion', 'os'],
        );
    }

    /**
     * Swaarm always ships disallow blocks as objects with null/empty fields.
     * Treat those as "no deny rules" rather than as a populated deny-list.
     *
     * @param  list<string>  $keys
     */
    private function disallowBlockIsEmpty(mixed $block, array $keys): bool
    {
        if (! is_array($block)) {
            return true;
        }

        foreach ($keys as $key) {
            if (filled($block[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Payable events only. `QUALITY` events are signals the advertiser watches,
     * not money the publisher earns, and summing them into the tile would quote
     * a price that never pays.
     *
     * @param  array<string, mixed>  $row
     * @return list<array<string, mixed>>
     */
    private function monetizationEvents(array $row): array
    {
        return collect(is_array($row['eventTypes'] ?? null) ? $row['eventTypes'] : [])
            ->filter(fn (mixed $event): bool => is_array($event))
            ->filter(fn (array $event): bool => strtoupper((string) ($event['classification'] ?? '')) === 'MONETIZATION')
            ->filter(function (array $event): bool {
                $currency = data_get($event, 'payout.currency');

                return $currency === null || strtoupper((string) $currency) === 'USD';
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $variant
     */
    private function offer(array $row, string $clickUrl, array $variant, string $externalId): OfferDTO
    {
        /** @var list<array{name: string, payout: float}> $goals */
        $goals = $variant['goals'] ?? [];

        return new OfferDTO(
            externalId: $externalId,
            name: (string) ($row['name'] ?? $row['app_title'] ?? 'Untitled offer'),
            trackingUrl: $this->withSubIdMacro($clickUrl),
            payout: (float) $variant['payout'],
            description: $this->plain($row['description'] ?? null) ?? $this->plain($row['app_description'] ?? null),
            instructions: $this->plain($row['restrictions'] ?? null),
            iconUrl: $this->nullableString(data_get($row, 'icons.256'))
                ?? $this->nullableString(data_get($row, 'icons.128'))
                ?? $this->nullableString(data_get($row, 'icons.96'))
                ?? $this->nullableString(data_get($row, 'icons.72')),
            previewUrl: $this->nullableString($row['preview_url'] ?? null),
            countries: $variant['countries'],
            devices: $variant['devices'],
            osMinVersion: $this->plain(data_get($row, 'targeting.allowedDeviceTargeting.minOsVersion')),
            categories: $this->categoryNames($row['app_categories'] ?? null),
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

        // `WEB` is every device on this platform, not desktop.
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

    /** Whatever they put in the body instead of a catalogue, for the operator. */
    private function rejection(Response $response): string
    {
        $fromJson = $this->nullableString($response->json('errors.0.message'))
            ?? $this->nullableString($response->json('errors.0.reason'))
            ?? $this->nullableString($response->json('error'))
            ?? $this->nullableString($response->json('message'));

        if ($fromJson !== null) {
            return $fromJson;
        }

        $body = trim($response->body());

        if ($body !== '' && ! str_starts_with($body, '{') && ! str_starts_with($body, '[')) {
            return $body;
        }

        return 'no error message given.';
    }

    private function plain(mixed $value): ?string
    {
        return $this->nullableString(Plain::text($this->nullableString($value)));
    }
}
