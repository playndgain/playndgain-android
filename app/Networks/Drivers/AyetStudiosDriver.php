<?php

namespace App\Networks\Drivers;

use App\Enums\ConnectorCategory;
use App\Enums\DeviceType;
use App\Enums\IdentityKind;
use App\Models\Offer;
use App\Models\OfferClick;
use App\Networks\AbstractDriver;
use App\Networks\Connector;
use App\Networks\Contracts\ResolvesTrackingUrlPerUser;
use App\Networks\Drivers\Concerns\NormalizesFields;
use App\Networks\DTO\ConnectionResult;
use App\Networks\DTO\OfferDTO;
use App\Networks\DTO\PostbackDTO;
use App\Networks\Support\CountryProbeIps;
use App\Networks\Support\ProbeSnapshots;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

/**
 * ayeT through its Offerwall API, the only adslot type we have: one GET per
 * user, no API key, and an answer filtered to that user.
 *
 *   GET https://www.ayetstudios.com/offers/offerwall_api/{adslot}
 *       ?external_identifier=…&ip=…&user_agent=…&num_offers=1000
 *
 * Three facts about that endpoint shape everything below:
 *
 *   - The answer is filtered by the caller's country (from `ip`) and device
 *     (from `user_agent`), and the offers carry no country list. The only way
 *     to learn where an offer runs is to ask as someone standing there. So the
 *     catalogue is a probe matrix, country × {android, ios, desktop}, and an
 *     offer's countries are the cells whose latest answer contained it. See
 *     `fetchOffers()` for the tiers and the pacing.
 *   - `tracking_link` is minted per user. Every offer in the answer carries a
 *     signed token for the `external_identifier` that asked, so the link a
 *     probe stored belongs to a probe. It is kept for the admin and publisher
 *     previews only; a real click asks again as its own user
 *     (`resolveTrackingUrl()`) and never falls back to the stored one.
 *   - Amounts are in the adslot's currency. `payout_base` is the figure to
 *     read (`payout` can include currency sales, `currency_amount` is 0), and
 *     `coins_per_usd` turns it into the USD the rest of the app expects.
 *
 * Errors arrive two ways: HTTP 200 with `{status: error, errorCode,
 * errorMessage}` (101 is an unknown adslot), and HTTP 400/401/429 with
 * `{status: error, error}`. 429 is ayeT throttling us and is treated as a
 * signal to stop, never to retry.
 *
 * The postback contract did not change with the API. We write it; an admin
 * pastes it under Placements → Settings → Conversion Callback:
 *
 *   https://{host}/postback/{key}
 *       ?callback_type={callback_type}&transaction_id={transaction_id}
 *       &is_chargeback={is_chargeback}&payout_usd={payout_usd}
 *       &currency_amount={currency_amount}&custom_1={custom_1}
 *       &external_identifier={external_identifier}&offer_id={offer_id}
 *       &offer_name={offer_name}&ip={ip}
 *
 * Without `transaction_id` or `payout_usd` a postback cannot be parsed and
 * the 422 buys twelve retries over an hour; any other macro dropped fails
 * silently. Postbacks are signed with `X-Ayetstudios-Security-Hash` when the
 * account-level publisher API key is configured, and the six source IPs on
 * the seeded row are the other gate. A partial IP list is the failure that
 * costs money: the network looks fine and the missing addresses' conversions
 * are rejected at the door.
 */
class AyetStudiosDriver extends AbstractDriver implements ResolvesTrackingUrlPerUser
{
    use NormalizesFields;

    private const ENDPOINT = 'https://www.ayetstudios.com/offers/offerwall_api/';

    /**
     * Our click id rides in `custom_1`. ayeT appends a `custom_1` passed to
     * the API onto every link verbatim, so appending it ourselves is the same
     * link, and it comes back in the callback unchanged.
     */
    private const CLICK_PARAM = 'custom_1';

    private const HASH_HEADER = 'X-Ayetstudios-Security-Hash';

    /** A plausible current browser per probe platform. The API reads the device from this. */
    private const PLATFORM_AGENTS = [
        'android' => 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Mobile Safari/537.36',
        'ios' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1',
        'desktop' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
    ];

