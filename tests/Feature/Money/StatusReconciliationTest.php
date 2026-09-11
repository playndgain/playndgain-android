<?php

use App\Enums\ConversionStatus;
use App\Enums\IncomingPostbackResult;
use App\Enums\LedgerEntryType;
use App\Enums\OutgoingPostbackKind;
use App\Jobs\DispatchPublisherPostback;
use App\Models\Conversion;
use App\Models\IncomingPostback;
use App\Models\LedgerEntry;
use App\Models\Network;
use App\Models\User;
use App\Networks\NetworkDriverManager;
use App\Services\Money\ConversionProcessor;
use App\Services\Money\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PlatformTestDriver;

require_once __DIR__.'/MoneyFixtures.php';

/**
 * The reconciliation matrix — docs/04 §3.
 *
 * Before this existed the unique index swallowed every postback after the
 * first, so a partner that fires `pending` and then `approved` left the
 * conversion pending forever and only an admin could release it. Sixteen
 * combinations, and the ones that look alike are the ones that differ in
 * whether money moves.
 */

/**
 * Put a conversion into one of the four internal states, then fire the second
 * postback that has to be reconciled against it.
 */
function reconcileFixture(string $first, string $second, string $driver = 'fake'): Conversion
{
    $click = moneyFixture(app: ['postback_url' => 'https://pub.test/cb?tx={trans_id}'], driver: $driver);

    firePostback(postbackDto($click, status: $first), driver: $driver);
    firePostback(postbackDto($click, status: $second), driver: $driver)->assertOk();

    return Conversion::query()->sole();
}

it('walks the documented matrix', function (string $first, string $second, ConversionStatus $expected, ?string $reason, int $ledger) {
    Queue::fake();

    $conversion = reconcileFixture($first, $second);

    expect($conversion->status)->toBe($expected)
        ->and($conversion->rejected_reason)->toBe($reason)
        ->and(LedgerEntry::query()->count())->toBe($ledger)
        // Whatever the cell did, the audit column records what the network
        // last said — that is the whole reason it exists.
        ->and($conversion->upstream_status?->value)->toBe(match ($second) {
            'credited' => 'approved',
            default => $second,
        })
        ->and($conversion->upstream_updated_at)->not->toBeNull();
})->with([
    // existing pending
    'pending then pending' => ['pending', 'pending', ConversionStatus::Pending, null, 0],
    'pending then credited' => ['pending', 'credited', ConversionStatus::Approved, null, 1],
    'pending then rejected' => ['pending', 'rejected', ConversionStatus::Rejected, 'network_rejected', 0],
    // D2: a decline before any credit is a rejection, not a reversal.
    'pending then reversed' => ['pending', 'reversed', ConversionStatus::Rejected, 'network_rejected', 0],

    // existing approved
    'credited then pending' => ['credited', 'pending', ConversionStatus::Approved, null, 1],
    'credited then credited' => ['credited', 'credited', ConversionStatus::Approved, null, 1],
    // D7: `fake` does not declare `rejectedAfterCreditReverses`, so a late
    // rejection raises an alert instead of clawing money back.
    'credited then rejected' => ['credited', 'rejected', ConversionStatus::Approved, null, 1],
    'credited then reversed' => ['credited', 'reversed', ConversionStatus::Reversed, null, 2],

    // existing rejected by the network
    'rejected then pending' => ['rejected', 'pending', ConversionStatus::Rejected, 'network_rejected', 0],
    // D3: we never made a decision of our own about this one.
    'rejected then credited' => ['rejected', 'credited', ConversionStatus::Approved, null, 1],
    'rejected then rejected' => ['rejected', 'rejected', ConversionStatus::Rejected, 'network_rejected', 0],
    'rejected then reversed' => ['rejected', 'reversed', ConversionStatus::Rejected, 'network_rejected', 0],
]);

it('leaves a reversed conversion alone whatever arrives next', function (string $second) {
    Queue::fake();

    $click = moneyFixture();

    firePostback(postbackDto($click));
    firePostback(postbackDto($click, status: 'reversed'));
    firePostback(postbackDto($click, status: $second))->assertOk();

    $conversion = Conversion::query()->sole();

    expect($conversion->status)->toBe(ConversionStatus::Reversed)
        ->and(LedgerEntry::query()->count())->toBe(2)
        ->and(IncomingPostback::query()->orderByDesc('id')->first()->result)
        ->toBe(IncomingPostbackResult::Duplicate);
})->with(['pending', 'credited', 'rejected', 'reversed']);

it('charges back a late rejection only on a connector that means it by it', function () {
    Queue::fake();
    Log::spy();

    $click = moneyFixture(app: ['postback_url' => 'https://pub.test/cb'], driver: 'platform-test');

    // After the fixture, which registers the test double under whatever key it
    // was given. The connector metadata is the whole point of this test.
    app(NetworkDriverManager::class)->register(PlatformTestDriver::key(), PlatformTestDriver::class);

    firePostback(postbackDto($click), driver: 'platform-test');
    firePostback(postbackDto($click, status: 'rejected'), driver: 'platform-test')->assertOk();

    $conversion = Conversion::query()->sole();

    expect($conversion->status)->toBe(ConversionStatus::Reversed)
        ->and(LedgerEntry::query()->where('type', LedgerEntryType::Chargeback)->count())->toBe(1);

    Queue::assertPushed(
        DispatchPublisherPostback::class,
        fn (DispatchPublisherPostback $job): bool => $job->kind === OutgoingPostbackKind::Reversal,
    );
});

