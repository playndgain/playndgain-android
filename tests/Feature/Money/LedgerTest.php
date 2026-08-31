<?php

use App\Enums\LedgerEntryType;
use App\Models\Conversion;
use App\Models\LedgerEntry;
use App\Services\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/MoneyFixtures.php';

it('splits revenue to six decimal places', function () {
    Queue::fake();

    $click = moneyFixture(publisher: ['rev_share' => 70.00]);

    firePostback(postbackDto($click, payout: 0.13))->assertOk();

    $conversion = Conversion::query()->sole();

    expect($conversion->revenue)->toBe('0.130000')
        ->and($conversion->payout)->toBe('0.091000')
        ->and($conversion->rev_share_applied)->toBe('70.00');
});

it('converts the payout into the app currency to four decimal places', function () {
    Queue::fake();

    $click = moneyFixture(app: ['currency_rate' => 1000.0000]);

    firePostback(postbackDto($click, payout: 0.13))->assertOk();

    expect(Conversion::query()->sole()->currency_amount)->toBe('91.0000');
});

it('keeps rev-share exact across payouts that would drift as floats', function (string $revenue, string $revShare, string $payout) {
    expect(Money::share($revenue, $revShare))->toBe($payout);
})->with([
    ['0.130000', '70.00', '0.091000'],
    ['0.003700', '70.00', '0.002590'],
    ['1.150000', '65.50', '0.753250'],
    ['0.000010', '70.00', '0.000007'],
    ['12.345678', '33.33', '4.114814'],
]);

it('snapshots the running balance on every entry and caches it on the publisher', function () {
    Queue::fake();

    $click = moneyFixture();

    firePostback(postbackDto($click, payout: 0.13, txId: 'NTX-1'))->assertOk();
    firePostback(postbackDto($click, payout: 0.20, txId: 'NTX-2'))->assertOk();

    $entries = LedgerEntry::query()->orderBy('id')->get();
    $publisher = $click->publisher->fresh();

    expect($entries[0]->type)->toBe(LedgerEntryType::Earning)
        ->and($entries[0]->amount)->toBe('0.091000')
        ->and($entries[0]->balance_after)->toBe('0.091000')
        ->and($entries[1]->amount)->toBe('0.140000')
        ->and($entries[1]->balance_after)->toBe('0.231000')
        ->and($publisher->balance)->toBe('0.231000')
        ->and($publisher->lifetime_earnings)->toBe('0.231000');
});

it('points the entry at the conversion that produced it', function () {
    Queue::fake();

    $click = moneyFixture();

    firePostback(postbackDto($click))->assertOk();

    $conversion = Conversion::query()->sole();
    $entry = LedgerEntry::query()->sole();

    expect($entry->reference_type)->toBe(Conversion::class)
        ->and($entry->reference_id)->toBe($conversion->id)
        ->and($entry->available_at?->timestamp)->toBe($conversion->clears_at?->timestamp)
        ->and($conversion->ledgerEntry)->not->toBeNull();
});

it('holds an earning until the start of the next calendar month', function () {
    Queue::fake();
    $this->travelTo(Carbon::parse('2026-01-15 14:30:00', 'UTC'));

    $click = moneyFixture();

    firePostback(postbackDto($click))->assertOk();

    $conversion = Conversion::query()->sole();
    $clears = Carbon::parse('2026-02-01 00:00:00', 'UTC');

    expect($conversion->clears_at->eq($clears))->toBeTrue()
        ->and(LedgerEntry::query()->sole()->available_at->eq($clears))->toBeTrue();
});

it('holds a conversion on the first of the month until the following month', function () {
    Queue::fake();
    $this->travelTo(Carbon::parse('2026-02-01 00:05:00', 'UTC'));

    $click = moneyFixture();

    firePostback(postbackDto($click))->assertOk();

    expect(Conversion::query()->sole()->clears_at->eq(Carbon::parse('2026-03-01 00:00:00', 'UTC')))->toBeTrue();
});

it('ignores a publisher hold_days override', function () {
    Queue::fake();
    $this->travelTo(Carbon::parse('2026-01-15 14:30:00', 'UTC'));

    $click = moneyFixture(publisher: ['hold_days' => 7]);

    firePostback(postbackDto($click))->assertOk();

    expect(Conversion::query()->sole()->clears_at->eq(Carbon::parse('2026-02-01 00:00:00', 'UTC')))->toBeTrue();
});

it('keeps the earning pending until the new month, then withdrawable', function () {
    Queue::fake();
    $this->travelTo(Carbon::parse('2026-01-15 14:30:00', 'UTC'));

    $click = moneyFixture();

    firePostback(postbackDto($click))->assertOk();

    $publisher = $click->publisher->fresh();

    expect($publisher->pendingBalance())->toBe('0.091000')
        ->and($publisher->withdrawableBalance())->toBe('0.000000');

    $this->travelTo(Carbon::parse('2026-02-01 00:00:00', 'UTC'));

    $publisher = $publisher->fresh();

    expect($publisher->pendingBalance())->toBe('0.000000')
        ->and($publisher->withdrawableBalance())->toBe('0.091000');
});
