<?php

namespace App\Networks\Drivers;

use App\Models\Offer;
use App\Networks\AbstractDriver;
use App\Networks\Drivers\Concerns\NormalizesFields;
use App\Networks\DTO\ConnectionResult;
use App\Networks\DTO\OfferDTO;
use App\Networks\DTO\PostbackDTO;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The adslot is the endpoint; the API key is the only credential in the query.
 *
 * The catalogue call and its field mapping are ported from a working Ayet
 * integration, so endpoint, auth and offer shape are known-good. Three things
 * about this network are unlike the others here:
 *
 *   - It answers a rejected key with HTTP 200 and `status` set to something
 *     other than `success`. Every method below checks the envelope, because
 *     the status line alone says the call went fine.
 *   - We write the postback contract, not Ayet. Their Conversion Callback is a
 *     URL template an admin pastes into the dashboard under Placements →
 *     Settings, and Ayet substitutes macros into it on the way out. Nothing
 *     below can work until that template is pasted, and the template exists
 *     nowhere else in this repository, so here it is:
 *
 *       https://{host}/postback/ayetstudios
 *           ?callback_type={callback_type}
 *           &transaction_id={transaction_id}
 *           &is_chargeback={is_chargeback}
 *           &payout_usd={payout_usd}
 *           &custom_1={custom_1}
 *           &external_identifier={external_identifier}
 *           &offer_id={offer_id}
 *           &offer_name={offer_name}
 *           &ip={ip}
 *
 *     Every parameter there is read below and nothing read below is missing
 *     from it. Dropping most of them fails silently — the postback still
 *     arrives, still answers 200, and the field it fed is simply null forever.
 *     Two of them do not: without `transaction_id` or `payout_usd` there is no
 *     idempotency key and no amount, `parsePostback()` returns null, and the
 *     422 that follows buys twelve retries of every event. The two chargeback
 *     flags are the safe ones to lose — they are read as three independent
 *     signals and any one of them still reverses.
 *   - Ayet retries any non-200 twelve times over an hour, so every rejection
 *     path in `PostbackController` is a retry storm rather than one lost
 *     conversion. A postback we cannot attribute to a click deliberately is
 *     not one of those paths — `parsePostback()` returns a DTO with a null
 *     click, the controller answers 200, and the row waits in the log for an
 *     admin to attach by hand.
 *
 * Postbacks carry an `X-Ayetstudios-Security-Hash` header, checked by
 * `verifyPostback()` when the account-level key is configured. The source-IP
 * allowlist on the `networks` row is the other half and is not optional: the
 * seeded `ayetstudios` row carries all six addresses Ayet fires from, and a
 * partial list is the failure that costs money rather than the one that looks
 * broken — the network works, and the conversions fired from the missing
 * addresses are rejected at the door and never paid.
 */
class AyetStudiosDriver extends AbstractDriver
{
    use NormalizesFields;

    /**
     * Our click id rides in `custom_1`, not in `external_identifier`.
     *
     * Ayet documents `external_identifier` as a *persistent user* identifier:
     * it keys their reward-status lookups, and a publisher who cannot supply a
     * stable one needs account-manager sign-off. Our click id is a fresh UUID
     * per tap, so claiming that slot tells Ayet every single click is a brand
     * new user. `custom_1` is documented as exactly what we need and nothing
     * else — "custom parameter to pass variables to the conversion callbacks",
     * appended to Static API and Offerwall API tracking links — and Ayet gives
     * it no meaning of its own that we could collide with.
     *
     * The catalogue's own user placeholders still get filled with the click id;
     * `withClickIdMacro()` explains why that is the least-bad option available
     * to a method that is handed a click id and nothing else.
     */
    private const CLICK_PARAM = 'custom_1';

    /** The signature header Ayet sends on every callback. */
    private const HASH_HEADER = 'X-Ayetstudios-Security-Hash';

    /**
     * The placeholder tokens catalogue links actually arrive with. Ayet
     * substitutes some into the path and templates others into the query, and
     * every one of them marks the `external_identifier` slot.
     */
    private const USER_PLACEHOLDERS = [
        'user=1' => 'user={click_id}',
        '[USER_ID]' => '{click_id}',
        '[user_id]' => '{click_id}',
        'USER_ID_HERE' => '{click_id}',
        '{{userid}}' => '{click_id}',
    ];