    /** The API's default page is small; ask for everything. */
    private const NUM_OFFERS = 1000;

    /** Wall-traffic countries probed every run, busiest first. The rest roll. */
    private const HOT_COUNTRIES = 25;

    private const ROLLING_MIN = 5;

    private const ROLLING_MAX = 30;

    /** Hard ceiling on API calls in one sync run, whatever the tiers ask for. */
    private const MAX_CALLS_PER_RUN = 120;

    /** Stop probing after this long; the job's own timeout is 600s. */
    private const RUN_BUDGET_SECONDS = 420;

    /** Gap between probe calls, jittered, so a run reads as traffic rather than a sweep. */
    private const PACE_MIN_MS = 1500;

    private const PACE_MAX_MS = 2000;

    private const MAX_CONSECUTIVE_FAILURES = 3;

    /** A cell older than this no longer says anything about its country. */
    private const SNAPSHOT_TTL_HOURS = 72;

    private const LINK_CACHE_MINUTES = 15;

    /** After a 429, neither the sync nor a click calls ayeT again for this long. */
    private const THROTTLE_COOLDOWN_SECONDS = 60;

    /** A click waits on this call; a user will not sit through the 30s catalogue timeout. */
    private const CLICK_TIMEOUT_SECONDS = 5;

    /** Tolerated drift between a callback's currency_amount/payout_usd and coins_per_usd. */
    private const RATE_TOLERANCE = 0.02;

    public static function key(): string
    {
        return 'ayetstudios';
    }

    public static function connector(): Connector
    {
        return new Connector('AyetStudios', ConnectorCategory::Direct, website: 'https://www.ayetstudios.com');
    }

    /**
     * @return array<string, string>
     */
    public static function postbackQuery(): array
    {
        return [
            'callback_type' => '{callback_type}',
            'transaction_id' => '{transaction_id}',
            'is_chargeback' => '{is_chargeback}',
            'payout_usd' => '{payout_usd}',
            'currency_amount' => '{currency_amount}',
            'custom_1' => '{custom_1}',
            'external_identifier' => '{external_identifier}',
            'offer_id' => '{offer_id}',
            'offer_name' => '{offer_name}',
            'ip' => '{ip}',
        ];
    }

    /**
     * @return array<string, array{label: string, required?: bool, secret?: bool, helper?: string, rule?: callable(?string): bool, rule_message?: string, placeholder?: string}>
     */
    public static function credentialSchema(): array
    {
        return [
            'adslot_id' => [
                'label' => 'Adslot ID (Offerwall API adslot)',
                'required' => true,
            ],
            'coins_per_usd' => [
                'label' => 'Adslot currency per USD',
                'required' => true,
                'helper' => '1000 means $1 = 1000 coins. Offers and callbacks are priced in the adslot currency; this converts them to USD.',
                'placeholder' => '1000',
                'rule' => fn (?string $value): bool => is_numeric($value) && (float) $value > 0,
                'rule_message' => 'Enter a number greater than zero.',
            ],
            'publisher_api_key' => [
                'label' => 'Publisher API key (account-level, signs postbacks)',
                'required' => false,
                'secret' => true,
            ],
            'probe_countries' => [
                'label' => 'Extra countries to refresh every sync',
                'required' => false,
                'helper' => 'Comma-separated ISO-2 codes, e.g. US, DE. Countries with wall traffic in the last 30 days are refreshed every sync already; every other country is refreshed on a rolling basis.',
                'placeholder' => 'US, DE',
            ],
        ];
    }

    public function testConnection(): ConnectionResult
    {
        try {
            // From the server's own IP: this checks the adslot answers, not
            // what any particular country sees.
            $response = $this->request(30)->get($this->endpoint(), [
                'external_identifier' => $this->identifier('connection-test'),
                'user_agent' => self::PLATFORM_AGENTS['android'],
                'num_offers' => self::NUM_OFFERS,
            ]);
        } catch (ConnectionException $e) {
            return ConnectionResult::fail('Could not reach ayeT Studios: '.$e->getMessage());
        }

        if ($this->succeeded($response)) {
            $offers = $response->json('offers');

            return ConnectionResult::ok('Connected.', is_array($offers) ? count($offers) : 0);
        }

        $prefix = $response->successful()
            ? 'ayeT Studios rejected the request: '
            : "ayeT Studios returned HTTP {$response->status()}: ";

        return ConnectionResult::fail($prefix.$this->statusText($response));
    }

