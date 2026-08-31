<?php

namespace App\Networks\Drivers;

use App\Models\Offer;
use App\Networks\AbstractDriver;
use App\Networks\Drivers\Concerns\NormalizesFields;
use App\Networks\DTO\ConnectionResult;
use App\Networks\DTO\OfferDTO;
use App\Networks\DTO\PostbackDTO;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Query-string auth — three credentials, all of them in the URL.
 *
 * The catalogue call and its field mapping are ported from a working Torox
 * integration, so endpoint, auth and offer shape are known-good. The postback
 * half comes from the same integration's inbound contract: Torox fires
 * `user_id`, `oid`, `o_name`, `amount`, `ip_address` and `payout`, and the
 * older `campaign_id`/`campaign_name`/`ip` spellings are read as fallbacks
 * because that is what the ported endpoint accepted. Torox signs nothing and
 * documents no transaction id — see `parsePostback()` and `verifyPostback()`
 * for what that costs us.
 */
class ToroxDriver extends AbstractDriver
{
    use NormalizesFields;

    private const ENDPOINT = 'https://torox.io/api';

    /**
     * ASSUMPTION: `user_id` is the slot Torox echoes back, so it is the slot a
     * link with no recognisable placeholder gets given. Every catalogue link
     * seen so far carries one of the placeholders below, which is the case this
     * is a backstop for rather than the normal path.
     */
    private const SUBID_PARAM = 'user_id';

    /**
     * The placeholder tokens these links actually arrive with. Torox templates
     * some of them and hard-codes a literal `user=1` into others.
     */
    private const SUBID_PLACEHOLDERS = [
        'user=1' => 'user={click_id}',
        '[USER_ID]' => '{click_id}',
        '[user_id]' => '{click_id}',
        'USER_ID_HERE' => '{click_id}',
        '{{userid}}' => '{click_id}',
    ];

    public static function key(): string
    {
        return 'torox';
    }

    /**
     * @return array<string, array{label: string, required?: bool, secret?: bool}>
     */
    public static function credentialSchema(): array
    {
        return [
            'pubid' => ['label' => 'Publisher ID', 'required' => true],
            'appid' => ['label' => 'App ID', 'required' => true],
            'secretkey' => ['label' => 'Secret Key', 'required' => true, 'secret' => true],
        ];
    }

    public function testConnection(): ConnectionResult
    {
        $response = $this->http()->get(self::ENDPOINT, $this->authParams());

        if ($response->failed()) {
            return ConnectionResult::fail("Torox returned HTTP {$response->status()}.");
        }

        $offers = $response->json('response.offers');

        // Torox answers a bad key with HTTP 200 and an error body. Counting
        // that as "connected, 0 offers" is the worst possible answer: the admin
        // pastes a typo, the button goes green, and the network is switched on
        // to sync nothing.
        if (! is_array($offers)) {
            return ConnectionResult::fail($this->rejection($response->json()));
        }

        return ConnectionResult::ok('Connected.', count($offers));
    }

    /**
     * @return iterable<OfferDTO>
     */
    public function fetchOffers(): iterable
    {
        $body = $this->http()
            ->timeout(180)
            ->get(self::ENDPOINT, $this->authParams())
            ->throw()
            ->json();

        $rows = data_get($body, 'response.offers');

        // Same HTTP-200 rejection as above, and here it is worse than a green
        // button: a driver that yields nothing tells the sync job the catalogue
        // is empty, and the sweep deactivates every Torox offer we have. The
        // collapse guard only catches that above twenty offers. Throwing is what
        // puts the run in `last_sync_error` where someone will read it.
        if (! is_array($rows)) {
            throw new RuntimeException('Torox returned no offers array: '.$this->rejection($body));
        }

        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['offer_id'])) {
                continue;
            }

            yield new OfferDTO(
                externalId: (string) $row['offer_id'],
                name: (string) ($row['offer_name'] ?? 'Untitled offer'),
                trackingUrl: $this->withSubIdMacro((string) ($row['offer_url'] ?? '')),
                payout: (float) ($row['payout'] ?? 0),
                description: $this->nullableString($row['offer_desc'] ?? null),
                iconUrl: $this->nullableString($row['image_url'] ?? null),
                countries: $this->parseCountries($row['countries'] ?? []),
                devices: $this->parseDevices($row['device'] ?? []),
                // `payout_type` is Torox's cpi/cpa/cpl vocabulary, which is our
                // conversion type rather than a category a user would recognise
                // on the wall. Anything the enum does not know becomes `cpa`.
                conversionType: $this->conversionType($row['payout_type'] ?? null),
            );
        }
    }

    public function parsePostback(Request $request): ?PostbackDTO
    {
        $clickId = $this->nullableString($request->input('user_id'));
        $payout = $request->input('payout');

        if ($clickId === null || ! is_numeric($payout)) {
            return null;
        }

        $offerId = $this->nullableString($request->input('oid') ?? $request->input('campaign_id'));

        // ASSUMPTION: Torox signals a reversal with a negative payout rather
        // than a status flag. Reading it backwards would credit a chargeback.
        $amount = (float) $payout;
        $reversed = $amount < 0;

        return new PostbackDTO(
            // Torox sends no transaction id, so the key is a composite and it is
            // worth being plain about that: click + offer. A click is one click
            // on one offer and Torox prices the offer whole, so a second fire
            // for the same pair is exactly the duplicate we need to reject.
            // Sign is deliberately absent — `reverse()` looks the credit up by
            // this key, so a reversal has to produce the same string.
            networkTxId: $clickId.':'.$offerId,
            clickId: $clickId,
            payout: $reversed ? -abs($amount) : abs($amount),
            status: $reversed ? 'reversed' : 'credited',
            offerExternalId: $offerId,
            offerName: $this->nullableString($request->input('o_name') ?? $request->input('campaign_name')),
            // No `endUserId`: `user_id` is Torox echoing our subid back, not an
            // identifier of theirs. The end user is the click's, and the
            // processor already reads it from there.
            ip: $this->nullableString($request->input('ip_address') ?? $request->input('ip')),
            raw: $request->all(),
        );
    }

    public function verifyPostback(Request $request): bool
    {
        // Torox signs nothing and offers no shared postback token, so there is
        // nothing here to check that would not be theatre. The source-IP
        // allowlist the controller enforces first is the whole of this
        // network's authentication — and it rejects when empty, so a Torox row
        // with no allowlist earns nothing rather than trusting anyone.
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
     * @return array<string, string>
     */
    private function authParams(): array
    {
        return [
            'pubid' => (string) $this->credential('pubid'),
            'appid' => (string) $this->credential('appid'),
            'secretkey' => (string) $this->credential('secretkey'),
            'format' => 'json',
        ];
    }

    /** Idempotent: a link that already carries the macro is left alone. */
    private function withSubIdMacro(string $link): string
    {
        $link = str_replace(
            array_keys(self::SUBID_PLACEHOLDERS),
            array_values(self::SUBID_PLACEHOLDERS),
            $link,
        );

        if (str_contains($link, '{click_id}')) {
            return $link;
        }

        return $link.(str_contains($link, '?') ? '&' : '?').self::SUBID_PARAM.'={click_id}';
    }

    /** Whatever Torox put in the body instead of a catalogue, for the operator. */
    private function rejection(mixed $body): string
    {
        $message = data_get($body, 'response.error')
            ?? data_get($body, 'error')
            ?? data_get($body, 'response.status');

        return $this->nullableString($message) ?? 'no error message given.';
    }
}
