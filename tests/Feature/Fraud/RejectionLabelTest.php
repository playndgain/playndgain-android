<?php

use App\Enums\ConversionStatus;
use App\Enums\FraudAction;
use App\Models\Conversion;
use App\Models\IncomingPostback;
use App\Models\Network;
use App\Networks\DTO\PostbackDTO;
use App\Services\Money\ConversionProcessor;

require_once __DIR__.'/FraudFixtures.php';

/**
 * `conversions.rejected_reason` names why the money did not move. Getting it
 * wrong is not cosmetic — it is the column an admin reads when a publisher asks
 * why a conversion was refused, and a fraud rule key on a network's own
 * rejection sends them hunting a fraud problem that does not exist.
 *
 * The reason is carried on the processor between the rule evaluation and the
 * insert, so it has to be cleared once per postback rather than once per fraud
 * evaluation: a network-sent `rejected` never runs the rules at all, and would
 * otherwise inherit the previous conversion's key.
 */
function fireRaw(ConversionProcessor $processor, string $txId, string $status, mixed $click): void
{
    $network = Network::query()->findOrFail($click->network_id);

    $processor->record(
        new PostbackDTO(
            networkTxId: $txId,
            clickId: (string) $click->click_id,
            payout: 0.13,
            status: $status,
            endUserId: (string) $click->end_user_id,
            ip: (string) $click->ip,
        ),
        $network,
        IncomingPostback::query()->create([
            'network_id' => $network->id,
            'driver' => $network->driver,
            'method' => 'GET',
            'ip' => '127.0.0.1',
            'result' => 'accepted',
            'created_at' => now(),
        ]),
    );
}

it('does not lend a fraud rule key to the next network rejection', function () {
    fraudBoot();

    // impossible_speed, because it genuinely fires here: the click is created
    // in this test and the conversion lands milliseconds later, well inside the
    // window. A rule that cannot trigger would make this test vacuous.
    fraudRule('impossible_speed', action: FraudAction::Block, config: ['min_seconds' => 15]);

    $processor = app(ConversionProcessor::class);

    // A credited postback the pipeline blocks, then a network rejection through
    // the same instance — the exact ordering that leaks state.
    fireRaw($processor, 'NTX-BLOCKED', 'credited', fraudClick());
    fireRaw($processor, 'NTX-NETWORK', 'rejected', fraudClick());

    $rejection = Conversion::query()->withoutGlobalScopes()
        ->where('network_txid', 'NTX-NETWORK')->sole();

    expect($rejection->status)->toBe(ConversionStatus::Rejected)
        ->and($rejection->rejected_reason)->toBe('network_rejected');
});