    /**
     * Probe, store, then rebuild the whole catalogue from everything stored.
     *
     * Two tiers of countries. Hot: the admin's extra list, then the countries
     * with wall traffic in the last 30 days, busiest first, capped at
     * HOT_COUNTRIES; probed every run on all three platforms. Rolling: every
     * other country in `CountryProbeIps`, stalest first, sized from the sync
     * interval so the whole table is walked about once a day (clamped to
     * ROLLING_MIN–ROLLING_MAX countries per run).
     *
     * The run is deliberately quiet: one call at a time, 1.5–2s apart with
     * jitter, at most MAX_CALLS_PER_RUN calls and RUN_BUDGET_SECONDS of wall
     * time, and hot cells first so a cut-short run loses rolling coverage
     * rather than the countries users are in. A 429 ends the run on the spot.
     *
     * A cut-short run is not a failed run. Cells not probed keep their last
     * answer, and the catalogue is rebuilt from every cell younger than
     * SNAPSHOT_TTL_HOURS, so a throttled afternoon costs freshness rather than
     * inventory. The one thing that throws is having no stored cell at all:
     * yielding nothing would tell the sync job every ayeT offer is gone.
     *
     * @return iterable<OfferDTO>
     */
    public function fetchOffers(): iterable
    {
        $rate = $this->coinsPerUsd();

        if (blank($this->credential('adslot_id'))) {
            throw new RuntimeException('ayeT Studios: adslot_id is not configured.');
        }

        $store = new ProbeSnapshots((int) $this->network->id);
        $store->prune(now()->subHours(self::SNAPSHOT_TTL_HOURS));

        $this->probeMatrix($store);

        $catalogue = $store->catalogue();

        if ($catalogue === [] && $store->oldestProbePerCountry() === []) {
            throw new RuntimeException('ayeT Studios: no probe has succeeded in the last '.self::SNAPSHOT_TTL_HOURS.' hours, so there is no catalogue to publish.');
        }

        foreach ($catalogue as $externalId => ['payload' => $payload, 'countries' => $countries]) {
            // Never empty in practice: an offer is only here because a cell
            // named it. Guarded anyway, because an empty list is "worldwide"
            // to the wall.
            if ($countries === []) {
                continue;
            }

            yield $this->toDto((string) $externalId, $payload, $countries, $rate);
        }
    }

    public function parsePostback(Request $request): ?PostbackDTO
    {
        $transactionId = $this->nullableString($request->input('transaction_id'));
        $payout = $request->input('payout_usd');

        // The transaction id is the idempotency key. A missing click id is
        // not a reason to refuse: a DTO with a null click logs once at 200
        // where an admin can attach it, and null here buys twelve retries.
        if ($transactionId === null || ! is_numeric($payout)) {
            return null;
        }

        $amount = (float) $payout;

        // Three signals, any one believed alone. Reading a chargeback as a
        // credit pays twice for money the advertiser took back; reading a
        // credit as a chargeback finds nothing to reverse and says so.
        $reversed = (string) $request->input('callback_type') === 'chargeback'
            || (string) $request->input('is_chargeback') === '1'
            || $amount < 0;

        $this->checkRate($request, $amount, $transactionId);

        return new PostbackDTO(
            // ayeT prefixes `r-` on a chargeback's transaction id and nowhere
            // else, and reversal finds its credit by exact `network_txid`.
            // Stripped once and only on a reversal, so a credit whose own id
            // starts `r-` still meets its `r-r-` chargeback. Sibling callbacks
            // for one event share a transaction id and collapse into one credit
            // at the unique index, which is the direction to err in.
            networkTxId: $reversed && str_starts_with($transactionId, 'r-')
                ? substr($transactionId, 2)
                : $transactionId,
            // `custom_1` only. `external_identifier` now carries our hashed
            // user identifier (see `userIdentifier()`), never a click id, so
            // reading it as one would attach conversions to nothing, or worse.
            clickId: $this->nullableString($request->input(self::CLICK_PARAM)),
            payout: $reversed ? -abs($amount) : abs($amount),
            status: $reversed ? 'reversed' : 'credited',
            offerExternalId: $this->nullableString($request->input('offer_id')),
            offerName: $this->nullableString($request->input('offer_name')),
            // No `endUserId`: nothing ayeT sends is the publisher's user id.
            // The processor takes it from the click, which is what the ban
            // list and fraud rules are keyed on.
            ip: $this->nullableString($request->input('ip')),
            raw: $request->all(),
        );
    }