it('raises an alert instead of clawing money back on a connector that does not', function () {
    Queue::fake();
    Log::spy();

    $click = moneyFixture(app: ['postback_url' => 'https://pub.test/cb']);

    firePostback(postbackDto($click));
    firePostback(postbackDto($click, status: 'rejected'))->assertOk();

    $conversion = Conversion::query()->sole();

    // MyChips and Offery send `rejected` routinely and have never meant a
    // chargeback by it. Reading it as one would claw back paid conversions.
    expect($conversion->status)->toBe(ConversionStatus::Approved)
        ->and($conversion->upstream_status)->toBe(ConversionStatus::Rejected)
        ->and(LedgerEntry::query()->where('type', LedgerEntryType::Chargeback)->count())->toBe(0)
        ->and(IncomingPostback::query()->orderByDesc('id')->first()->result)
        ->toBe(IncomingPostbackResult::Duplicate);

    Log::shouldHaveReceived('critical')
        ->withArgs(fn (string $message): bool => $message === 'admin-alert: conversion.upstream_rejected_after_credit')
        ->once();

    // Partners retry until they get a 200, and some retry regardless. One
    // condition is one alert; the audit column is what remembers it was raised.
    firePostback(postbackDto($click, status: 'rejected'))->assertOk();
    firePostback(postbackDto($click, status: 'rejected'))->assertOk();

    Log::shouldHaveReceived('critical')
        ->withArgs(fn (string $message): bool => $message === 'admin-alert: conversion.upstream_rejected_after_credit')
        ->once();

    Queue::assertPushed(DispatchPublisherPostback::class, 1);
});

it('parks a conversion it cannot judge rather than filing it as a duplicate', function () {
    Queue::fake();

    $click = moneyFixture();

    firePostback(postbackDto($click, status: 'pending'));

    // `logs:prune` deletes clicks and `conversions.offer_click_id` is
    // `nullOnDelete`, so a conversion that sat pending past the retention
    // window loses the click it was judged from. The row is still a candidate
    // for approval and the network is saying it should be paid, but there is
    // nothing left to evaluate it against.
    Conversion::query()->sole()->update(['offer_click_id' => null]);

    firePostback(postbackDto($click, status: 'credited'))->assertOk();

    $log = IncomingPostback::query()->orderByDesc('id')->first();
    $conversion = Conversion::query()->sole();

    expect($log->result)->toBe(IncomingPostbackResult::Error)
        ->and($log->reject_reason)->toStartWith('no click to evaluate for network_txid')
        ->and($log->conversion_id)->toBe($conversion->id)
        ->and($conversion->status)->toBe(ConversionStatus::Pending)
        ->and(LedgerEntry::query()->count())->toBe(0);
});

it('writes exactly one earning and one credit callback when a pending row is approved late', function () {
    Queue::fake();

    $click = moneyFixture(app: ['postback_url' => 'https://pub.test/cb']);

    firePostback(postbackDto($click, status: 'pending'));
    firePostback(postbackDto($click, status: 'credited'))->assertOk();
    // The network retries the confirmation. It must not pay twice.
    firePostback(postbackDto($click, status: 'credited'))->assertOk();

    expect(LedgerEntry::query()->count())->toBe(1)
        ->and($click->publisher->fresh()->balance)->toBe('0.091000');

    Queue::assertPushed(DispatchPublisherPostback::class, 1);
    Queue::assertPushed(
        DispatchPublisherPostback::class,
        fn (DispatchPublisherPostback $job): bool => $job->kind === OutgoingPostbackKind::Credit,
    );
});

it('refuses to reconcile a conversion whose publisher has been deleted', function () {
    Queue::fake();

    $click = moneyFixture();

    firePostback(postbackDto($click));

    $click->publisher->delete();

    // `chargeBack()` resolves the publisher with `findOrFail`, so without this
    // gate a reversal answers the partner with a 500 rather than a logged
    // refusal an admin can act on.
    firePostback(postbackDto($click, status: 'reversed'))->assertOk();

    $log = IncomingPostback::query()->orderByDesc('id')->first();

    expect($log->result)->toBe(IncomingPostbackResult::Error)
        ->and($log->reject_reason)->toBe('app or publisher no longer exists')
        ->and(Conversion::query()->sole()->status)->toBe(ConversionStatus::Approved)
        ->and(LedgerEntry::query()->where('type', LedgerEntryType::Chargeback)->count())->toBe(0);
});

