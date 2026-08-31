<?php

namespace App\Networks\Drivers;

use App\Models\Offer;
use App\Networks\AbstractDriver;
use App\Networks\Drivers\Concerns\NormalizesFields;
use App\Networks\DTO\ConnectionResult;
use App\Networks\DTO\OfferDTO;
use App\Networks\DTO\PostbackDTO;
use Illuminate\Http\Request;

/**
 * Offers we sell directly, entered by hand in the admin panel.
 *
 * There is no API on the other side, so this driver has no catalogue and no
 * credentials to test — `syncsCatalogue()` is false and the sync job leaves the
 * network alone. Everything downstream of a click is unchanged: the postback
 * contract below is ours to define rather than a network's to guess, so the
 * parameter names are a specification an advertiser is handed, not an
 * ASSUMPTION to verify later.
 *
 * The advertiser is given:
 *
 *   https://{host}/postback/direct
 *       ?transaction_id={their unique id}   required — the idempotency key
 *       &click_id={click_id}                required — echoed from our link
 *       &payout={amount USD}                required
 *       &secret={postback secret}           required
 *       &status=1|2                         optional — 2 (or "reversed") reverses
 *       &offer_id={their offer id}          optional
 */
class DirectDriver extends AbstractDriver
{
    use NormalizesFields;

    public static function key(): string
    {
        return 'direct';
    }

    /**
     * @return array<string, string>
     */
    public static function postbackQuery(): array
    {
        return [
            'transaction_id' => '{transaction_id}',
            'click_id' => '{click_id}',
            'payout' => '{payout}',
            'secret' => '{secret}',
            'status' => '{status}',
            'offer_id' => '{offer_id}',
        ];
    }

    /**
     * @return array<string, array{label: string, required?: bool, secret?: bool}>
     */
    public static function credentialSchema(): array
    {
        return [
            'postback_secret' => ['label' => 'Postback Secret', 'required' => true, 'secret' => true],
        ];
    }

    /** Nothing to connect to — the useful answer is how much is on the shelf. */
    public function testConnection(): ConnectionResult
    {
        return ConnectionResult::ok(
            'Direct offers are entered by hand — there is no catalogue to fetch.',
            (int) $this->network->offers()->count(),
        );
    }

    public function syncsCatalogue(): bool
    {
        return false;
    }

    /**
     * Never reached: the sync job checks `syncsCatalogue()` first. Empty rather
     * than throwing, because a driver that yields nothing is the honest answer
     * if it ever is called.
     *
     * @return iterable<OfferDTO>
     */
    public function fetchOffers(): iterable
    {
        return [];
    }

    public function parsePostback(Request $request): ?PostbackDTO
    {
        $transId = $this->nullableString($request->input('transaction_id'));
        $payout = $request->input('payout');

        // No fallback idempotency key here, unlike the networks that omit one:
        // we set this contract, and a duplicate credit is money we do not get
        // back. An advertiser who cannot send an id cannot be paid per event.
        if ($transId === null || ! is_numeric($payout)) {
            return null;
        }

        $reversed = in_array(strtolower((string) $request->input('status', '1')), ['2', 'reversed'], true);
        $amount = abs((float) $payout);

        return new PostbackDTO(
            networkTxId: $transId,
            clickId: $this->nullableString($request->input('click_id')),
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
        $secret = $this->credential('postback_secret');

        // Fails closed — the secret is a required credential, so a blank one is
        // a misconfiguration, not permission to skip the check. The controller's
        // source-IP allowlist is the second gate.
        if (blank($secret)) {
            return false;
        }

        return hash_equals($secret, (string) $request->input('secret', ''));
    }

    public function buildTrackingUrl(Offer $offer, string $clickId): string
    {
        return str_replace('{click_id}', $clickId, $offer->tracking_url);
    }
}
