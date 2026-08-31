<?php

use App\Enums\ConversionStatus;
use App\Enums\IncomingPostbackResult;
use App\Enums\LedgerEntryType;
use App\Enums\NetworkStatus;
use App\Enums\OutgoingPostbackKind;
use App\Models\Conversion;
use App\Models\IncomingPostback;
use App\Models\LedgerEntry;
use App\Models\Network;
use App\Models\OfferClick;
use App\Models\OutgoingPostback;
use App\Networks\Drivers\FakeNetworkDriver;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/MoneyFixtures.php';

/**
 * The repair for the chargebacks the old not-active gate refused.
 *
 * Ayet Studios' API key expired, the network went to `disabled`, and 133
 * chargebacks for conversions already credited came back 403 until Ayet gave
 * up retrying. The ingress gate is fixed; nothing is coming back to re-deliver
 * those, so they are applied from the log rows they left behind.
 *
 * The rows are written directly rather than fired through the controller —
 * the controller no longer produces them, which is the point of the fix.
 */
function inactiveRejection(OfferClick $click, string $reason = 'network is not active'): IncomingPostback
{
    return IncomingPostback::create([
        'driver' => 'fake',
        'network_id' => $click->network_id,
        'method' => 'GET',
        'query' => ['transaction_id' => 'NTX-100', 'is_chargeback' => '1'],
        'body' => [],
        'headers' => [],
        'ip' => '127.0.0.1',
        'result' => IncomingPostbackResult::Rejected,
        'reject_reason' => $reason,
        'ip_allowed' => true,
        'signature_valid' => true,
        'created_at' => now(),
    ]);
}

/** What the driver hands back when the command re-parses those stored rows. */
function replayParsesAsReversal(OfferClick $click): void
{
    FakeNetworkDriver::shouldParsePostback(postbackDto($click, 'reversed'));
}

it('applies a chargeback that was refused while the network was off', function () {
    $click = moneyFixture();

    firePostback(postbackDto($click))->assertOk();
    Network::query()->where('driver', 'fake')->update(['status' => NetworkStatus::Disabled]);

    $log = inactiveRejection($click);
    replayParsesAsReversal($click);

    $this->artisan('postbacks:replay-chargebacks')->assertSuccessful();

    $conversion = Conversion::query()->sole();

    expect($conversion->status)->toBe(ConversionStatus::Reversed)
        ->and($conversion->reversed_at)->not->toBeNull()
        ->and(LedgerEntry::query()->where('type', LedgerEntryType::Chargeback)->count())->toBe(1)
        ->and($log->fresh()->result)->toBe(IncomingPostbackResult::Accepted)
        ->and($log->fresh()->reject_reason)->toBeNull();
});

it('moves the money once however many retries of the same chargeback it replays', function () {
    $click = moneyFixture();

    firePostback(postbackDto($click))->assertOk();
    Network::query()->where('driver', 'fake')->update(['status' => NetworkStatus::Disabled]);

    // Ayet sent each chargeback eleven times. Every one of them is a row.
    foreach (range(1, 3) as $ignored) {
        inactiveRejection($click);
    }

    replayParsesAsReversal($click);

    $this->artisan('postbacks:replay-chargebacks')->assertSuccessful();

    expect(LedgerEntry::query()->where('type', LedgerEntryType::Chargeback)->count())->toBe(1)
        ->and(IncomingPostback::query()->where('result', IncomingPostbackResult::Duplicate)->count())->toBe(2);
});

it('leaves a refused credit refused', function () {
    $click = moneyFixture();

    Network::query()->where('driver', 'fake')->update(['status' => NetworkStatus::Disabled]);
    firePostback(postbackDto($click))->assertForbidden();

    $this->artisan('postbacks:replay-chargebacks')->assertSuccessful();

    // A disabled network still must not earn — the whole point of the gate.
    expect(Conversion::query()->count())->toBe(0)
        ->and(IncomingPostback::query()->sole()->result)->toBe(IncomingPostbackResult::Rejected);
});

it('never touches a postback refused by a gate that authenticates the sender', function () {
    $click = moneyFixture();

    firePostback(postbackDto($click))->assertOk();
    inactiveRejection($click, 'invalid signature');
    replayParsesAsReversal($click);

    $this->artisan('postbacks:replay-chargebacks')->assertSuccessful();

    expect(Conversion::query()->sole()->status)->toBe(ConversionStatus::Approved)
        ->and(LedgerEntry::query()->where('type', LedgerEntryType::Chargeback)->count())->toBe(0);
});

it('keeps the reason a replay could not match', function () {
    $click = moneyFixture();

    Network::query()->where('driver', 'fake')->update(['status' => NetworkStatus::Disabled]);

    $log = inactiveRejection($click);
    FakeNetworkDriver::shouldParsePostback(postbackDto($click, 'reversed', txId: 'NTX-GONE'));

    $this->artisan('postbacks:replay-chargebacks')->assertSuccessful();

    // Clearing this one would hide the only thing the row now has to say.
    expect($log->fresh()->result)->toBe(IncomingPostbackResult::Error)
        ->and($log->fresh()->reject_reason)->toBe('no conversion for network_txid NTX-GONE');
});

it('moves the money without calling the publisher when asked', function () {
    Http::fake();

    $click = moneyFixture(['postback_url' => 'https://publisher.test/cb?trans={trans_id}']);

    firePostback(postbackDto($click))->assertOk();
    Network::query()->where('driver', 'fake')->update(['status' => NetworkStatus::Disabled]);

    inactiveRejection($click);
    replayParsesAsReversal($click);

    // The credit callback fired when the conversion landed; the reversal's is
    // the one being held back for a human to send.
    $this->artisan('postbacks:replay-chargebacks', ['--no-callbacks' => true])->assertSuccessful();

    expect(Conversion::query()->sole()->status)->toBe(ConversionStatus::Reversed)
        ->and(LedgerEntry::query()->where('type', LedgerEntryType::Chargeback)->count())->toBe(1)
        ->and(OutgoingPostback::query()->where('kind', OutgoingPostbackKind::Reversal)->count())->toBe(0)
        ->and(OutgoingPostback::query()->where('kind', OutgoingPostbackKind::Credit)->count())->toBe(1);
});

it('writes nothing on a dry run', function () {
    $click = moneyFixture();

    firePostback(postbackDto($click))->assertOk();
    Network::query()->where('driver', 'fake')->update(['status' => NetworkStatus::Disabled]);

    $log = inactiveRejection($click);
    replayParsesAsReversal($click);

    $this->artisan('postbacks:replay-chargebacks', ['--dry-run' => true])
        ->expectsOutputToContain('reversed')
        ->assertSuccessful();

    expect(Conversion::query()->sole()->status)->toBe(ConversionStatus::Approved)
        ->and(LedgerEntry::query()->where('type', LedgerEntryType::Chargeback)->count())->toBe(0)
        ->and($log->fresh()->result)->toBe(IncomingPostbackResult::Rejected);
});
