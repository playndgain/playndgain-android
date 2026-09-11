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
 * One POST endpoint, a method name in the body, and a token pair for auth.
 *
 * The catalogue half is built from HangMyAds' own developer docs
 * (api.hangmytracking.com/docs/developers.html) and a recorded `getOffers`
 * response. Three things about this network are unlike the others here:
 *
 *   - **We** have to be allowlisted before the API answers at all: "IP
 *     addresses making the requests must be submitted to the white-list for API
 *     access." A worker that syncs from a new egress address gets rejected, and
 *     the rejection arrives as HTTP 200 with `status` set to `NOK`.
 *   - Money is in cents everywhere — `payout_cents`, `pay_cents`,
 *     `daily_cents_cap`. `OfferDTO` is USD, so everything is divided here and
 *     nowhere else.
 *   - Offers are per-affiliate gated. An offer we have not been approved for
 *     comes back with `tracking_link: null`, which is a tile nobody can click.
 *
 * Postback macros come from Hang My Tracking's "Optional Variables" list
 * (publisher dashboard, 2026). `{subid}` is our click. `{convid}` is their
 * conversion id. `{pay}` is USD (affiliate conversion codes only); `{payout}`
 * is cents. There is still no signature — the source-IP allowlist is the gate.
 */
class HangMyAdsDriver extends AbstractDriver
{
    use NormalizesFields;

    /** Docs say http; the token travels in the body, so https it is. */
    private const ENDPOINT = 'https://api.hangmytracking.com/api.php';

    /** `{subid}` is their click/custom id slot — docs, tracking link macros. */
    private const SUBID_PARAM = 'subid';

    /**
     * Their published page size example is 25. Bigger pages mean fewer round
     * trips against a 20-calls-per-10-seconds limit, and the loop below stops
     * on an empty page rather than on a short one, so a server-side cap lower
     * than this costs extra requests instead of a truncated catalogue.
     */
    private const PAGE_SIZE = 250;

    /**
     * A catalogue link arrives as a template with the affiliate's own values
     * already in it and placeholder words where the click's values go. The
     * three we cannot fill are blanked rather than left as-is: sending
     * `google_aid=REPLACE_WITH_GAID` to a tracker that scores device ids is a
     * click asking to be flagged as fraud.
     */
    private const LINK_PLACEHOLDERS = [
        'YOUR_CLICK_ID' => '{click_id}',
        'YOUR_SUB_SOURCE' => '',
        'REPLACE_WITH_GAID' => '',
        'REPLACE_WITH_IDFA' => '',
    ];

    public static function key(): string
    {
        return 'hangmyads';
    }

    public static function connector(): Connector
    {
        return new Connector('HangMyAds', ConnectorCategory::Direct, website: 'https://hangmyads.com');
    }

    /**
     * @return array<string, string>
     */
    public static function postbackQuery(): array
    {
        return [
            'subid' => '{subid}',
            'offerid' => '{offerid}',
            'pay' => '{pay}',
            'goalname' => '{goalname}',
            'convid' => '{convid}',
            'offer_name' => '{offer_name}',
        ];
    }

    /**
     * @return array<string, array{label: string, required?: bool, secret?: bool}>
     */
    public static function credentialSchema(): array
    {
        return [
            'api_id' => ['label' => 'Network ID (apiID)', 'required' => true],
            'api_token' => ['label' => 'API Token', 'required' => true, 'secret' => true],
        ];
    }

    public function testConnection(): ConnectionResult
    {
        // One page of one row: the cheapest request that still proves the token
        // pair works and our egress address is on their allowlist.
        $response = $this->call(1, 1);

        if ($response->failed()) {
            return ConnectionResult::fail("HangMyAds returned HTTP {$response->status()}.");
        }

        // A bad token and a caller that is not allowlisted both arrive as
        // HTTP 200 with `status: NOK`. Reporting that as "connected, 0 offers"
        // is the worst possible answer: the admin pastes a typo, the button
        // goes green, and the network is switched on to sync nothing.
        if (! $this->succeeded($response)) {
            return ConnectionResult::fail('HangMyAds rejected the request: '.$this->errorText($response));
        }

        // `total_records` is the whole catalogue, not the page — which is the
        // number the admin actually wants to see next to the button.
        $total = $response->json('data.total_records');

        return ConnectionResult::ok('Connected.', is_numeric($total) ? (int) $total : 0);
    }

    /**
     * @return iterable<OfferDTO>
     */
    public function fetchOffers(): iterable
    {
        $page = 1;

        do {
            $rows = $this->page($page);

            foreach ($rows as $row) {
                $offer = $this->offer($row);

                if ($offer !== null) {
                    yield $offer;
                }
            }

            $page++;
        } while ($rows !== []);
    }