    /**
     * ayeT's own sample: `ksort($_GET, SORT_STRING)`, then HMAC-SHA256 of
     * `http_build_query($params, '', '&')` with the publisher API key.
     *
     * Built from the raw query string, not `$request->all()`. TrimStrings and
     * ConvertEmptyStringsToNull run on the postback route: a trimmed value no
     * longer hashes the same, and `http_build_query` drops a null outright, so
     * any callback with an empty macro (`custom_1=` is common) would fail.
     * `parse_str` still decodes `+` and `%20` the way `$_GET` does, which is
     * why the pairs are rebuilt rather than hashing the raw bytes.
     *
     * Open when no key is configured: the key is optional and account-level,
     * and the source-IP allowlist, which rejects when empty, is the floor.
     */
    public function verifyPostback(Request $request): bool
    {
        $apiKey = $this->credential('publisher_api_key');

        if (blank($apiKey)) {
            return true;
        }

        parse_str((string) $request->server('QUERY_STRING'), $params);
        ksort($params, SORT_STRING);

        return hash_equals(
            hash_hmac('sha256', http_build_query($params, '', '&'), $apiKey),
            $this->nullableString($request->header(self::HASH_HEADER)) ?? '',
        );
    }

    /**
     * Previews only. The stored link belongs to a probe identity, which is
     * harmless for an admin or publisher looking at where an offer goes and
     * wrong for anyone who could convert on it. Real clicks go through
     * `resolveTrackingUrl()`.
     */
    public function buildTrackingUrl(Offer $offer, string $clickId): string
    {
        return $this->withClickId((string) $offer->tracking_url, $clickId);
    }

    /**
     * The link ayeT minted for this click's user, with our click id on it.
     *
     * Asks as the user: their hashed identifier, the IP and user agent the
     * click was recorded with (masked when the app anonymises IPs, which still
     * places the country). The user's whole {offer => link} map is cached for
     * LINK_CACHE_MINUTES per identifier, country and device, so browsing the
     * wall and tapping several offers costs one call. Null when the offer is
     * not in the user's answer, on any error, and while a 429 cooldown runs.
     */
    public function resolveTrackingUrl(Offer $offer, OfferClick $click): ?string
    {
        $identifier = $this->userIdentifier((int) $click->app_id, (string) $click->end_user_id);
        $device = $click->device_type instanceof DeviceType ? $click->device_type->value : (string) $click->device_type;
        $key = sprintf('ayet:links:%d:%s:%s:%s', (int) $this->network->id, $identifier, (string) $click->country, $device);

        $links = Cache::get($key);

        if (! is_array($links)) {
            $links = $this->fetchUserLinks($identifier, $click, $device);

            if ($links === null) {
                return null;
            }

            Cache::put($key, $links, now()->addMinutes(self::LINK_CACHE_MINUTES));
        }

        $link = $links[(string) $offer->external_id] ?? null;

        return is_string($link) && $link !== '' ? $this->withClickId($link, (string) $click->click_id) : null;
    }

