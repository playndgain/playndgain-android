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
 * One key, one unpaginated catalogue call, and the best-specified postback of
 * the set after GemiAd — theirs, not ours, and signed.
 *
 * Four things about this network worth knowing before reading the code:
 *
 *   - **The postback contract is theirs.** Unlike GemiAd or Adtogame, Offery
 *     sends a fixed set of parameter names to whatever URL is saved in their
 *     dashboard, so there is no macro template to compose. Paste this into
 *     Offery's dashboard → Postback URL and nothing else:
 *
 *     ```
 *     https://{host}/postback/offery
 *     ```
 *
 *     They POST it, and the keys below (`subId`, `transId`, `payout`, …) are
 *     read because those are the ones they document sending.
 *   - It signs, and the recipe is documented rather than guessed:
 *     `md5(subId + transId + reward + secretKey)`. md5 is their choice; the
 *     comparison here is still constant-time, because a fast reject on a
 *     forgery is worth nothing and a timing oracle is worth something.
 *   - **`payout` is the USD figure and `reward` is not.** `reward` is the same
 *     conversion multiplied through the publisher's own exchange rate
 *     (`reward_value` units per dollar), and `PostbackDTO` is denominated in
 *     USD. Crediting the ledger with `reward` would be off by whatever that
 *     rate is — a thousand times over for a publisher on 1000 coins to the
 *     dollar. `reward` is still read, but only as a string, because it is the
 *     third term of the signature.
 *   - A chargeback is `status=2` carrying the original `transId`, so the
 *     reversal lands on the key its own credit produced with no composite
 *     needed. Their docs are explicit that `reward` and `payout` arrive as
 *     absolute values on a chargeback, so the sign carries no signal here and
 *     `status` is the only thing read for direction.
 *
 * The subid slot is a path segment rather than a query parameter — their click
 * links end `/click/{token}/{offerId}/USER_ID` with that last segment literal —
 * which is why this driver substitutes rather than appends.
 */
class OfferyDriver extends AbstractDriver
{
    use NormalizesFields;

    private const ENDPOINT = 'https://offery.io/api/';

    /** The literal trailing path segment their catalogue links carry. */
    private const SUBID_PLACEHOLDER = 'USER_ID';

    /**
     * `PC` is their word for desktop and `parseDevices()` does not know it —
     * it matches `desktop` and `web`. Left unmapped, a PC-only offer stores an
     * empty device list, and an empty list means *every* device: the offer is
     * shown to the Android and iOS users it cannot pay for, who install
     * nothing and earn nothing. Failing open on targeting is the expensive
     * direction.
     */
    private const DEVICE_ALIASES = [
        'pc' => 'desktop',
    ];

    public static function key(): string
    {
        return 'offery';
    }

    /**
     * @return array<string, array{label: string, required?: bool, secret?: bool}>
     */
    public static function credentialSchema(): array
    {
        // Two keys, two places, not interchangeable: the API key reads the
        // catalogue, the secret key only verifies postback signatures. Saying
        // which is which in the label is cheaper than finding out from a run of
        // rejected conversions that the wrong field was pasted.
        return [
            'api_key' => ['label' => 'API key', 'required' => true, 'secret' => true],
            'secret_key' => [
                'label' => 'Secret key (signs postbacks)',
                'required' => true,
                'secret' => true,
            ],
        ];
    }

    public function testConnection(): ConnectionResult
    {
        $response = $this->http()->get(self::ENDPOINT, $this->authParams());

        if ($response->failed()) {
            return ConnectionResult::fail("Offery returned HTTP {$response->status()}: ".$this->rejection($response));
        }

        if (! $this->succeeded($response)) {
            return ConnectionResult::fail('Offery rejected the request: '.$this->rejection($response));
        }

        $rows = $response->json('data');

        return ConnectionResult::ok('Connected.', is_array($rows) ? count($rows) : 0);
    }