    public function parsePostback(Request $request): ?PostbackDTO
    {
        $clickId = $this->nullableString($request->input(self::SUBID_PARAM))
            ?? $this->nullableString($request->input('click_id'))
            ?? $this->nullableString($request->input('affiliate_subid'));
        $amount = $this->payoutUsd($request);

        if ($clickId === null || $amount === null) {
            return null;
        }

        $offerId = $this->nullableString($request->input('offerid') ?? $request->input('oid') ?? $request->input('offer_id'));
        $goal = $this->nullableString($request->input('goalname') ?? $request->input('goal_name'));

        // ASSUMPTION: a reversal arrives as a negative amount rather than a
        // status flag. Reading it backwards would credit a chargeback.
        $reversed = $amount < 0;

        return new PostbackDTO(
            // `{convid}` is their unique conversion id. Without it a composite
            // carries the rest. Click plus offer is not enough on its own,
            // because an offer can price several goals and `allow_duplicates`
            // lets one click convert more than once. The goal name and the
            // amount separate those fires; the amount is unsigned so that a
            // reversal produces the key its own credit did, which is how
            // `reverse()` finds the conversion to charge back.
            //
            // ponytail: the ceiling is two identically-priced fires of the same
            // goal on one click — the second drops as a duplicate.
            networkTxId: $this->nullableString($request->input('convid') ?? $request->input('transaction_id') ?? $request->input('conversion_id'))
                ?? implode(':', [$clickId, $offerId, $goal, abs($amount)]),
            clickId: $clickId,
            payout: $reversed ? -abs($amount) : abs($amount),
            status: $reversed ? 'reversed' : 'credited',
            offerExternalId: $offerId,
            // The goal is the half of a multi-goal offer that actually fired, so
            // it names the conversion better than the offer does.
            offerName: $goal ?? $this->nullableString($request->input('offer_name')),
            ip: $this->nullableString($request->input('click_ip') ?? $request->input('ip')),
            raw: $request->all(),
        );
    }

    public function verifyPostback(Request $request): bool
    {
        // HangMyAds documents no signature and no shared postback token, so
        // there is nothing here to check that would not be theatre. The
        // source-IP allowlist the controller enforces first is the whole of
        // this network's authentication — and it rejects when empty, so a
        // HangMyAds row with no allowlist earns nothing rather than trusting
        // anyone. Their firing addresses are not in the docs; they have to be
        // asked for and pasted onto the network before it can be switched on.
        return true;
    }

