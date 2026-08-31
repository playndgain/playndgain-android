<?php

namespace App\Networks\Drivers;

use App\Models\Offer;
use App\Networks\AbstractDriver;
use App\Networks\Drivers\Concerns\NormalizesFields;
use App\Networks\DTO\ConnectionResult;
use App\Networks\DTO\OfferDTO;
use App\Networks\DTO\PostbackDTO;
use App\Support\Plain;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Everflow underneath — a header key, REST status codes, and paging that says
 * how much is left.
 *
 * Adtogame runs on Everflow's hosted affiliate API (`api.eflow.team`), so the
 * catalogue half is a documented contract rather than a recorded guess: one
 * `X-Eflow-Api-Key` header, `page`/`page_size` on the query, and an envelope
 * carrying `offers` beside `paging.total_count`. Three things about this
 * network are unlike the others here:
 *
 *   - It answers a bad key with a real HTTP status, so there is no success
 *     envelope to check. `failed()` and `->throw()` are the whole of it.
 *   - An offer's price is a *list*. `relationship.payouts.entries` holds the
 *     base payout and every event beside it, so what the offer is worth to us
 *     is their sum, and each named entry is a step on the wall.
 *   - Targeting carries its own direction. A ruleset entry can be an *exclude*
 *     rule, and reading one as an include list would aim the offer at exactly
 *     the countries it is blocked in — see `included()`.
 *
 * **The postback contract is ours to write, not theirs to guess.** Adtogame
 * takes a postback URL with their macros in it, so this is the URL to paste
 * into their dashboard rather than an assumption about what will arrive —
 * see `postbackQuery()`. AM slots: `sub2` is the click id, `sub1` is the
 * source. `{payout_usd}` is not an Everflow macro; partner money is
 * `{payout_amount}`.
 *
 * ASSUMPTION: a negative `{payout_amount}` is how a reversal arrives. Their
 * partner docs never say they re-fire on reject, so that reading and the
 * composite idempotency key in `parsePostback()` are the two things to
 * confirm against a live fire.
 */
class AdtogameDriver extends AbstractDriver
{
    use NormalizesFields;

    private const ENDPOINT = 'https://api.eflow.team/v1/affiliates/offersrunnable';

    /**
     * AM 2026-08-18: `sub2` is the click id, `sub1` is the source. Generic
     * Everflow copy says the opposite. Filling `sub1` with the UUID would
     * send our click id back as `{sub1}` and leave `{sub2}` as whatever the
     * catalogue already had — usually a source tag, which matches no click.
     */
    private const SUBID_PARAMS = ['sub2'];

    /**
     * Their documented default is 50. Bigger pages mean fewer round trips, and
     * the loop below is driven by `paging.total_count` rather than by page
     * length, so a server-side cap below this costs extra requests instead of a
     * truncated catalogue.
     */
    private const PAGE_SIZE = 100;

    /**
     * Backstop, not a limit — 100 pages is 10,000 offers at the size above.
     * It only fires if the endpoint ever both ignores `page` and stops
     * reporting a total, which is the one shape that would otherwise loop for
     * as long as the worker lives.
     */
    private const MAX_PAGES = 100;

    public static function key(): string
    {
        return 'adtogame';
    }

    /**
     * @return array<string, string>
     */
    public static function postbackQuery(): array
    {
        return [
            'click_id' => '{sub2}',
            'source_id' => '{sub1}',
            'user_id' => '{user_id}',
            'transaction_id' => '{transaction_id}',
            'conversion_id' => '{conversion_id}',
            'campaign_id' => '{offer_id}',
            'campaign_name' => '{offer_name}',
            'event_id' => '{event_id}',
            'event_name' => '{event_name}',
            'payout' => '{payout_amount}',
            'currency' => '{offer_currency}',
            'ip' => '{session_ip}',
        ];
    }

    /**
     * @return array<string, array{label: string, required?: bool, secret?: bool}>
     */
    public static function credentialSchema(): array
    {
        return [
            'api_key' => ['label' => 'API Key (X-Eflow-Api-Key)', 'required' => true, 'secret' => true],
        ];
    }