    /**
     * @return array<string, string>|null offer id => tracking link; null on any failure
     */
    private function fetchUserLinks(string $identifier, OfferClick $click, string $device): ?array
    {
        if ($this->coolingDown() || blank($this->credential('adslot_id'))) {
            return null;
        }

        $query = [
            'external_identifier' => $identifier,
            'ip' => (string) $click->ip,
            // The API reads the device from this; a click with no user agent
            // is asked about as the device it was recorded as.
            'user_agent' => $this->nullableString($click->user_agent) ?? self::PLATFORM_AGENTS[$device] ?? self::PLATFORM_AGENTS['desktop'],
            'num_offers' => self::NUM_OFFERS,
        ];

        if ($device === DeviceType::Desktop->value) {
            $query['include_mobile_offers'] = 'true';
        }

        try {
            $response = $this->request(self::CLICK_TIMEOUT_SECONDS)->get($this->endpoint(), $query);
        } catch (Throwable $e) {
            Log::warning('ayeT per-user link lookup failed', ['network' => $this->network->id, 'error' => $e->getMessage()]);

            return null;
        }

        if ($this->throttled($response)) {
            $this->startCooldown();

            return null;
        }

        if (! $this->succeeded($response)) {
            Log::warning('ayeT per-user link lookup refused', [
                'network' => $this->network->id,
                'status' => $response->status(),
                'error' => $this->statusText($response),
            ]);

            return null;
        }

        $links = [];

        foreach ($this->rows($response) as $row) {
            $link = $this->nullableString($row['tracking_link'] ?? null);

            if ($link !== null) {
                $links[(string) $row['id']] = $link;
            }
        }

        return $links;
    }

    /**
     * A run that stops on a 429 or on its budget keeps what it has. A run in
     * which ayeT answered and every answer was a failure throws instead: the
     * stored cells would otherwise be republished as a healthy sync for three
     * days while nobody is told, then vanish at once. Nothing has been yielded
     * at that point, so the job deactivates nothing.
     */
    private function probeMatrix(ProbeSnapshots $store): void
    {
        $deadline = microtime(true) + self::RUN_BUDGET_SECONDS;
        $calls = 0;
        $failures = 0;
        $successes = 0;
        $lastError = null;

        foreach ($this->plannedCells($store) as [$country, $platform]) {
            if ($calls >= self::MAX_CALLS_PER_RUN || microtime(true) >= $deadline || $this->coolingDown()) {
                break;
            }

            if ($calls > 0) {
                Sleep::for(random_int(self::PACE_MIN_MS, self::PACE_MAX_MS))->milliseconds();
            }

            $calls++;

            $response = $this->probe($country, $platform);

            if ($response !== null && $this->throttled($response)) {
                $this->startCooldown();

                Log::warning('ayeT probe run stopped early: throttled', [
                    'network' => $this->network->id,
                    'calls' => $calls,
                    'at' => "{$country}/{$platform}",
                ]);

                return;
            }

            if ($response !== null && $this->succeeded($response)) {
                $failures = 0;
                $successes++;
                $store->record($country, $platform, $this->payloads($response), now());

                continue;
            }

            // An unknown adslot or a refused credential is the same answer in
            // every cell. Throwing on the first one puts the reason in
            // `last_sync_error` instead of spending the run collecting it.
            if ($response !== null && $this->configurationError($response)) {
                throw new RuntimeException('ayeT Studios rejected the offers request: '.$this->statusText($response));
            }

            $lastError = $response === null
                ? 'connection failed'
                : "HTTP {$response->status()}: {$this->statusText($response)}";

            Log::warning('ayeT probe failed', [
                'network' => $this->network->id,
                'at' => "{$country}/{$platform}",
                'error' => $lastError,
            ]);

            if (++$failures >= self::MAX_CONSECUTIVE_FAILURES) {
                break;
            }
        }

        if ($successes === 0 && $lastError !== null) {
            throw new RuntimeException("ayeT Studios: every probe this run failed ({$lastError}).");
        }
    }