    /**
     * One call — their API is unpaginated and every documented parameter except
     * the key is a filter.
     *
     * Called with no filters on purpose. `countries` and `device` would narrow
     * the catalogue to a subset, and the wall does its own targeting off
     * `offers.countries`/`devices`: filtering here would mean an offer that
     * exists for Germany is never stored, so no German user ever sees it.
     *
     * ponytail: read into memory rather than streamed off disk. Their inventory
     * is hundreds of offers, not hundreds of thousands; if that changes, stream
     * the `data` array.
     *
     * @return iterable<OfferDTO>
     */
    public function fetchOffers(): iterable
    {
        $response = $this->http()
            ->timeout(180)
            ->get(self::ENDPOINT, $this->authParams())
            ->throw();

        $rows = $response->json('data');

        // Failing loudly rather than yielding nothing. A driver that returns an
        // empty catalogue tells the sync job the network has no offers left, and
        // the sweep deactivates every Offery offer we have — the collapse guard
        // only catches that above twenty offers. Throwing puts the reason in
        // `last_sync_error` where someone will read it.
        if (! $this->succeeded($response) || ! is_array($rows)) {
            throw new RuntimeException('Offery rejected the offers request: '.$this->rejection($response));
        }

        foreach ($rows as $row) {
            if (! is_array($row) || blank(data_get($row, 'offer.id'))) {
                continue;
            }

            $events = array_values(array_filter(
                is_array($row['events'] ?? null) ? $row['events'] : [],
                'is_array',
            ));

            yield new OfferDTO(
                externalId: (string) data_get($row, 'offer.id'),
                name: (string) (data_get($row, 'offer.name') ?? 'Untitled offer'),
                trackingUrl: $this->withSubIdMacro((string) ($row['url'] ?? '')),
                // `payout.usd` is the offer total and is the authority. Their
                // own example does not add up — three events at 0.50, 1.17 and
                // 3.09 against a stated 4.36 — so recomputing the total from
                // the events would price offers at something the network never
                // agreed to pay.
                payout: (float) data_get($row, 'payout.usd', 0),
                description: $this->nullableString(data_get($row, 'offer.description')),
                // "Achieve a specific target" — what the user has to do, which
                // is instructions and not a blurb.
                instructions: $this->nullableString(data_get($row, 'offer.requirements')),
                iconUrl: $this->nullableString(data_get($row, 'offer.image')),
                countries: $this->countries($row['geo_targeting'] ?? null),
                devices: $this->devices($row['devices'] ?? []),
                // No category field in their payload. The wall groups by what
                // it has, and an invented category is worse than none.
                categories: [],
                // Not published per offer either. Their inventory is mixed
                // (`offer_type` on the postback says ptc, offer, task or
                // shortlink), so the default stands rather than a guess.
                conversionType: 'cpa',
                isMultiReward: count($events) > 1,
                // A step with no payout is still a step the user completes, so
                // it is listed at zero rather than dropped.
                goals: $this->goalList($events, 'name', 'payout'),
            );
        }
    }

    public function parsePostback(Request $request): ?PostbackDTO
    {
        $clickId = $this->nullableString($request->input('subId'));
        $txId = $this->nullableString($request->input('transId'));
        $payout = $request->input('payout');

        // All three are required: two of them are terms of the signature, and a
        // fire without the third is a conversion with no amount. Parsing one of
        // those would only produce something unattributable or worthless.
        if ($clickId === null || $txId === null || ! is_numeric($payout)) {
            return null;
        }

        // `debug=1` is their test fire. It is signed and genuine, so it is
        // recorded — but as `rejected`, which writes the conversion row and
        // moves no money. Checked before `status`, deliberately: a debug
        // chargeback must not claw back a real credit either.
        $isTest = (string) $request->input('debug') === '1';

        // 1 credits, 2 charges back — the only two values they document. Their
        // docs are explicit that `reward` and `payout` are absolute on both, so
        // unlike GemiAd there is no sign to cross-check and `abs()` here is
        // belt-and-braces rather than the contract.
        $reversed = ! $isTest && (string) $request->input('status') === '2';

        $amount = abs((float) $payout);

        return new PostbackDTO(
            // ASSUMPTION: each payable event of a multi-event offer fires with
            // its own `transId`. Their docs describe a transaction id as unique
            // and name no per-event key, so this is the one detail to confirm
            // against a live multi-event fire. If they reuse one id across an
            // offer's events, the second event is dropped as a duplicate — a
            // step the publisher earned and does not see. Suffixing the event
            // here would fix that and break the chargeback instead, because a
            // chargeback quotes only the transaction id: this codebase would
            // rather miss a step than fail to claw one back.
            networkTxId: $txId,
            clickId: $clickId,
            payout: $reversed ? -$amount : $amount,
            status: match (true) {
                $isTest => 'rejected',
                $reversed => 'reversed',
                default => 'credited',
            },
            offerExternalId: $this->nullableString($request->input('offer_id')),
            offerName: $this->nullableString($request->input('offer_name')),
            // No `endUserId`: `subId` is Offery echoing our click id back, not
            // an identifier of theirs. The end user belongs to the click, and
            // the processor already reads it from there.
            ip: $this->nullableString($request->input('userIp')),
            raw: $request->all(),
        );
    }