    public function testConnection(): ConnectionResult
    {
        // One page of one row: the cheapest request that still proves the key
        // works. Everflow rejects a bad key with a 401 rather than with a
        // success envelope carrying an error, so the status line is the answer.
        $response = $this->http()->withHeaders($this->authHeaders())->get(self::ENDPOINT, [
            'page' => 1,
            'page_size' => 1,
        ]);

        if ($response->failed()) {
            return ConnectionResult::fail("Adtogame returned HTTP {$response->status()}.");
        }

        // `total_count` is the whole catalogue, not the page — which is the
        // number the admin actually wants to see next to the button.
        $total = $response->json('paging.total_count');

        return ConnectionResult::ok('Connected.', is_numeric($total) ? (int) $total : 0);
    }

    /**
     * @return iterable<OfferDTO>
     */
    public function fetchOffers(): iterable
    {
        $page = 1;
        $seen = 0;

        do {
            $body = $this->http()
                ->timeout(180)
                ->withHeaders($this->authHeaders())
                ->get(self::ENDPOINT, ['page' => $page, 'page_size' => self::PAGE_SIZE])
                // Failing loudly rather than yielding nothing. A driver that
                // returns an empty catalogue tells the sync job the network has
                // no offers left, and the sweep deactivates every Adtogame offer
                // we have — the collapse guard only catches that above twenty
                // offers. Throwing puts the status in `last_sync_error` where
                // someone will read it.
                ->throw()
                ->json();

            $rows = array_values(array_filter(
                is_array(data_get($body, 'offers')) ? data_get($body, 'offers') : [],
                'is_array',
            ));

            foreach ($rows as $row) {
                $offer = $this->offer($row);

                if ($offer !== null) {
                    yield $offer;
                }
            }

            // Rows seen, not offers yielded: the skips below are ours, and
            // counting them out would ask for a page that does not exist.
            $seen += count($rows);
            $total = (int) (is_numeric(data_get($body, 'paging.total_count')) ? data_get($body, 'paging.total_count') : 0);
            $page++;

            // The total is what ends this, and it also ends it after one page if
            // the endpoint ever ignored `page` and answered with the whole
            // catalogue each time. An empty page ends it when no total is given.
        } while ($rows !== [] && ($total <= 0 || $seen < $total) && $page <= self::MAX_PAGES);
    }

    public function parsePostback(Request $request): ?PostbackDTO
    {
        $clickId = $this->resolved($request->input('click_id'))
            ?? $this->resolved($request->input('sub2'))
            ?? $this->resolved($request->input('user_id'))
            ?? $this->resolved($request->input('sub1'));
        $payout = $request->input('payout');

        if ($clickId === null || ! is_numeric($payout)) {
            return null;
        }

        $offerId = $this->resolved($request->input('campaign_id') ?? $request->input('offer_id'));
        $event = $this->resolved($request->input('event_name'));

        // ASSUMPTION: a reversal arrives as a negative payout rather than as a
        // status flag — their macro list carries no status at all. Reading it
        // backwards would credit a chargeback.
        $amount = (float) $payout;
        $reversed = $amount < 0;

        return new PostbackDTO(
            // No transaction id in the macros they publish, so the key is a
            // composite and it is worth being plain about what it can tell
            // apart. Click plus offer is not enough on its own: one offer prices
            // several events, each fires its own postback with the same click
            // and the same offer id, and every event after the first would be
            // dropped as a duplicate — money the publisher earned and never
            // sees. The event name and the amount separate them; the amount is
            // unsigned so that a reversal produces the key its own credit did,
            // which is how `reverse()` finds the conversion to charge back.
            //
            // ponytail: the ceiling is two identically-priced events on one
            // click arriving without an event name — the second drops as a
            // duplicate. Read a real id here the day their dashboard offers one.
            networkTxId: $this->resolved($request->input('transaction_id') ?? $request->input('conversion_id'))
                ?? implode(':', [$clickId, $offerId, $event, abs($amount)]),
            clickId: $clickId,
            payout: $reversed ? -abs($amount) : abs($amount),
            status: $reversed ? 'reversed' : 'credited',
            offerExternalId: $offerId,
            // The event is the half of a multi-event offer that actually fired,
            // so it names the conversion better than the offer does.
            offerName: $event ?? $this->resolved($request->input('campaign_name') ?? $request->input('offer_name')),
            // No `endUserId`: `user_id` is Adtogame echoing our subid back, not
            // an identifier of theirs. The end user is the click's, and the
            // processor already reads it from there.
            ip: $this->resolved($request->input('ip') ?? $request->input('user_ip')),
            raw: $request->all(),
        );
    }

