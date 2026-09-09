<?php

use App\Enums\ConversionStatus;
use App\Enums\IncomingPostbackResult;
use App\Enums\LedgerEntryType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/MoneyFixtures.php';

/**
 * Two workers holding the same reversal postback. Same shape as
 * ConcurrentDuplicateTest: the interleave that pays a publisher twice — or
 * here, debits them twice — is both workers reading before either wrote.
 *
 * The credit path is settled by the unique index. The reversal path changes
 * an existing row, so nothing but a locked re-read inside the transaction can
 * settle it: worker B's snapshot still says `approved` after A committed, and
 * a plain status check on that snapshot writes a second chargeback.
 */
const REVERSAL_RACE_TABLES = [
    'audit_logs',
    'incoming_postbacks',
    'outgoing_postbacks',
    'ledger_entries',
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

it('writes one chargeback when two workers reverse the same conversion', function () {
    Queue::fake();

    try {
        DB::setDefaultConnection('race_a');

        $click = moneyFixture();
        firePostback(postbackDto($click))->assertOk();

        $status = fn (string $connection) => DB::connection($connection)
            ->table('conversions')
            ->where('network_txid', 'NTX-100')
            ->value('status');

        // Worker B picks the reversal up and looks: still approved.
        DB::connection('race_b')->beginTransaction();
        $seenBefore = $status('race_b');

        // Worker A reverses and commits.
        firePostback(postbackDto($click, status: 'reversed'))->assertOk();

        // B's snapshot has not moved. A status check here passes and debits
        // the publisher a second time.
        $seenAfter = $status('race_b');

        DB::setDefaultConnection('race_b');
        firePostback(postbackDto($click, status: 'reversed'))->assertOk();
        DB::connection('race_b')->commit();

        DB::setDefaultConnection('race_a');

        $results = DB::connection('race_a')->table('incoming_postbacks')->orderBy('id')->pluck('result');
        $chargebacks = DB::connection('race_a')->table('ledger_entries')->where('type', LedgerEntryType::Chargeback->value)->count();

        expect($seenBefore)->toBe(ConversionStatus::Approved->value)
            ->and($seenAfter)->toBe(ConversionStatus::Approved->value)
            ->and($chargebacks)->toBe(1)
            ->and(DB::connection('race_a')->table('publishers')->value('balance'))->toBe('0.000000')
            ->and($results->all())->toBe([
                IncomingPostbackResult::Accepted->value,
                IncomingPostbackResult::Accepted->value,
                IncomingPostbackResult::Duplicate->value,
            ]);
    } finally {
        DB::setDefaultConnection('mysql');

        if (DB::connection('race_b')->transactionLevel() > 0) {
            DB::connection('race_b')->rollBack();
        }

        foreach (REVERSAL_RACE_TABLES as $table) {
            DB::connection('race_a')->table($table)->delete();
        }

        DB::purge('race_a');
        DB::purge('race_b');
    }
});
