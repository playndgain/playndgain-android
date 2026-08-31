<?php

use App\Enums\ConversionStatus;
use App\Models\Conversion;
use App\Models\OfferClick;
use Database\Factories\OfferClickFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/MoneyFixtures.php';

it('passes when every conversion matches its own revenue, rev-share and ledger', function () {
    Queue::fake();

    $click = moneyFixture(publisher: ['rev_share' => 70.00]);

    firePostback(postbackDto($click, payout: 0.13))->assertOk();

    $this->artisan('money:audit')->assertSuccessful();
});

it('catches a payout that is not the publisher rev-share of the revenue', function () {
    Queue::fake();

    $click = moneyFixture(publisher: ['rev_share' => 70.00]);

    firePostback(postbackDto($click, payout: 0.13))->assertOk();

    // $0.13 at 70% is $0.091. Someone credited the full revenue instead — the
    // exact class of bug a balance-vs-ledger check cannot see, because the
    // ledger entry agrees with the wrong number.
    Conversion::query()->withoutGlobalScopes()->update(['payout' => '0.130000']);

    $this->artisan('money:audit')
        ->expectsOutputToContain('0.091000')
        ->assertFailed();
});

it('catches an approved conversion the ledger never credited', function () {
    Queue::fake();

    $click = moneyFixture();

    firePostback(postbackDto($click))->assertOk();

    // Raw delete: ledger entries are immutable through the model, which is the
    // point. This is the shape of "money code wrote the conversion and not the
    // entry" — `ledger:reconcile` still passes, because a zero balance matches
    // a zero ledger.
    DB::table('ledger_entries')->delete();
    DB::table('publishers')->update(['balance' => '0.000000', 'lifetime_earnings' => '0.000000']);

    $this->artisan('ledger:reconcile')->assertSuccessful();
    $this->artisan('money:audit')->expectsOutputToContain('ledger')->assertFailed();
});

it('expects no ledger entry for a pending, rejected or test conversion', function () {
    Queue::fake();

    $click = moneyFixture();

    // `networks.driver` is unique, so the three cases share one fixture and
    // differ only in the click.
    $sibling = fn (array $extra = []): OfferClick => OfferClickFactory::new()->create([
        'app_id' => $click->app_id,
        'publisher_id' => $click->publisher_id,
        'offer_id' => $click->offer_id,
        'network_id' => $click->network_id,
        ...$extra,
    ]);

    firePostback(postbackDto($click, status: 'pending', txId: 'NTX-P'))->assertOk();
    firePostback(postbackDto($sibling(), status: 'rejected', txId: 'NTX-R'))->assertOk();
    firePostback(postbackDto($sibling(['is_test' => true]), txId: 'NTX-T'))->assertOk();

    expect(DB::table('ledger_entries')->count())->toBe(0);

    $this->artisan('money:audit')->assertSuccessful();
});

it('expects a reversed conversion to net to zero', function () {
    Queue::fake();

    $click = moneyFixture();

    firePostback(postbackDto($click))->assertOk();
    firePostback(postbackDto($click, status: 'reversed'))->assertOk();

    expect(Conversion::query()->withoutGlobalScopes()->sole()->status)->toBe(ConversionStatus::Reversed);

    $this->artisan('money:audit')->assertSuccessful();

    // The earning left behind without its chargeback is a publisher keeping
    // money the advertiser took back.
    DB::table('ledger_entries')->where('type', 'chargeback')->delete();

    $this->artisan('money:audit')->assertFailed();
});