    public function verifyPostback(Request $request): bool
    {
        // Adtogame signs nothing and offers no shared postback token, so there
        // is nothing here to check that would not be theatre. The source-IP
        // allowlist the controller enforces first is the whole of this network's
        // authentication — and it rejects when empty, so an Adtogame row with no
        // allowlist earns nothing rather than trusting anyone. The nine Everflow
        // S2S addresses are seeded on the row; confirm they match Debug Info
        // before the network takes traffic.
        return true;
    }

    public function buildTrackingUrl(Offer $offer, string $clickId): string
    {
        // Through the same helper the catalogue went through, not a bare
        // str_replace. A stored link with no macro in it — hand-edited, or
        // synced before this driver normalized links — would otherwise be handed
        // to the user untouched, and every conversion off it comes back with a
        // blank subid that matches no click. Nobody is paid and nothing logs an
        // error.
        return str_replace('{click_id}', $clickId, $this->withSubIdMacros($offer->tracking_url));
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(): array
    {
        return ['X-Eflow-Api-Key' => (string) $this->credential('api_key')];
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
        $link = $this->nullableString($row['tracking_url'] ?? null);
        $currency = strtoupper($this->nullableString($row['currency_id'] ?? null) ?? 'USD');
        $entries = array_values(array_filter(
            is_array(data_get($row, 'relationship.payouts.entries')) ? data_get($row, 'relationship.payouts.entries') : [],
            'is_array',
        ));

        // The sum is what a full completion pays us. A revenue-share entry
        // prices itself as a percentage and contributes nothing here, so an
        // offer paying *only* revenue share totals zero — and a zero-payout tile
        // is a wall slot that cannot earn either of us anything.
        $payout = $this->sumPayouts($entries, 'payout_amount');

        if (! isset($row['network_offer_id']) || $link === null || $currency !== 'USD' || $payout <= 0) {
            return null;
        }

        if (strtolower($this->nullableString($row['offer_status'] ?? null) ?? 'active') !== 'active') {
            return null;
        }

        $goals = $this->goals($entries);

        return new OfferDTO(
            externalId: (string) $row['network_offer_id'],
            name: (string) ($this->nullableString($row['name'] ?? null) ?? 'Untitled offer'),
            trackingUrl: $this->withSubIdMacros($link),
            payout: $payout,
            // `html_description` is markup by name and by content. The wall
            // escapes what it prints, so stored raw it reaches the user as the
            // literal string `<b>reach level 10</b>` — and rendered instead of
            // escaped it would be third-party HTML inside a publisher's iframe.
            // Flattened once, here, like every sibling driver stores plain text.
            description: $this->nullableString(Plain::text($this->nullableString($row['html_description'] ?? null))),
            iconUrl: $this->nullableString($row['thumbnail_url'] ?? null),
            previewUrl: $this->nullableString($row['preview_url'] ?? null),
            countries: $this->parseCountries($this->included(data_get($row, 'relationship.ruleset.countries'), 'country_code')),
            // ASSUMPTION: platform targeting is read out of the ruleset the same
            // way, and `parseDevices()` throws away whatever does not map — so a
            // shape we have not seen costs an empty list (every device, which is
            // this DTO's default) rather than wrong targeting.
            devices: $this->parseDevices($this->included(data_get($row, 'relationship.ruleset.platforms'), 'platform')),
            // Wrapped in a list of one: the category arrives as an object, and
            // `categoryNames()` reads a bare array as the list itself — which
            // would file the offer under its id and its status.
            categories: $this->categoryNames([data_get($row, 'relationship.category')]),
            // `payout_type` is their cpa/cpi/cps vocabulary, which is our
            // conversion type rather than a category a user would recognise on
            // the wall. Anything the enum does not know becomes `cpa`.
            conversionType: $this->conversionType($this->defaultEntry($entries)['payout_type'] ?? null),
            isMultiReward: count($goals) > 1,
            goals: $goals,
        );
    }

    /**
     * The priced steps of the offer, in the order Adtogame lists them.
     *
     * The base entry carries no name — it is the offer itself — so it is
     * labelled rather than dropped, and the event names arrive as the snake_case
     * keys their dashboard stores. Entries worth nothing are left out: a
     * "Revenue Share — 0.00" row on a wall tells the user the step is free.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return list<array{name: string, payout: float}>
     */
    private function goals(array $entries): array
    {
        $goals = [];

        foreach ($entries as $entry) {
            $payout = (float) ($entry['payout_amount'] ?? 0);

            if ($payout <= 0) {
                continue;
            }

            $name = $this->nullableString($entry['entry_name'] ?? null);

            $goals[] = [
                'name' => $name === null ? 'Base' : Str::title(str_replace('_', ' ', $name)),
                'payout' => $payout,
            ];
        }

        return $goals;
    }

    /**
     * The entry the offer is priced on when nothing has fired yet — their flag
     * if it is set, the first entry otherwise.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, mixed>
     */
    private function defaultEntry(array $entries): array
    {
        foreach ($entries as $entry) {
            if (($entry['is_default'] ?? false) === true) {
                return $entry;
            }
        }

        return $entries[0] ?? [];
    }

    /**
     * The values of a targeting list, minus the rules that *block* traffic.
     *
     * Everflow rules carry their own direction in `targeting_type`, so a country
     * list can be a blocklist. Read as an include list it would aim the offer at
     * exactly the places it may not run — clicks that cannot convert, on a wall
     * slot that could have earned. An entry with no direction is an include,
     * which is what every catalogue row seen so far carries.
     *
     * ponytail: an exclude-only ruleset degrades to worldwide, because `OfferDTO`
     * has no vocabulary for "everywhere but here". Give it one the day a network
     * ships offers targeted that way.
     *
     * @return list<string>
     */
    private function included(mixed $rules, string $key): array
    {
        if (! is_array($rules)) {
            return [];
        }

        return collect($rules)
            ->filter(fn (mixed $rule): bool => is_array($rule)
                && strtolower((string) ($this->nullableString($rule['targeting_type'] ?? null) ?? 'include')) !== 'exclude')
            ->map(fn (array $rule): ?string => $this->nullableString($rule[$key] ?? null))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Idempotent, and it replaces rather than appends: these links arrive with
     * the affiliate's own values already in them, and a second `sub2=` on the
     * end of a link that already has one is ignored by the tracker — which is a
     * click whose conversion comes back with somebody else's subid.
     */
    private function withSubIdMacros(string $link): string
    {
        foreach (self::SUBID_PARAMS as $param) {
            $replaced = preg_replace('/([?&]'.$param.'=)[^&]*/i', '${1}{click_id}', $link, 1, $count);

            $link = $count > 0
                ? (string) $replaced
                : $link.(str_contains($link, '?') ? '&' : '?').$param.'={click_id}';
        }

        return $link;
    }

    /**
     * A macro the other end failed to fill is not a value.
     *
     * Every parameter in this network's postback is a macro we asked for by
     * name, and a macro their dashboard does not know arrives as the literal
     * `{conversion_id}`. Taken at face value that string is the same on every
     * conversion, which would make the second one a duplicate of the first and
     * every one after it unpaid.
     */
    private function resolved(mixed $value): ?string
    {
        $value = $this->nullableString($value);

        return $value === null || preg_match('/^[{\[].*[}\]]$/', trim($value)) === 1 ? null : $value;
    }
}
