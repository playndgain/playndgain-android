<?php

use App\Enums\IncomingPostbackResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/MoneyFixtures.php';

/**
 * The constraint is what is under test, so this runs on MySQL across two real
 * connections. There is no pcntl on Windows, so the fork is replaced by the
 * interleave a fork would produce — and by the only interleave that actually
 * pays a publisher twice: both workers read before either wrote.
 */

/** Tables this test commits into, child-first so the deletes need no FK dance. */
const RACE_TABLES = [
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
    // Two connections to the same test database, neither of them the one
    // RefreshDatabase is holding open.
    config([
        'database.connections.race_a' => config('database.connections.mysql'),
        'database.connections.race_b' => config('database.connections.mysql'),
    ]);
});

it('lets the unique index settle two workers holding the same postback', function () {
    Queue::fake();

    try {
        // RefreshDatabase keeps the default connection inside a transaction, and
        // an uncommitted row is invisible to a second connection. This one test
        // commits its fixtures on their own connection and clears up by hand.
        DB::setDefaultConnection('race_a');

        $click = moneyFixture();
        $dto = postbackDto($click);

        $conversions = fn (string $connection) => DB::connection($connection)
            ->table('conversions')
            ->where('network_txid', $dto->networkTxId)
            ->count();

        // Worker B picks the job up and looks: nothing there.
        DB::connection('race_b')->beginTransaction();
        $seenBefore = $conversions('race_b');

        // Worker A runs the whole pipeline and commits.
        firePostback($dto)->assertOk();

        // B's snapshot still shows nothing. An `exists()` guard would pass right
        // here and pay the publisher a second time.
        $seenAfter = $conversions('race_b');

        DB::setDefaultConnection('race_b');
        firePostback($dto)->assertOk();
        DB::connection('race_b')->commit();

        DB::setDefaultConnection('race_a');

        $results = DB::connection('race_a')->table('incoming_postbacks')->orderBy('id')->pluck('result');

        expect($seenBefore)->toBe(0)
            ->and($seenAfter)->toBe(0)
            ->and($conversions('race_a'))->toBe(1)
            ->and(DB::connection('race_a')->table('ledger_entries')->count())->toBe(1)
            ->and(DB::connection('race_a')->table('publishers')->value('balance'))->toBe('0.091000')
            ->and($results->all())->toBe([
                IncomingPostbackResult::Accepted->value,
                IncomingPostbackResult::Duplicate->value,
            ]);
    } finally {
        // RefreshDatabase rolls back "the default connection" by name at
        // teardown; leaving it pointed at a race connection leaks the open
        // transaction into the next test.
        DB::setDefaultConnection('mysql');

        // A failing assertion leaves worker B mid-transaction, and the cleanup
        // below would then sit on its locks until MySQL gives up.
        if (DB::connection('race_b')->transactionLevel() > 0) {
            DB::connection('race_b')->rollBack();
        }

        foreach (RACE_TABLES as $table) {
            DB::connection('race_a')->table($table)->delete();
        }

        DB::purge('race_a');
        DB::purge('race_b');
    }
});