    private function probe(string $country, string $platform): ?Response
    {
        $query = [
            'external_identifier' => $this->identifier("probe:{$country}:{$platform}"),
            'ip' => (string) CountryProbeIps::for($country),
            'user_agent' => self::PLATFORM_AGENTS[$platform],
            'num_offers' => self::NUM_OFFERS,
        ];

        // Desktop answers with no offers at all unless mobile ones are asked for.
        if ($platform === 'desktop') {
            $query['include_mobile_offers'] = 'true';
        }

        try {
            return $this->request(30)->get($this->endpoint(), $query);
        } catch (ConnectionException) {
            return null;
        }
    }

    /**
     * Hot cells first, then rolling ones.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function plannedCells(ProbeSnapshots $store): array
    {
        $known = CountryProbeIps::countries();
        $hot = array_slice(array_values(array_unique(array_filter(
            [...$this->parseCountries($this->credential('probe_countries')), ...$this->trafficCountries()],
            fn (string $country): bool => in_array($country, $known, true),
        ))), 0, self::HOT_COUNTRIES);

        $oldest = $store->oldestProbePerCountry();
        $rolling = array_values(array_diff($known, $hot));

        // Never-probed first, then stalest; alphabetical within a tie so a
        // run is reproducible.
        usort($rolling, fn (string $a, string $b): int => [isset($oldest[$a]) ? $oldest[$a]->getTimestamp() : 0, $a]
            <=> [isset($oldest[$b]) ? $oldest[$b]->getTimestamp() : 0, $b]);

        $cells = [];

        foreach ([...$hot, ...array_slice($rolling, 0, $this->rollingSliceSize(count($known)))] as $country) {
            foreach (array_keys(self::PLATFORM_AGENTS) as $platform) {
                $cells[] = [$country, $platform];
            }
        }

        return $cells;
    }

    /**
     * Countries with wall opens in the last 30 days, busiest first. The wall
     * identity ledger, not clicks: a user who opens the wall and finds
     * nothing never clicks, and that is exactly the country to probe.
     *
     * @return list<string>
     */
    private function trafficCountries(): array
    {
        return DB::table('identity_sessions')
            ->where('kind', IdentityKind::Wall->value)
            ->whereNotNull('country')
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('country')
            ->orderByRaw('COUNT(*) DESC')
            ->orderBy('country')
            ->limit(self::HOT_COUNTRIES)
            ->pluck('country')
            ->map(fn (mixed $country): string => strtoupper((string) $country))
            ->all();
    }

    private function rollingSliceSize(int $total): int
    {
        $runsPerDay = max(1, intdiv(24 * 60, max(1, (int) $this->network->sync_interval_minutes)));

        return max(self::ROLLING_MIN, min(self::ROLLING_MAX, (int) ceil($total / $runsPerDay)));
    }