    public function buildTrackingUrl(Offer $offer, string $clickId): string
    {
        // Through the same helper the catalogue went through, not a bare
        // str_replace. A stored link with no macro in it — hand-edited, or
        // synced before this driver normalized links — would otherwise be
        // handed to the user untouched, and every conversion off it comes back
        // with a blank subid that matches no click. Nobody is paid and nothing
        // logs an error.
        return str_replace('{click_id}', $clickId, $this->withSubIdMacro($offer->tracking_url));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function page(int $page): array
    {
        $response = $this->call($page, self::PAGE_SIZE)->throw();

        // Failing loudly rather than yielding nothing. A driver that returns an
        // empty catalogue tells the sync job the network has no offers left, and
        // the sweep deactivates every HangMyAds offer we have — the collapse
        // guard only catches that above twenty offers. Throwing puts the reason
        // in `last_sync_error` where someone will read it.
        if (! $this->succeeded($response)) {
            throw new RuntimeException('HangMyAds rejected the offers request: '.$this->errorText($response));
        }

        $rows = $response->json('data.offers');

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    private function call(int $page, int $limit): Response
    {
        // POST only, and form-encoded rather than JSON: the other end is a
        // single PHP script reading request parameters.
        return $this->http()->timeout(180)->asForm()->post(self::ENDPOINT, [
            'method' => 'getOffers',
            'apiID' => (string) $this->credential('api_id'),
            'apiToken' => (string) $this->credential('api_token'),
            'page' => $page,
            'limit' => $limit,
            // EPC costs them a query per offer and buys us nothing: we rank on
            // our own click and conversion history, not on theirs.
            'epc' => 0,
        ]);
    }

    /**
     * An offer we cannot send a click to, cannot price in dollars, or that the
     * network has already stopped running is not a tile on a wall. Skipped
     * rather than stored — and because the sync sweep deactivates whatever it
     * does not see, an offer that goes paused or loses its approval goes
     * inactive on its own.
     *
     * @param  array<string, mixed>  $row
     */
    private function offer(array $row): ?OfferDTO
    {
        $link = $this->nullableString($row['tracking_link'] ?? null);
        $currency = strtoupper((string) ($this->nullableString($row['pay_curr'] ?? null) ?? 'USD'));

        if (! isset($row['id']) || $link === null || $currency !== 'USD') {
            return null;
        }

        if (strtolower((string) ($row['status'] ?? 'active')) !== 'active') {
            return null;
        }

        $goals = $this->goals($row['goals'] ?? null);

        return new OfferDTO(
            externalId: (string) $row['id'],
            name: (string) ($row['name'] ?? 'Untitled offer'),
            trackingUrl: $this->withSubIdMacro($link),
            // Cents, network-wide. `payout_cents` is the offer's own price and
            // the goals below itemize it, so it stays the authority rather than
            // being recomputed from them.
            payout: (float) ($row['payout_cents'] ?? 0) / 100,
            description: $this->nullableString($row['description'] ?? null),
            // `conditions` is the "requires iOS 15 or later" line — what the
            // user has to satisfy, which is what instructions are for.
            instructions: $this->nullableString($row['conditions'] ?? null),
            iconUrl: $this->nullableString($row['thumbnail'] ?? null),
            previewUrl: $this->nullableString($row['preview_URL'] ?? null),
            countries: $this->parseCountries($row['countries'] ?? []),
            // `os` names the platform and `devices` the form factor, and either
            // can be the only one that says anything — `devices: "All"` maps to
            // nothing, which is our vocabulary for "every device".
            devices: $this->parseDevices([
                (string) ($this->nullableString($row['os'] ?? null) ?? ''),
                (string) ($this->nullableString($row['devices'] ?? null) ?? ''),
            ]),
            osMinVersion: $this->osMinVersion($row['minOSVersion'] ?? null),
            // `categories` is the same list as a comma-joined string; the object
            // form is already split and needs no guessing about the separator.
            categories: $this->categoryNames($row['categories_obj'] ?? null),
            conversionType: $this->conversionType($row['pay_type'] ?? null),
            isMultiReward: count($goals) > 1,
            goals: $goals,
            dailyCap: $this->cap($row['daily_cap'] ?? null),
        );
    }

    /**
     * ASSUMPTION: goals arrive keyed by goal name, each holding `pay_cents` —
     * that is the shape the docs describe and there is no recorded multi-goal
     * response to check it against. A list-shaped payload yields no steps here
     * rather than wrong ones.
     *
     * @return list<array{name: string, payout: float}>
     */
    private function goals(mixed $goals): array
    {
        if (! is_array($goals)) {
            return [];
        }

        $steps = [];

        foreach ($goals as $name => $goal) {
            // A step with no label is dropped, same as `goalList()` does it:
            // "Step 2 — 4.25" tells the user nothing the total does not.
            if (! is_array($goal) || ! is_string($name) || trim($name) === '') {
                continue;
            }

            $steps[] = [
                'name' => trim($name),
                'payout' => (float) ($goal['pay_cents'] ?? 0) / 100,
            ];
        }

        return $steps;
    }

    private function withSubIdMacro(string $link): string
    {
        $link = str_replace(
            array_keys(self::LINK_PLACEHOLDERS),
            array_values(self::LINK_PLACEHOLDERS),
            $link,
        );

        if (str_contains($link, '{click_id}')) {
            return $link;
        }

        return $link.(str_contains($link, '?') ? '&' : '?').self::SUBID_PARAM.'={click_id}';
    }

    /**
     * `{pay}` is USD on affiliate conversion codes. `{payout}` is cents —
     * treating that as dollars would credit a $15 fire as $1,500.
     */
    private function payoutUsd(Request $request): ?float
    {
        $pay = $request->input('pay');

        if (is_numeric($pay)) {
            return (float) $pay;
        }

        $payout = $request->input('payout');

        return is_numeric($payout) ? (float) $payout / 100 : null;
    }

    private function succeeded(Response $response): bool
    {
        return $response->json('status') === 'OK';
    }

    /** Whatever they put in the envelope instead of `OK`, for the operator. */
    private function errorText(Response $response): string
    {
        $messages = $response->json('errorMessages');

        if (is_array($messages) && $messages !== []) {
            return implode('; ', array_map('strval', $messages));
        }

        return $this->nullableString($response->json('status')) ?? 'no error message given.';
    }

    /** "None" is their way of saying the offer runs on any version. */
    private function osMinVersion(mixed $version): ?string
    {
        $version = $this->nullableString($version);

        return $version === null || strcasecmp($version, 'none') === 0 ? null : $version;
    }

    /** Caps arrive as a number or as the words "No Cap". */
    private function cap(mixed $cap): ?int
    {
        return is_numeric($cap) ? (int) $cap : null;
    }
}
