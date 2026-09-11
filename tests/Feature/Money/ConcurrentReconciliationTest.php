<?php

use App\Enums\ConversionStatus;
use App\Enums\LedgerEntryType;
use App\Enums\OutgoingPostbackKind;
use App\Jobs\DispatchPublisherPostback;
use App\Models\Conversion;
use App\Models\User;
use App\Services\Money\ConversionProcessor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/MoneyFixtures.php';

/**
 * Two workers holding postbacks about the same transaction, in the interleave
 * that costs money — both reading before either wrote.
 *
 * Same shape as `ConcurrentReversalTest`. The credit path is settled by the
 * unique index; reconciliation changes an existing row, so nothing but the
 * locked re-read inside the transaction can settle it. Worker B's snapshot
 * still says `pending` after A committed the approval, and a cell chosen from
 * that snapshot writes a second earning.
 */
const RECONCILE_RACE_TABLES = [
    'audit_logs',
    'incoming_postbacks',
    'outgoing_postbacks',
    'ledger_entries',
    'fraud_events',
    'conversions',
    'offer_clicks',
    'offers',
    'apps',
    'publishers',
    'users',
    'networks',
];

beforeEach(function () {
    config([
        'database.connections.race_a' => config('database.connections.mysql'),
        'database.connections.race_b' => config('database.connections.mysql'),
    ]);
});

afterEach(function () {
    DB::setDefaultConnection('mysql');

    if (DB::connection('race_b')->transactionLevel() > 0) {
        DB::connection('race_b')->rollBack();
    }

    foreach (RECONCILE_RACE_TABLES as $table) {
        DB::connection('race_a')->table($table)->delete();
    }

    DB::purge('race_a');
    DB::purge('race_b');
});

it('writes one earning when two workers confirm the same pending conversion', function () {
    Queue::fake();

    DB::setDefaultConnection('race_a');

    $click = moneyFixture(app: ['postback_url' => 'https://pub.test/cb']);
    firePostback(postbackDto($click, status: 'pending'))->assertOk();

    $status = fn (string $connection) => DB::connection($connection)
        ->table('conversions')
        ->where('network_txid', 'NTX-100')
        ->value('status');

    // Worker B picks the confirmation up and looks: still pending.
    DB::connection('race_b')->beginTransaction();
    $seenBefore = $status('race_b');

    // Worker A approves and commits.
    firePostback(postbackDto($click, status: 'credited'))->assertOk();

    // B's snapshot has not moved. Choosing the cell from it credits twice.
    $seenAfter = $status('race_b');

    DB::setDefaultConnection('race_b');
    firePostback(postbackDto($click, status: 'credited'))->assertOk();
    DB::connection('race_b')->commit();

    DB::setDefaultConnection('race_a');

    $earnings = DB::connection('race_a')->table('ledger_entries')
        ->where('type', LedgerEntryType::Earning->value)
        ->count();

    expect($seenBefore)->toBe(ConversionStatus::Pending->value)
        ->and($seenAfter)->toBe(ConversionStatus::Pending->value)
        ->and($earnings)->toBe(1)
        ->and(DB::connection('race_a')->table('publishers')->value('balance'))->toBe('0.091000');

    Queue::assertPushed(DispatchPublisherPostback::class, 1);
});

it('never charges back money it never credited when a credit and a decline race', function () {
    Queue::fake();

    DB::setDefaultConnection('race_a');

    $click = moneyFixture(app: ['postback_url' => 'https://pub.test/cb']);
    firePostback(postbackDto($click, status: 'pending'))->assertOk();

    // Worker B holds the decline and sees the row pending.
    DB::connection('race_b')->beginTransaction();
    DB::connection('race_b')->table('conversions')->where('network_txid', 'NTX-100')->value('status');

    // Worker A credits it and commits.
    firePostback(postbackDto($click, status: 'credited'))->assertOk();

    DB::setDefaultConnection('race_b');
    firePostback(postbackDto($click, status: 'reversed'))->assertOk();
    DB::connection('race_b')->commit();

    DB::setDefaultConnection('race_a');

    $entries = DB::connection('race_a')->table('ledger_entries')->orderBy('id')->pluck('type');
    $status = DB::connection('race_a')->table('conversions')->value('status');

    // Two outcomes are correct and no third one is: the decline either landed
    // on a pending row and rejected it with no money at all, or landed on the
    // credited row and reversed it — an earning and its chargeback, in that
    // order. A chargeback without an earning in front of it is the failure.
    if ($status === ConversionStatus::Reversed->value) {
        expect($entries->all())->toBe([
            LedgerEntryType::Earning->value,
            LedgerEntryType::Chargeback->value,
        ])
            ->and(DB::connection('race_a')->table('publishers')->value('balance'))->toBe('0.000000');
    } else {
        expect($status)->toBe(ConversionStatus::Rejected->value)
            ->and($entries->all())->toBe([]);
    }

    expect($entries->filter(fn (string $type): bool => $type === LedgerEntryType::Chargeback->value)->count())
        ->toBeLessThanOrEqual($entries->filter(fn (string $type): bool => $type === LedgerEntryType::Earning->value)->count());
});

it('never leaves an earning on a rejected row when an admin rejects during a credit', function () {
    Queue::fake();

    DB::setDefaultConnection('race_a');

    $click = moneyFixture(app: ['postback_url' => 'https://pub.test/cb']);
    $admin = User::factory()->admin()->create();

    firePostback(postbackDto($click, status: 'pending'))->assertOk();

    // The admin has the review queue open and the row reads pending.
    DB::connection('race_b')->beginTransaction();
    DB::connection('race_b')->table('conversions')->where('network_txid', 'NTX-100')->value('status');

    // The partner confirms in the meantime.
    firePostback(postbackDto($click, status: 'credited'))->assertOk();

    DB::setDefaultConnection('race_b');
    app(ConversionProcessor::class)->rejectPending(
        Conversion::query()->withoutGlobalScopes()->sole(),
        'Datacenter IP',
        $admin,
    );
    DB::connection('race_b')->commit();

    DB::setDefaultConnection('race_a');

    $status = DB::connection('race_a')->table('conversions')->value('status');
    $earnings = DB::connection('race_a')->table('ledger_entries')
        ->where('type', LedgerEntryType::Earning->value)
        ->count();

    // The rejection read a stale `pending`, but the locked re-check inside
    // `rejectPending()` sees the approval that landed first and stands down.
    // Without the lock the row would read `rejected` with the publisher's
    // earning still sitting in the ledger behind it.
    expect($status)->toBe(ConversionStatus::Approved->value)
        ->and($earnings)->toBe(1);

    Queue::assertPushed(
        DispatchPublisherPostback::class,
        fn (DispatchPublisherPostback $job): bool => $job->kind === OutgoingPostbackKind::Credit,
    );
});