    public static function key(): string
    {
        return 'ayetstudios';
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
            'custom_1' => '{custom_1}',
            'external_identifier' => '{external_identifier}',
            'offer_id' => '{offer_id}',
            'offer_name' => '{offer_name}',
            'ip' => '{ip}',
        ];
    }

    /**
     * @return array<string, array{label: string, required?: bool, secret?: bool}>
     */
    public static function credentialSchema(): array
    {
        // The first two labels name the *adslot* deliberately. Ayet issues an
        // account-level API key under Account Settings, and it is not this one:
        // their documentation is explicit that the Static API key "is adslot
        // specific and available in the adslot details once approved by your
        // account manager". Pasting the account key here returns error 103 with
        // a message that blames the adslot, which is a long way to travel to
        // find out you copied the wrong field.
        //
        // The account key now has a home of its own, which is the third field —
        // it signs the postbacks and it fetches nothing. Swapping the two
        // breaks both halves at once and neither failure names the other, so
        // each label says which key it wants rather than trusting the order.
        return [
            'adslot_id' => ['label' => 'Adslot ID', 'required' => true],
            'api_key' => [
                'label' => 'Static API key (adslot-specific, not the account API key)',
                'required' => true,
                'secret' => true,
            ],
            'publisher_api_key' => [
                'label' => 'Publisher API key (account-level, signs postbacks — not the Static API key above)',
                'required' => false,
                'secret' => true,
            ],
        ];
    }

    public function testConnection(): ConnectionResult
    {
        $response = $this->http()->get($this->endpoint(), $this->authParams());

        if ($response->failed()) {
            return ConnectionResult::fail("Ayet Studios returned HTTP {$response->status()}.");
        }

        // A wrong key, a wrong adslot and a disabled account all arrive as
        // HTTP 200. Reporting that as "connected, 0 offers" is the worst
        // possible answer: the admin pastes a typo, the button goes green, and
        // the network is switched on to sync nothing.
        if (! $this->succeeded($response)) {
            return ConnectionResult::fail('Ayet Studios rejected the request: '.$this->statusText($response));
        }

        $offers = $response->json('offers');

        return ConnectionResult::ok('Connected.', is_array($offers) ? count($offers) : 0);
    }

    /**
     * ponytail: read into memory rather than streamed off disk. One adslot's
     * catalogue fits; if one stops fitting, stream the `offers` array.
     *
     * @return iterable<OfferDTO>
     */
    public function fetchOffers(): iterable
    {
        $response = $this->http()
            ->timeout(180)
            ->get($this->endpoint(), $this->authParams())
            ->throw();

        // Failing loudly rather than yielding nothing. A driver that returns an
        // empty catalogue tells the sync job the network has no offers left, and
        // the sweep deactivates every Ayet offer we have — the collapse guard
        // only catches that above twenty offers. Throwing puts the reason in
        // `last_sync_error` where someone will read it.
        if (! $this->succeeded($response)) {
            throw new RuntimeException('Ayet Studios rejected the offers request: '.$this->statusText($response));
        }

        $rows = $response->json('offers');

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row) || ! isset($row['id'])) {
                continue;
            }

            $tasks = array_values(array_filter($row['tasks'] ?? [], 'is_array'));

            yield new OfferDTO(
                externalId: (string) $row['id'],
                name: (string) ($row['name'] ?? 'Untitled offer'),
                trackingUrl: $this->withClickIdMacro((string) ($row['tracking_link'] ?? '')),
                // A task-based offer prices each step, and completing all of
                // them is what the offer is worth to us. `payout_usd` is the
                // authority only when there are no tasks to add up.
                payout: $tasks === [] ? (float) ($row['payout_usd'] ?? 0) : $this->sumPayouts($tasks),
                // `introduction` is the fuller blurb on offers that carry both,
                // and the only one on offers that carry no description at all.
                description: $this->nullableString($row['description'] ?? null)
                    ?? $this->nullableString($row['introduction'] ?? null),
                instructions: $this->instructions($row),
                iconUrl: $this->nullableString($row['icon'] ?? null),
                countries: $this->parseCountries($row['countries'] ?? []),
                devices: $this->parseDevices($this->platforms($row)),
                categories: $this->categoryNames($this->categories($row)),
                conversionType: $this->conversionType($row['conversion_type'] ?? null),
                isMultiReward: count($tasks) > 1,
                goals: $this->goalList($tasks, 'name', 'payout'),
            );
        }
    }

    public function parsePostback(Request $request): ?PostbackDTO
    {
        $transactionId = $this->nullableString($request->input('transaction_id'));
        $payout = $request->input('payout_usd');

        // The transaction id is the idempotency key, so a postback without one
        // cannot be told apart from a retry of itself. The click id is *not*
        // required: an unattributable postback is still a real event, and
        // returning null here would answer 422 and buy twelve retries of the
        // same unparseable payload. Handing back a DTO with a null click logs
        // it once, at 200, where an admin can attach it by hand.
        if ($transactionId === null || ! is_numeric($payout)) {
            return null;
        }

        $amount = (float) $payout;

        // Three signals, any one of which is believed on its own. Ayet's docs
        // describe a chargeback as all three at once — `callback_type` set to
        // `chargeback`, `is_chargeback` set to 1, and the amounts negative — so
        // requiring them to agree buys nothing and costs everything the day one
        // of them goes missing from the pasted template. The asymmetry settles
        // it: reading a chargeback as a credit pays the publisher a second time
        // for money the advertiser just took back, while reading a credit as a
        // chargeback finds no conversion to reverse and says so in the log.
        $reversed = (string) $request->input('callback_type') === 'chargeback'
            || (string) $request->input('is_chargeback') === '1'
            || $amount < 0;

        return new PostbackDTO(
            // Ayet prepends `r-` to the transaction id on a chargeback and
            // nowhere else, and `ConversionProcessor::reverse()` finds the
            // credit to charge back by exact `network_txid` match. Left alone,
            // a reversal reporting `r-12345` would never meet the `12345` its
            // credit was written under: every Ayet chargeback would fail with
            // "no conversion for network_txid" and the publisher would keep
            // money we did not. Stripped only on a reversal and only once, so a
            // credit whose own id happens to begin `r-` stores as `r-X`, its
            // chargeback arrives as `r-r-X`, and the two still meet.
            //
            // The other consequence of keying on this id is worth being plain
            // about: Ayet fires the Conversion Callback and any Optional
            // Callbacks for one user event simultaneously, all sharing a single
            // transaction id, and their docs say to use that id for duplicate
            // checks. So the siblings collapse into one credit at the
            // `(network_id, network_txid)` unique index. That is the direction
            // to err in — under-crediting arrives as a support ticket,
            // over-crediting arrives as a refund.
            //
            // ponytail: the ceiling is an optional or iap callback that is
            // meant to pay on top of its conversion. Appending `callback_type`
            // to the key would separate those — and break the chargeback above,
            // whose type is `chargeback` where its credit's was `conversion`.
            // Pay for it with an explicit exemption for reversals, not by
            // widening the key and hoping.
            networkTxId: $reversed && str_starts_with($transactionId, 'r-')
                ? substr($transactionId, 2)
                : $transactionId,
            // `custom_1` is where `buildTrackingUrl()` puts the click id.
            // `external_identifier` is the fallback because it is where the id
            // used to go: a click made before this deploy carries a link with
            // only that parameter on it, and Ayet's conversion windows run to
            // weeks — the Coin Master offer in our own fixture gives the user
            // fourteen days. Without the fallback every one of those in-flight
            // clicks converts unattributed, at 200, with nothing anywhere
            // saying why.
            clickId: $this->nullableString($request->input(self::CLICK_PARAM))
                ?? $this->nullableString($request->input('external_identifier')),
            // The sign lives in the status, and the amount is always positive —
            // the same shape every other driver here hands the processor, so
            // `reverse()` gets a chargeback that looks like its own credit.
            payout: $reversed ? -abs($amount) : abs($amount),
            status: $reversed ? 'reversed' : 'credited',
            offerExternalId: $this->nullableString($request->input('offer_id')),
            offerName: $this->nullableString($request->input('offer_name')),
            // No `endUserId`. Everything Ayet sends that resembles one is not
            // one: `external_identifier` is our own value echoed back, and
            // `user_id` is Ayet's internal account id for the player. The
            // processor falls back to the click's `end_user_id`, which is the
            // publisher's identity for that user and the key the ban list and
            // the fraud rules are written against. Filling this field would
            // overwrite it with an id that matches nothing we hold.
            ip: $this->nullableString($request->input('ip')),
            raw: $request->all(),
        );
    }

    public function verifyPostback(Request $request): bool
    {
        $apiKey = $this->credential('publisher_api_key');

        // Open when the credential is absent, the same way AdGate's is. The
        // HMAC needs the account-level publisher API key from dashboard
        // Settings, which is a different credential from the adslot Static API
        // key the catalogue runs on — a live integration can be syncing offers
        // and earning for months before anybody goes and finds it. Failing
        // closed on a field the schema itself calls optional would take a
        // working network off the air the moment this file shipped. The
        // source-IP allowlist is the floor underneath, and it rejects when
        // empty rather than admitting everyone.
        if (blank($apiKey)) {
            return true;
        }

        // Ayet's whole specification is "(1) Get all request parameters (2)
        // Order the request parameters alphabetically (3) Build and compare the
        // HMAC hash using the ordered request parameter string and your API
        // key", so "ordered request parameter string" is an interpretation and
        // this one is ours: the parameters sorted by key as strings and
        // rendered as a urlencoded `k=v&k=v` query string, which is what
        // `http_build_query` over a `ksort`ed array produces and what Ayet's
        // own PHP would produce from that same sentence. Two readings rejected:
        // concatenating the values alone with no keys and no separators, and
        // joining the pairs raw rather than percent-encoded. We cannot settle
        // which is right without a live fire.
        //
        // A wrong recipe rejects every postback, and a network that 403s
        // everything looks exactly like a network that is down. The escape
        // hatch is the credential itself — clear `publisher_api_key` and the
        // network is back to running on the allowlist alone while the recipe
        // gets fixed. The hash header is not a request parameter, so it is not
        // part of the string it signs.
        $params = $request->all();
        ksort($params, SORT_STRING);

        return hash_equals(
            hash_hmac('sha256', http_build_query($params), $apiKey),
            $this->nullableString($request->header(self::HASH_HEADER)) ?? '',
        );
    }

    public function buildTrackingUrl(Offer $offer, string $clickId): string
    {
        // Through the same helper the catalogue went through, not a bare
        // str_replace. A stored link with no macro in it — hand-edited, or
        // synced before this driver normalized links — would otherwise be handed
        // to the user untouched, and every conversion off it comes back with no
        // click id at all. Nobody is paid and nothing logs an error.
        return str_replace('{click_id}', $clickId, $this->withClickIdMacro($offer->tracking_url));
    }

    private function endpoint(): string
    {
        return 'https://www.ayetstudios.com/offers/get/'.(string) $this->credential('adslot_id');
    }

    /**
     * @return array<string, string>
     */
    private function authParams(): array
    {
        return ['apiKey' => (string) $this->credential('api_key')];
    }

    private function succeeded(Response $response): bool
    {
        return $response->json('status') === 'success';
    }

    /**
     * Whatever Ayet put in the envelope instead of `success`, for the operator.
     *
     * `status` is read last, not first. It is always the literal string
     * "error", which tells the admin staring at the Test connection button
     * precisely nothing — the sentence they need is in `errorMessage`, and the
     * code beside it is what their account manager will ask for. Reporting the
     * useless field because it is the one we branch on is how a driver turns a
     * five-minute configuration fix into a support ticket.
     */
    private function statusText(Response $response): string
    {
        $message = $this->nullableString($response->json('errorMessage'))
            ?? $this->nullableString($response->json('message'));

        $code = $response->json('errorCode');

        if ($message === null) {
            return $this->nullableString($response->json('status')) ?? 'no status given.';
        }

        return is_scalar($code) ? "[{$code}] {$message}" : $message;
    }

    /**
     * Idempotent: a link already carrying `custom_1={click_id}` is left alone.
     *
     * Two slots get filled here, for two unrelated reasons. `custom_1` is ours
     * and is the one `parsePostback()` reads back, so it is the one that must
     * always be present — a link with no slot gets one rather than being
     * returned untouched.
     *
     * Ayet's own user placeholder gets the click id as well, because every
     * alternative is worse. Left as the literal `USER_ID_HERE`, every click in
     * the system is the same user to Ayet, and a network that caps an offer per
     * user pays us once and never again. A per-click UUID at least keeps the
     * clicks distinct. It is emphatically not the stable identifier Ayet asks
     * for in that field — this method is handed a click id and nothing else —
     * which is exactly why attribution does not depend on it.
     *
     * A stray `custom_1` already on a link is appended after rather than
     * rewritten in place. On any server that reads the last of a repeated key,
     * PHP's included, ours is the one that wins.
     */
    private function withClickIdMacro(string $link): string
    {
        $link = str_replace(
            array_keys(self::USER_PLACEHOLDERS),
            array_values(self::USER_PLACEHOLDERS),
            $link,
        );

        if (str_contains($link, self::CLICK_PARAM.'={click_id}')) {
            return $link;
        }

        return $link.(str_contains($link, '?') ? '&' : '?').self::CLICK_PARAM.'={click_id}';
    }

    /**
     * Two short fields, either of which may be absent: what the user has to do,
     * then the pitch that sets it up.
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
     * `platforms` is the list form; single-platform offers carry `platform`
     * instead. `parseDevices` throws away whatever does not map.
     *
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
     * The tags are the categories a user would recognise on the wall. Offers
     * that carry none fall back to how the offer converts and what shelf Ayet
     * files it under, which is thin but better than an uncategorised offer.
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
