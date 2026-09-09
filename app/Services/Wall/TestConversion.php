<?php

namespace App\Services\Wall;

use App\Enums\IncomingPostbackResult;
use App\Models\IncomingPostback;
use App\Models\Offer;
use App\Models\OfferClick;
use App\Networks\DTO\PostbackDTO;
use App\Services\Money\ConversionProcessor;

/**
 * The test offer's conversion — docs/03-offerwall.md §7.
 *
 * "Clicking it fires a real end-to-end conversion through the full pipeline —
 * fraud rules, ledger entry, outgoing postback, all of it." That sentence is
 * why this synthesises a postback instead of simulating a result: the click
 * runs `ConversionProcessor::record()`, the same method the network endpoint
 * calls, so the integration being tested is the real one down to the macro
 * expansion and the signature.
 *
 * What it skips is only the transport. There is no advertiser to redirect the
 * user to and back from, so the request never crosses the network boundary and
 * there is nothing for the IP allowlist or the signature check to rule on. The
 * log row says so rather than claiming a check that did not happen.
 *
 * The conversion inherits `is_test` from the click, so it is excluded from the
 * ledger, from earnings and from every report — `ConversionProcessor` enforces
 * that, not this class.
 */
final class TestConversion
{
    public function __construct(private readonly ConversionProcessor $processor) {}

    public function fire(OfferClick $click, Offer $offer): void
    {
        $clickId = (string) $click->click_id;

        $log = IncomingPostback::create([
            'network_id' => $offer->network_id,
            'driver' => $offer->network->driver,
            'method' => 'INTERNAL',
            'query' => [],
            'body' => ['source' => 'wall_test_offer', 'click_id' => $clickId],
            'headers' => [],
            'ip' => $click->ip,
            // Neither gate ran, because there was no inbound request to gate.
            // Recording them as passed would make this row indistinguishable
            // from a real network postback in the admin log.
            'ip_allowed' => null,
            'signature_valid' => null,
            'result' => IncomingPostbackResult::Error,
            'created_at' => now(),
        ]);

        // The click id doubles as the idempotency key: it is a UUID and it is
        // single-use, so a user who reloads the redirect target cannot bill the
        // same test twice — the unique index on (network_id, network_txid)
        // catches the second one exactly as it would a network's retry.
        $this->processor->record(
            new PostbackDTO(
                networkTxId: 'playndgain-test-'.$clickId,
                clickId: $clickId,
                payout: (float) $offer->payout,
                status: 'credited',
                offerExternalId: $offer->external_id,
                offerName: $offer->name,
                endUserId: $click->end_user_id,
                ip: $click->ip,
                raw: ['source' => 'wall_test_offer'],
            ),
            $offer->network,
            $log,
        );
    }
}
