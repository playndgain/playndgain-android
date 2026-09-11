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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * Affise as a platform — one `networks` row per partner tenant.
 *
 * The shape that makes this driver different from every direct one is
 * `payments[]`. An Affise offer does not have *a* payout; it has a matrix of
 * them, one entry per (countries, devices, os, goal), and a catalogue where
 * every offer lists ten countries at ten prices is ordinary rather than
 * exotic. `ExpandsPayoutVariants` turns that into one `offers` row per clean
 * variant; everything the wall cannot filter on is skipped with a reason.
 *
 * Three operational facts drive the rest:
 *
 *   - **Every page is fetched and validated before the first offer is
 *     yielded.** A generator that yields as it pages would have upserted two
 *     pages before a failure on the third, leaving a half-applied catalogue
 *     whose missing offers look like inventory the partner dropped. Fetching
 *     eagerly means a failure anywhere is a failure everywhere, the sync job's
 *     catch runs, and nothing is deactivated.
 *   - **Their documented request timeout is 30–60 seconds and they recommend
 *     retrying.** The timeout here is deliberately well past that, and the
 *     retry is slow rather than tight: a partner under load answers a second
 *     attempt 200ms later exactly as badly.
 *   - **`revenue` is the affiliate's payout**, not the advertiser's price —
 *     this is the partner endpoint, and what it returns is what we are paid.
 *
 * Docs: docs/networks/affise.md.
 */
class AffiseDriver extends AbstractDriver
{
    use ExpandsPayoutVariants;
    use NormalizesFields;

    /** Hosts an Affise tenant can legitimately live on. */
    public const ALLOWED_HOSTS = ['.affise.com'];

    /** Their documented maximum. Fewer pages is fewer chances to fail mid-catalogue. */
    private const PAGE_SIZE = 500;

    /**
     * A stop rather than a loop. `next_page` that never goes null — a tenant
     * bug, or a filter we did not send — would otherwise page until the job
     * times out and report as a catalogue failure rather than as what it is.
     */
    private const MAX_PAGES = 200;

    /** Server-side failures a second attempt can plausibly fix. */
    private const RETRYABLE = [429, 500, 502, 503, 504];

    /** The slot Affise echoes back as `{sub1}`. */
    private const SUBID_PARAM = 'sub1';

    private const SKIP_NON_USD = 'non-USD payout';

    private const SKIP_PERCENT = 'percentage payout';

    private const SKIP_EXCLUDE = 'country exclusion list';

    private const SKIP_TARGETING = 'targeting dimension the wall cannot filter on';

    private const SKIP_NO_LINK = 'missing tracking link or id';

    private const SKIP_STOPPED = 'offer stopped';

    public static function key(): string
    {
        return 'affise';
    }

    public static function connector(): Connector
    {
        return new Connector(
            'Affise',
            ConnectorCategory::Platform,
            supportsMultipleAccounts: true,
            // Affise conversion status 3 is `declined`, and a decline after a
            // confirmation is the advertiser taking the money back.
            rejectedAfterCreditReverses: true,
            website: 'https://affise.com',
        );
    }

