<?php

use App\Models\Publisher;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/MoneyFixtures.php';

it('passes when every cached balance matches the ledger', function () {
    Queue::fake();

    $click = moneyFixture();

    firePostback(postbackDto($click))->assertOk();

    $this->artisan('ledger:reconcile')->assertSuccessful();
});

it('reports drift and refuses to paper over it', function () {
    Queue::fake();

    $click = moneyFixture();

    firePostback(postbackDto($click))->assertOk();

    // A balance written without the entry that justifies it — the exact bug the
    // nightly run exists to catch.
    Publisher::query()->whereKey($click->publisher_id)->update(['balance' => '9.910000']);

    $this->artisan('ledger:reconcile')
        ->expectsOutputToContain('9.910000')
        ->assertFailed();

    // Drift is never auto-corrected: fixing the symptom nightly means never
    // finding the writer.
    expect($click->publisher->fresh()->balance)->toBe('9.910000');
});

it('sees a publisher with no entries at all as balanced', function () {
    Publisher::factory()->create();

    $this->artisan('ledger:reconcile')->assertSuccessful();
});