it('pays the amount on the confirming postback, not the placeholder on the first', function () {
    Queue::fake();

    $click = moneyFixture();

    // A partner that cannot price an event until it settles fires the pending
    // at zero. Paying the placeholder would owe the publisher nothing.
    firePostback(postbackDto($click, status: 'pending', payout: 0.0));
    firePostback(postbackDto($click, status: 'credited', payout: 0.42))->assertOk();

    $conversion = Conversion::query()->sole();

    expect($conversion->revenue)->toBe('0.420000')
        // 70% is the factory's rev share, snapshotted on the row at creation.
        ->and($conversion->payout)->toBe(Money::share('0.420000', $conversion->rev_share_applied))
        ->and(LedgerEntry::query()->sole()->amount)->toBe($conversion->payout);
});

it('keeps the rev share the conversion was quoted rather than the current one', function () {
    Queue::fake();

    $click = moneyFixture();

    firePostback(postbackDto($click, status: 'pending', payout: 0.10));

    $quoted = Conversion::query()->sole()->rev_share_applied;

    // The publisher is moved to a different share between the two fires. The
    // conversion keeps the deal it was made under.
    $click->publisher->fresh()->update(['rev_share' => '10.00']);

    firePostback(postbackDto($click, status: 'credited', payout: 0.10))->assertOk();

    expect(Conversion::query()->sole()->rev_share_applied)->toBe($quoted)
        ->and(Conversion::query()->sole()->payout)->toBe(Money::share('0.100000', $quoted));
});

it('measures the hold from the approval, not from the month the row arrived in', function () {
    Queue::fake();
    $this->travelTo(Carbon::parse('2026-01-20 10:00:00', 'UTC'));

    $click = moneyFixture();
    firePostback(postbackDto($click, status: 'pending'));

    expect(Conversion::query()->sole()->clears_at->eq(Carbon::parse('2026-02-01 00:00:00', 'UTC')))->toBeTrue();

    // The partner confirms three weeks later, in the next month. Keeping the
    // original date would make the earning withdrawable the instant it became
    // real, which is not what "clears on the 1st" means.
    $this->travelTo(Carbon::parse('2026-02-10 09:00:00', 'UTC'));
    firePostback(postbackDto($click, status: 'credited'))->assertOk();

    $clears = Carbon::parse('2026-03-01 00:00:00', 'UTC');

    expect(Conversion::query()->sole()->clears_at->eq($clears))->toBeTrue()
        ->and(LedgerEntry::query()->sole()->available_at->eq($clears))->toBeTrue();
});

it('does not let an upstream approval overturn a rejection our own rules made', function () {
    Queue::fake();

    $click = moneyFixture();

    firePostback(postbackDto($click, status: 'pending'));

    Conversion::query()->sole()->update([
        'status' => ConversionStatus::Rejected,
        'rejected_reason' => 'conversion_proxy_ip',
    ]);

    firePostback(postbackDto($click, status: 'credited'))->assertOk();

    $conversion = Conversion::query()->sole();
    $log = IncomingPostback::query()->orderByDesc('id')->first();

    expect($conversion->status)->toBe(ConversionStatus::Rejected)
        ->and($conversion->rejected_reason)->toBe('conversion_proxy_ip')
        ->and($conversion->upstream_status)->toBe(ConversionStatus::Approved)
        ->and(LedgerEntry::query()->count())->toBe(0)
        ->and($log->result)->toBe(IncomingPostbackResult::Rejected)
        ->and($log->reject_reason)->toBe('internal rejection stands');

    Queue::assertNothingPushed();
});

it('does not let an upstream approval overturn an admin rejection', function () {
    Queue::fake();

    $click = moneyFixture();
    $admin = User::factory()->admin()->create();

    firePostback(postbackDto($click, status: 'pending'));

    app(ConversionProcessor::class)->rejectPending(Conversion::query()->sole(), 'Datacenter IP', $admin);

    firePostback(postbackDto($click, status: 'credited'))->assertOk();

    expect(Conversion::query()->sole()->status)->toBe(ConversionStatus::Rejected)
        ->and(Conversion::query()->sole()->rejected_reason)->toBe('Datacenter IP')
        ->and(LedgerEntry::query()->count())->toBe(0);
});

it('never writes a ledger entry for a test conversion, however it is reconciled', function () {
    Queue::fake();

    $click = moneyFixture(click: ['is_test' => true]);

    firePostback(postbackDto($click, status: 'pending'));
    firePostback(postbackDto($click, status: 'credited'))->assertOk();

    expect(Conversion::query()->sole()->status)->toBe(ConversionStatus::Approved)
        ->and(LedgerEntry::query()->count())->toBe(0)
        ->and($click->publisher->fresh()->balance)->toBe('0.000000');

    firePostback(postbackDto($click, status: 'reversed'))->assertOk();

    expect(Conversion::query()->sole()->status)->toBe(ConversionStatus::Reversed)
        ->and(LedgerEntry::query()->count())->toBe(0);
});

it('names the conversion on every log row it reconciles, duplicates included', function () {
    Queue::fake();

    $click = moneyFixture();

    firePostback(postbackDto($click, status: 'pending'));
    firePostback(postbackDto($click, status: 'credited'));
    firePostback(postbackDto($click, status: 'credited'));

    $id = Conversion::query()->sole()->id;

    expect(IncomingPostback::query()->pluck('conversion_id')->all())->toBe([$id, $id, $id]);
});
