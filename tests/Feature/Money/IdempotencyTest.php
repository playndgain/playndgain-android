<?php

use App\Enums\IncomingPostbackResult;
use App\Models\Conversion;
use App\Models\IncomingPostback;
use App\Models\LedgerEntry;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/MoneyFixtures.php';

it('pays once when a network sends the same transaction twice', function () {
    Queue::fake();

    $click = moneyFixture();
    $dto = postbackDto($click);

    firePostback($dto)->assertOk();
    firePostback($dto)->assertOk();

    $logs = IncomingPostback::query()->orderBy('id')->get();

    expect(Conversion::query()->count())->toBe(1)
        ->and(LedgerEntry::query()->count())->toBe(1)
        ->and($click->publisher->fresh()->balance)->toBe('0.091000')
        ->and($logs)->toHaveCount(2)
        ->and($logs[0]->result)->toBe(IncomingPostbackResult::Accepted)
        ->and($logs[1]->result)->toBe(IncomingPostbackResult::Duplicate)
        // The duplicate names the conversion it was a duplicate *of*. Left null
        // it was the one log row an admin could not follow to the money, which
        // is the row they open when a partner disputes a count.
        ->and($logs[1]->conversion_id)->toBe(Conversion::query()->sole()->id);
});

it('keeps the idempotency key scoped to the network that sent it', function () {
    Queue::fake();

    $first = moneyFixture();
    $second = moneyFixture(driver: 'fake-two');

    firePostback(postbackDto($first))->assertOk();
    firePostback(postbackDto($second), driver: 'fake-two')->assertOk();

    // Same network_txid, two networks: the unique key is the pair, so both are
    // real conversions and neither is a duplicate.
    expect(Conversion::query()->count())->toBe(2)
        ->and(Conversion::query()->distinct()->count('network_txid'))->toBe(1)
        ->and(LedgerEntry::query()->count())->toBe(2);
});
