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
use Illuminate\Http\Request;

/**
 * Hosted MyChips / MAF wall. There is no publisher catalogue — the empty
 * `/api` page on docs.mychips.io is not a stub we fill in later. Offers live
 * on their iframe; conversions come back on a GET we write in their Settings.
 *
 * They sign nothing. `verifyPostback()` is a no-op and the controller's
 * source-IP allowlist is the whole of the authentication. Empty rejects.
 *
 * Additional postbacks they fire at the same path: append `kind=deduction` or
 * paste `rejected_reason_id={rejected_reason_id}` (Rejected). `{payout}` on a
 * deduction is positive; we negate it. `{postback_id}` is unique per fire, so
 * it cannot be the idempotency key — a deduction would miss its credit.
 */
class MyChipsDriver extends AbstractDriver
{
    use NormalizesFields;

    public static function key(): string
    {
        return 'mychips';
    }

    public static function connector(): Connector
    {
        return new Connector('MyChips', ConnectorCategory::Direct, website: 'https://maf.ad');
    }

    /**
     * @return array<string, string>
     */
    public static function postbackQuery(): array
    {
        return [
            'click_id' => '{click_id}',
            'aff_sub1' => '{aff_sub1}',
            'payout' => '{payout}',
            'user_id' => '{user_id}',
            'event_name' => '{event_name}',
            'campaign_id' => '{campaign_id}',
            'postback_id' => '{postback_id}',
            'user_payout' => '{user_payout}',
            'conversion_country' => '{conversion_country}',
            'conversion_time' => '{unix_timestamp}',
        ];
    }

    /**
     * @return array<string, array{label: string, required?: bool, secret?: bool}>
     */
    public static function credentialSchema(): array
    {
        return [
            'adunit_id' => ['label' => 'Ad Unit ID (content_id)', 'required' => true],
            'api_key' => ['label' => 'API Key', 'required' => false, 'secret' => true],
        ];
    }

    public function testConnection(): ConnectionResult
    {
        return ConnectionResult::ok(
            'MyChips has no catalogue to fetch — frame the hosted wall and paste the postback URL.',
            0,
        );
    }

    public function syncsCatalogue(): bool
    {
        return false;
    }

    /**
     * @return iterable<OfferDTO>
     */
    public function fetchOffers(): iterable
    {
        return [];
    }

    public function parsePostback(Request $request): ?PostbackDTO
    {
        $payout = $request->input('payout');

        if (! is_numeric($payout)) {
            return null;
        }

        $clickId = $this->nullableString($request->input('click_id'))
            ?? $this->nullableString($request->input('aff_sub1'));
        $event = $this->nullableString($request->input('event_name'));
        $campaign = $this->nullableString($request->input('campaign_id'));

        if ($clickId === null && $event === null && $campaign === null) {
            return null;
        }

        $amount = abs((float) $payout);
        $rejected = (string) $request->input('kind') === 'rejected'
            || $this->nullableString($request->input('rejected_reason_id')) !== null;
        $reversed = ! $rejected && (string) $request->input('kind') === 'deduction';

        return new PostbackDTO(
            networkTxId: implode(':', [$clickId ?? '', $event ?? '', $campaign ?? '', (string) $amount]),
            clickId: $clickId,
            payout: $reversed ? -$amount : $amount,
            status: $rejected ? 'rejected' : ($reversed ? 'reversed' : 'credited'),
            offerExternalId: $campaign,
            offerName: $event ?? $this->nullableString($request->input('campaign_name')),
            endUserId: $this->nullableString($request->input('user_id')),
            ip: $this->nullableString($request->input('ip')),
            raw: $request->all(),
        );
    }

    public function verifyPostback(Request $request): bool
    {
        return true;
    }

    public function buildTrackingUrl(Offer $offer, string $clickId): string
    {
        return str_replace('{click_id}', $clickId, $offer->tracking_url);
    }
}