    public function verifyPostback(Request $request): bool
    {
        $secret = $this->credential('secret_key');

        // Fails closed. The secret is a required credential, so a blank one is
        // a misconfiguration, not permission to skip the check.
        if (blank($secret)) {
            return false;
        }

        // Documented, not guessed: md5(subId + transId + reward + secretKey).
        //
        // `reward` is concatenated as the string they sent. Casting it to float
        // and back turns their `1.50` into `1.5` and every postback fails a
        // signature that was never wrong — which looks exactly like an outage
        // on their side.
        $expected = md5(
            (string) $request->input('subId')
            .(string) $request->input('transId')
            .(string) $request->input('reward')
            .$secret
        );

        return hash_equals($expected, strtolower((string) $request->input('signature', '')));
    }

    public function buildTrackingUrl(Offer $offer, string $clickId): string
    {
        // Through the same helper the catalogue went through, not a bare
        // str_replace. A stored link with no slot in it — hand-edited, or synced
        // before this driver normalized links — would otherwise be handed to
        // the user untouched, and every conversion off it comes back with a
        // subid that matches no click. Nobody is paid and nothing logs an error.
        return str_replace('{click_id}', $clickId, $this->withSubIdMacro($offer->tracking_url));
    }

    /**
     * `apiKey` with a capital K. Their query string is case-sensitive; `apikey`
     * is a different parameter and comes back as a refusal.
     *
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

    /** Whatever Offery put in the body instead of a catalogue, for the operator. */
    private function rejection(Response $response): string
    {
        $message = $this->nullableString($response->json('message'))
            ?? $this->nullableString($response->json('error'))
            ?? $this->nullableString($response->json('status'));

        return $message ?? 'no error message given.';
    }

    /**
     * The allowlist half of their `geo_targeting` object.
     *
     * ponytail: `excluded_countries` is dropped, because there is nowhere to put
     * it — `offers.countries` is an allowlist and nothing else. The cost is
     * real and bounded: a worldwide-minus-Brazil offer is stored as worldwide,
     * a Brazilian user can click it, and Offery declines that conversion. The
     * upgrade path is an `excluded_countries` column read alongside `countries`
     * in the wall's targeting filter — worth doing when a partner ships enough
     * exclusion-shaped inventory to show up as unpaid clicks.
     *
     * @return list<string>
     */
    private function countries(mixed $geoTargeting): array
    {
        $allowed = data_get(is_array($geoTargeting) ? $geoTargeting : [], 'allowed_countries');

        return $this->parseCountries(match (true) {
            is_array($allowed) => array_values(array_filter($allowed, 'is_scalar')),
            is_string($allowed) => $allowed,
            default => [],
        });
    }

    /**
     * Their device list, with `PC` translated before `parseDevices()` decides
     * what to keep.
     *
     * @return list<string>
     */
    private function devices(mixed $devices): array
    {
        $names = collect(is_array($devices) ? $devices : [$devices])
            ->map(fn (mixed $device): string => strtolower(trim((string) (is_scalar($device) ? $device : ''))))
            ->map(fn (string $device): string => self::DEVICE_ALIASES[$device] ?? $device)
            ->all();

        return $this->parseDevices($names);
    }

    /**
     * Their click link with the click-id slot opened up.
     *
     * ponytail: only `USER_ID` is substituted, and a link that arrives without
     * it gets the macro appended as a final path segment — which is where their
     * documented format puts it (`/click/{token}/{offerId}/USER_ID`). A link
     * shaped differently enough for that to be wrong is one this driver did not
     * fetch. Idempotent: a link already carrying the macro is left alone.
     */
    private function withSubIdMacro(string $link): string
    {
        $link = str_replace(self::SUBID_PLACEHOLDER, '{click_id}', $link);

        if ($link === '' || str_contains($link, '{click_id}')) {
            return $link;
        }

        return rtrim($link, '/').'/{click_id}';
    }
}