    /**
     * @return array<string, array{label: string, required?: bool, secret?: bool}>
     */
    public static function credentialSchema(): array
    {
        return [
            'api_base_url' => [
                'label' => 'API base URL',
                'required' => true,
                'secret' => false,
                'rule' => fn (?string $value): bool => TenantUrl::passes($value, self::ALLOWED_HOSTS),
                'rule_message' => 'The API base URL must be an https address on affise.com.',
                'placeholder' => 'https://api-tenant.affise.com',
            ],
            'api_key' => ['label' => 'API key', 'required' => true, 'secret' => true],
            'postback_secret' => ['label' => 'Postback secret', 'required' => true, 'secret' => true],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function postbackQuery(): array
    {
        // No click-id macro exists on this platform — `{sub1}` is the slot we
        // put our click id into and the slot they echo back.
        return [
            'click_id' => '{sub1}',
            'txid' => '{transactionid}',
            'payout' => '{sum}',
            'currency' => '{currency}',
            'status' => '{status}',
            'offer_id' => '{offerid}',
            'offer_name' => '{offer_name}',
            'goal' => '{goal}',
        ];
    }

    public function postbackUrl(): string
    {
        $url = static::postbackPath((string) $this->network->postback_key);
        $secret = (string) $this->credential('postback_secret');

        return $secret === '' ? $url : $url.'&secret='.rawurlencode($secret);
    }

    public function testConnection(): ConnectionResult
    {
        $response = $this->request(1);

        if ($response->failed()) {
            return ConnectionResult::fail("Affise returned HTTP {$response->status()}: ".$this->rejection($response));
        }

        $offers = $response->json('offers');

        if ((int) $response->json('status') !== 1 || ! is_array($offers)) {
            return ConnectionResult::fail('Affise rejected the request: '.$this->rejection($response));
        }

        $total = $response->json('pagination.total_count');

        return ConnectionResult::ok('Connected.', is_numeric($total) ? (int) $total : count($offers));
    }

    /**
     * @return iterable<OfferDTO>
     */
    public function fetchOffers(): iterable
    {
        // Every page first. See the class docblock: a partial catalogue is
        // indistinguishable from a shrunken one, and the sync job acts on that
        // difference.
        foreach ($this->allOffers() as $row) {
            yield from $this->offersFor($row);
        }
    }

    public function parsePostback(Request $request): ?PostbackDTO
    {
        $clickId = $this->nullableString($request->input('click_id'));
        $payout = $request->input('payout');

        if ($clickId === null || ! is_numeric($payout)) {
            return null;
        }

        // A non-USD conversion is refused rather than converted. There is no
        // exchange rate anywhere in this application, and crediting at a guessed
        // one pays the publisher a number nobody can reconcile. 422 and a log
        // row is the honest answer.
        $currency = strtoupper((string) ($this->nullableString($request->input('currency')) ?? 'USD'));

        if ($currency !== 'USD') {
            return null;
        }

        $status = trim((string) $request->input('status'));
        $amount = abs((float) $payout);

        // The driver reports what Affise said; the processor decides what it
        // costs. Status 3 `declined` is a rejection — on a pending row that is
        // simply a refusal, and on a credited one the matrix turns it into a
        // chargeback because this connector declares
        // `rejectedAfterCreditReverses`. Only an explicitly negative `{sum}` is
        // a reversal here, because that is Affise quoting the amount it is
        // taking back rather than naming a decision.
        $reversed = (float) $payout < 0;

        return new PostbackDTO(
            networkTxId: $this->transactionId($request, $clickId),
            clickId: $clickId,
            payout: $reversed ? -$amount : $amount,
            status: match (true) {
                $reversed => 'reversed',
                $status === '3' => 'rejected',
                // 1 confirmed. 2 pending and 5 hold are both "not decided yet";
                // anything unrecognised joins them rather than being paid.
                $status === '1' => 'credited',
                default => 'pending',
            },
            offerExternalId: $this->nullableString($request->input('offer_id')),
            offerName: $this->nullableString($request->input('offer_name')),
            ip: $this->nullableString($request->input('ip')),
            raw: Redactor::keys($request->all()),
        );
    }

    public function verifyPostback(Request $request): bool
    {
        return PostbackSecret::matches(
            (string) $this->credential('postback_secret'),
            $this->nullableString($request->input('secret')),
        );
    }

    public function buildTrackingUrl(Offer $offer, string $clickId): string
    {
        return str_replace('{click_id}', $clickId, $this->withSubIdMacro($offer->tracking_url));
    }

    /**
     * `{transactionid}` where the tenant sends one, a composite where it does
     * not.
     *
     * The key has to be the same on the credit and on the decline that reverses
     * it, because `reverse()` matches on `(network_id, network_txid)`. Some
     * tenants leave the macro blank; the three values below are the ones that
     * are stable across both fires for a given conversion.
     */
    private function transactionId(Request $request, string $clickId): string
    {
        $txId = $this->nullableString($request->input('txid'));

        if ($txId !== null) {
            return $txId;
        }

        return implode(':', [
            $clickId,
            $this->nullableString($request->input('offer_id')) ?? '',
            $this->nullableString($request->input('goal')) ?? 'default',
        ]);
    }

    /**
     * Every page, validated, before a single offer is handed on.
     *
     * @return list<array<string, mixed>>
     */
    private function allOffers(): array
    {
        $rows = [];
        $page = 1;

        while ($page <= self::MAX_PAGES) {
            $response = $this->request($page);

            $offers = $response->json('offers');

            if ($response->failed() || (int) $response->json('status') !== 1 || ! is_array($offers)) {
                throw new RuntimeException(
                    "Affise rejected the offers request on page {$page}: ".$this->rejection($response)
                );
            }

            foreach ($offers as $offer) {
                if (is_array($offer)) {
                    $rows[] = $offer;
                }
            }

            $next = $response->json('pagination.next_page');

            if (! is_numeric($next) || (int) $next <= $page) {
                return $rows;
            }

            $page = (int) $next;
        }

        throw new RuntimeException('Affise paged past '.self::MAX_PAGES.' pages without ending.');
    }

    private function request(int $page): Response
    {
        return $this->http()
            // Their documented server-side timeout is 30–60s, so a 30s client
            // timeout would abandon requests the tenant was about to answer.
            ->timeout(120)
            // Slow retries, and only for the failures a retry can fix. A 401 is
            // a wrong key and answering it three times more is three more
            // wrong keys; the backoff is seconds because a tenant under load
            // answers a request 200ms later exactly as badly.
            ->retry(3, 2000, fn (Throwable $e): bool => $e instanceof ConnectionException
                || ($e instanceof RequestException && in_array($e->response->status(), self::RETRYABLE, true)), throw: false)
            ->withHeaders(['API-Key' => (string) $this->credential('api_key')])
            ->get($this->endpoint().'/3.0/partner/offers', [
                'page' => $page,
                'limit' => self::PAGE_SIZE,
            ]);
    }

    private function endpoint(): string
    {
        return rtrim(TenantUrl::validate($this->credential('api_base_url'), self::ALLOWED_HOSTS), '/');
    }

    /**
     * One offer, however many rows its payments matrix comes to.
     *
     * @param  array<string, mixed>  $row
     * @return list<OfferDTO>
     */
    private function offersFor(array $row): array
    {
        $id = $this->nullableString($row['offer_id'] ?? null) ?? $this->nullableString($row['id'] ?? null);
        $link = $this->nullableString($row['link'] ?? null) ?? $this->nullableString(data_get($row, 'links.0.url'));

        if ($id === null || $link === null) {
            $this->skips()->skip($id ?? '(no id)', self::SKIP_NO_LINK);

            return [];
        }

        if (filled($row['stop_at'] ?? null)) {
            $this->skips()->skip($id, self::SKIP_STOPPED);

            return [];
        }

        if (! $this->targetingIsExpressible($row)) {
            $this->skips()->skip($id, self::SKIP_TARGETING);

            return [];
        }

        $variants = $this->variantsFrom($id, is_array($row['payments'] ?? null) ? $row['payments'] : []);

        if ($variants === null) {
            $this->skips()->skip($id, self::SKIP_CONFLICTING);

            return [];
        }

        return $this->expandVariants(
            $id,
            $variants,
            fn (array $variant, string $externalId): OfferDTO => $this->offer($row, $link, $variant, $externalId),
        );
    }

    /**
     * `payments[]` grouped into one variant per audience.
     *
     * Two entries sharing a (countries, devices) key mean one of two very
     * different things, and `goal` is what tells them apart. **Different
     * goals** are the steps of a multi-step offer: they become `goals[]` and the
     * tile is their sum, the same shape Ayet takes. **The same goal twice** is
     * two prices for one thing, which cannot both be true — that is returned as
     * a conflict and the whole offer is skipped rather than summed into a number
     * the advertiser never agreed to.
     *
     * @param  list<mixed>  $payments
     * @return list<array<string, mixed>>|null Null when the offer prices one goal twice.
     */
    private function variantsFrom(string $id, array $payments): ?array
    {
        $groups = [];
        $revenueByGoal = [];

        foreach ($payments as $payment) {
            if (! is_array($payment)) {
                continue;
            }

            if (strtolower((string) ($payment['type'] ?? 'fixed')) !== 'fixed') {
                // A revenue share has no fixed number to put on a tile.
                $this->skips()->skip($id, self::SKIP_PERCENT);

                continue;
            }

            if (strtoupper((string) ($payment['currency'] ?? 'usd')) !== 'USD') {
                $this->skips()->skip($id, self::SKIP_NON_USD);

                continue;
            }

            if (($payment['country_exclude'] ?? false) === true) {
                // "Everywhere except these" is a list we cannot build, the same
                // problem as a worldwide base with country variants.
                $this->skips()->skip($id, self::SKIP_EXCLUDE);

                continue;
            }

            if (filled($payment['cities'] ?? null)) {
                $this->skips()->skip($id, self::SKIP_TARGETING);

                continue;
            }

            $revenue = (float) ($payment['revenue'] ?? 0);

            if ($revenue <= 0) {
                continue;
            }

            $countries = $this->parseCountries(is_array($payment['countries'] ?? null) ? $payment['countries'] : []);
            $devices = $this->deviceList($payment);
            sort($countries);
            sort($devices);

            $key = implode(',', $countries).'|'.implode(',', $devices);
            $goalId = $this->nullableString($payment['goal'] ?? null) ?? 'default';
            $goalKey = $key.'#'.$goalId;

            if (array_key_exists($goalKey, $revenueByGoal)) {
                // The same goal, the same audience, a second price. Summing
                // them would quote a number nobody agreed to and picking one
                // would be a guess about money.
                if ($revenueByGoal[$goalKey] !== $revenue) {
                    return null;
                }

                continue;
            }

            $revenueByGoal[$goalKey] = $revenue;

            $groups[$key] ??= ['countries' => $countries, 'devices' => $devices, 'payout' => 0.0, 'goals' => []];
            $groups[$key]['payout'] += $revenue;

            $label = $this->nullableString($payment['title'] ?? null);

            if ($label !== null) {
                $groups[$key]['goals'][] = ['name' => $label, 'payout' => $revenue];
            }
        }

        return array_values(array_map(function (array $group): array {
            // A single step is not a multi-reward offer; the goal name would
            // just repeat the tile.
            if (count($group['goals']) < 2) {
                $group['goals'] = [];
            }

            return $group;
        }, $groups));
    }

    /**
     * `os[]` and `devices[]` both describe the same thing in different
     * vocabularies depending on how the offer was set up.
     *
     * @param  array<string, mixed>  $payment
     * @return list<string>
     */
    private function deviceList(array $payment): array
    {
        $tokens = array_merge(
            is_array($payment['os'] ?? null) ? $payment['os'] : [],
            is_array($payment['devices'] ?? null) ? $payment['devices'] : [],
        );

        return $this->parseDevices(array_values(array_filter(
            array_map(fn (mixed $token): string => is_scalar($token) ? (string) $token : '', $tokens),
        )));
    }

    /**
     * `targeting[]` is a separate, richer matrix than `payments[]`, and most of
     * it is dimensions `offers` has no column for. An offer restricted by ISP,
     * browser, IP range, region or city is one the wall would show to users the
     * advertiser will not pay for.
     *
     * @param  array<string, mixed>  $row
     */
    private function targetingIsExpressible(array $row): bool
    {
        foreach (is_array($row['targeting'] ?? null) ? $row['targeting'] : [] as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            foreach (['region.allow', 'region.deny', 'city.allow', 'city.deny', 'isp.allow', 'isp.deny',
                'browser.allow', 'browser.deny', 'ip.allow', 'ip.deny', 'country.deny', 'os.deny'] as $path) {
                if (filled(data_get($rule, $path))) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $variant
     */
    private function offer(array $row, string $link, array $variant, string $externalId): OfferDTO
    {
        /** @var list<array{name: string, payout: float}> $goals */
        $goals = $variant['goals'] ?? [];

        return new OfferDTO(
            externalId: $externalId,
            name: (string) ($row['title'] ?? 'Untitled offer'),
            trackingUrl: $this->withSubIdMacro($link),
            payout: (float) $variant['payout'],
            description: $this->plain(data_get($row, 'description_lang.en')),
            instructions: $this->plain(data_get($row, 'kpi.en')),
            iconUrl: $this->nullableString($row['logo'] ?? null) ?? $this->nullableString($row['logo_source'] ?? null),
            previewUrl: $this->nullableString($row['preview_url'] ?? null),
            countries: $variant['countries'],
            devices: $variant['devices'],
            categories: $this->categoryNames($row['full_categories'] ?? $row['categories'] ?? null),
            conversionType: ($row['is_cpi'] ?? false) === true ? 'cpi' : 'cpa',
            isMultiReward: count($goals) > 1,
            goals: $goals,
            dailyCap: $this->dailyCap($row),
        );
    }

    /**
     * The daily conversion cap, where one is set for every country and every
     * goal. A cap scoped to one country is not this offer's cap.
     *
     * @param  array<string, mixed>  $row
     */
    private function dailyCap(array $row): ?int
    {
        foreach (is_array($row['caps'] ?? null) ? $row['caps'] : [] as $cap) {
            if (! is_array($cap)) {
                continue;
            }

            $scoped = ($cap['period'] ?? null) === 'day'
                && ($cap['type'] ?? null) === 'conversions'
                && ($cap['goal_type'] ?? 'all') === 'all'
                && ($cap['country_type'] ?? 'all') === 'all';

            if ($scoped && is_numeric($cap['value'] ?? null)) {
                return (int) $cap['value'];
            }
        }

        return null;
    }

    /** Idempotent: a link that already carries the macro is left alone. */
    private function withSubIdMacro(string $link): string
    {
        if ($link === '' || str_contains($link, '{click_id}')) {
            return $link;
        }

        return $link.(str_contains($link, '?') ? '&' : '?').self::SUBID_PARAM.'={click_id}';
    }

    private function rejection(Response $response): string
    {
        $fromJson = $this->nullableString($response->json('message'))
            ?? $this->nullableString($response->json('error'))
            ?? $this->nullableString($response->json('errors.0'));

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