    /**
     * What a cell stores per offer: everything the DTO needs except the
     * countries, which come from the cells, and with amounts still in coins so
     * a corrected rate applies to stored cells on the next run.
     *
     * @return array<string, array<string, mixed>>
     */
    private function payloads(Response $response): array
    {
        $payloads = [];

        foreach ($this->rows($response) as $row) {
            $tasks = array_values(array_filter(is_array($row['cpe_instructions'] ?? null) ? $row['cpe_instructions'] : [], 'is_array'));
            $devices = $this->parseDevices($this->platforms($row));

            $payloads[(string) $row['id']] = [
                'name' => (string) ($this->nullableString($row['name'] ?? null) ?? 'Untitled offer'),
                'tracking_url' => (string) ($this->nullableString($row['tracking_link'] ?? null) ?? ''),
                // Completing every task is what a task offer is worth to us,
                // the same convention every multi-step driver here uses.
                'payout_coins' => $tasks === [] ? (float) ($row['payout_base'] ?? 0) : $this->sumPayouts($tasks, 'payout_base'),
                'description' => $this->nullableString($row['description'] ?? null)
                    ?? $this->nullableString($row['introduction'] ?? null),
                'instructions' => $this->instructions($row),
                'icon_url' => $this->nullableString($row['icon'] ?? null)
                    ?? $this->nullableString($row['icon_large'] ?? null),
                'devices' => $devices,
                'os_min_version' => $this->osFloor($row, $devices),
                'categories' => $this->categoryNames($this->categories($row)),
                'conversion_type' => $this->conversionType($row['conversion_type'] ?? null),
                'is_multi_reward' => count($tasks) > 1,
                'goals' => collect($tasks)
                    ->map(fn (array $task): array => [
                        'name' => (string) ($this->nullableString($task['name'] ?? null) ?? ''),
                        'coins' => (float) ($task['payout_base'] ?? 0),
                    ])
                    ->filter(fn (array $goal): bool => $goal['name'] !== '')
                    ->values()
                    ->all(),
            ];
        }

        return $payloads;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $countries
     */
    private function toDto(string $externalId, array $payload, array $countries, float $rate): OfferDTO
    {
        $goals = is_array($payload['goals'] ?? null) ? $payload['goals'] : [];

        return new OfferDTO(
            externalId: $externalId,
            name: (string) ($payload['name'] ?? 'Untitled offer'),
            trackingUrl: (string) ($payload['tracking_url'] ?? ''),
            payout: round((float) ($payload['payout_coins'] ?? 0) / $rate, 6),
            description: $this->nullableString($payload['description'] ?? null),
            instructions: $this->nullableString($payload['instructions'] ?? null),
            iconUrl: $this->nullableString($payload['icon_url'] ?? null),
            countries: $countries,
            devices: array_values(array_filter(is_array($payload['devices'] ?? null) ? $payload['devices'] : [], 'is_string')),
            osMinVersion: $this->nullableString($payload['os_min_version'] ?? null),
            categories: array_values(array_filter(is_array($payload['categories'] ?? null) ? $payload['categories'] : [], 'is_string')),
            conversionType: (string) ($payload['conversion_type'] ?? 'cpa'),
            isMultiReward: (bool) ($payload['is_multi_reward'] ?? false),
            goals: collect($goals)
                ->filter(fn (mixed $goal): bool => is_array($goal))
                ->map(fn (array $goal): array => [
                    'name' => (string) ($goal['name'] ?? ''),
                    'payout' => round((float) ($goal['coins'] ?? 0) / $rate, 6),
                ])
                ->values()
                ->all(),
        );
    }

    /**
     * `os_min_version` is one number compared against whatever OS the user
     * runs, so it can only be set for an offer that runs on one OS.
     *
     * @param  array<string, mixed>  $row
     * @param  list<string>  $devices
     */
    private function osFloor(array $row, array $devices): ?string
    {
        return match ($devices) {
            ['android'] => $this->nullableString($row['min_android_version'] ?? null),
            ['ios'] => $this->nullableString($row['min_ios_version'] ?? null),
            default => null,
        };
    }

    /**
     * A callback carrying both amounts implies a rate. A different rate from
     * the configured one means offers are being priced wrong on the wall, so
     * it is logged for the admin; the conversion itself is not refused over it.
     */
    private function checkRate(Request $request, float $payoutUsd, string $transactionId): void
    {
        $currency = $request->input('currency_amount');
        $rate = $this->credential('coins_per_usd');

        if (! is_numeric($currency) || (float) $currency == 0.0 || $payoutUsd == 0.0 || ! is_numeric($rate) || (float) $rate <= 0) {
            return;
        }

        $implied = abs((float) $currency) / abs($payoutUsd);

        if (abs($implied - (float) $rate) / (float) $rate > self::RATE_TOLERANCE) {
            Log::warning('ayeT callback implies a different currency rate than coins_per_usd', [
                'network' => $this->network->id,
                'transaction_id' => $transactionId,
                'implied_rate' => round($implied, 4),
                'coins_per_usd' => (float) $rate,
            ]);
        }
    }

    private function coinsPerUsd(): float
    {
        $rate = $this->credential('coins_per_usd');

        if (! is_numeric($rate) || (float) $rate <= 0) {
            throw new RuntimeException('ayeT Studios: coins_per_usd is not configured. Set the adslot currency rate (1000 means $1 = 1000 coins).');
        }

        return (float) $rate;
    }

    /**
     * Stable, and not reversible without the app key: ayeT sees the same
     * person on every visit and never the publisher's own user id.
     */
    private function userIdentifier(int $appId, string $endUserId): string
    {
        return $this->identifier("user:{$appId}:{$endUserId}");
    }

    /** Probes use the same shape as real users, so a probe reads as one. */
    private function identifier(string $seed): string
    {
        return substr(hash_hmac('sha256', $seed, (string) config('app.key')), 0, 32);
    }

    /**
     * No retry. `http()` retries three times, which is exactly the wrong
     * answer to a 429 and a needless one to a probe whose cell simply keeps
     * its last answer.
     */
    private function request(int $timeout): PendingRequest
    {
        return Http::timeout($timeout)
            ->connectTimeout(min($timeout, 5))
            ->withoutRedirecting()
            ->acceptJson();
    }

    private function endpoint(): string
    {
        return self::ENDPOINT.rawurlencode((string) $this->credential('adslot_id'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(Response $response): array
    {
        $rows = $response->json('offers');

        return array_values(array_filter(
            is_array($rows) ? $rows : [],
            fn (mixed $row): bool => is_array($row) && isset($row['id']) && is_scalar($row['id']),
        ));
    }

    private function succeeded(Response $response): bool
    {
        return $response->successful() && $response->json('status') === 'success';
    }

    private function throttled(Response $response): bool
    {
        return $response->status() === 429
            || str_contains(strtolower((string) $this->nullableString($response->json('error'))), 'throttled');
    }

    private function configurationError(Response $response): bool
    {
        return in_array($response->status(), [401, 403], true)
            || (int) $response->json('errorCode') === 101;
    }

    private function coolingDown(): bool
    {
        return Cache::has($this->cooldownKey());
    }

    private function startCooldown(): void
    {
        Cache::put($this->cooldownKey(), true, now()->addSeconds(self::THROTTLE_COOLDOWN_SECONDS));
    }

    private function cooldownKey(): string
    {
        return 'ayet:throttled:'.(int) $this->network->id;
    }

    /**
     * The sentence ayeT put in the envelope, for the operator. `status` is
     * always the word "error" and is the last resort. 200-shaped failures
     * carry `errorMessage` and `errorCode`; 4xx ones carry `error`.
     */
    private function statusText(Response $response): string
    {
        $message = $this->nullableString($response->json('errorMessage'))
            ?? $this->nullableString($response->json('error'))
            ?? $this->nullableString($response->json('message'));

        $code = $response->json('errorCode');

        if ($message === null) {
            return $this->nullableString($response->json('status')) ?? 'no status given.';
        }

        return is_scalar($code) ? "[{$code}] {$message}" : $message;
    }

    /**
     * A `custom_1` already on the link is left and ours appended after it; on
     * any server that reads the last of a repeated key, PHP's included, ours
     * wins.
     */
    private function withClickId(string $link, string $clickId): string
    {
        return $link.(str_contains($link, '?') ? '&' : '?').self::CLICK_PARAM.'='.rawurlencode($clickId);
    }

    /**
     * What the user has to do, then the pitch that sets it up.
     *
     * @param  array<string, mixed>  $row
     */
    private function instructions(array $row): ?string
    {
        $lines = array_filter([
            $this->nullableString($row['conversion_instructions_short'] ?? null),
            $this->nullableString($row['introduction'] ?? null),
        ]);

        return $lines === [] ? null : implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function platforms(array $row): array
    {
        $platforms = is_array($row['platforms'] ?? null)
            ? $row['platforms']
            : [$row['platform'] ?? null];

        return collect($platforms)
            ->map(fn (mixed $platform): ?string => $this->nullableString($platform))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * The tags are the categories a user recognises. Offers without any fall
     * back to how the offer converts and the shelf ayeT files it under.
     *
     * @param  array<string, mixed>  $row
     * @return list<mixed>
     */
    private function categories(array $row): array
    {
        $tagged = data_get($row, 'tags.categories');

        if (is_array($tagged) && $tagged !== []) {
            return array_values($tagged);
        }

        return array_values(array_filter([
            $row['conversion_type'] ?? null,
            $row['category'] ?? null,
        ]));
    }
}
