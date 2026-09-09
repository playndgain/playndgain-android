<?php

use App\Models\Conversion;
use App\Models\LedgerEntry;
use App\Models\OfferClick;
use Database\Factories\OfferClickFactory;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/MoneyFixtures.php';

it('deletes log rows past their window and keeps everything inside it', function () {
    config(['playndgain.log_retention' => ['offer_clicks' => 180]]);

    $old = OfferClickFactory::new()->create(['created_at' => now()->subDays(181)]);
    $fresh = OfferClickFactory::new()->create(['created_at' => now()->subDays(179)]);

    $this->artisan('logs:prune')->assertSuccessful();

    expect(OfferClick::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(OfferClick::query()->whereKey($fresh->id)->exists())->toBeTrue();
});

it('walks the whole backlog rather than one batch of it', function () {
    // Four rows over a batch size of one: a prune that ran a single statement
    // and stopped would leave three, and the table would grow forever at a rate
    // the operator could not see.
    config([
        'playndgain.log_retention' => ['offer_clicks' => 30],
        'playndgain.log_prune_batch' => 1,
        'playndgain.log_prune_pause_ms' => 0,
    ]);

    OfferClickFactory::new()->count(4)->create(['created_at' => now()->subDays(60)]);

    $this->artisan('logs:prune')->assertSuccessful();

    expect(OfferClick::query()->count())->toBe(0);
});

it('reports without deleting on a dry run', function () {
    config(['playndgain.log_retention' => ['offer_clicks' => 30]]);

    OfferClickFactory::new()->create(['created_at' => now()->subDays(60)]);

    $this->artisan('logs:prune --dry-run')
        ->expectsOutputToContain('would delete 1')
        ->assertSuccessful();

    expect(OfferClick::query()->count())->toBe(1);
});

it('never touches a money table, however old the rows are', function () {
    Queue::fake();

    $click = moneyFixture();
    firePostback(postbackDto($click))->assertOk();

    // Age the whole money path past every retention window in the config.
    $ancient = now()->subYears(5);
    Conversion::query()->update(['created_at' => $ancient]);
    LedgerEntry::query()->update(['created_at' => $ancient]);

    $this->artisan('logs:prune')->assertSuccessful();

    expect(Conversion::query()->count())->toBe(1)
        ->and(LedgerEntry::query()->count())->toBe(1);
});

it('keeps the money tables out of the retention map', function () {
    // The guard against deleting money history is that these table names are
    // not in the config. There is no second guard, so the config is the test.
    expect(array_keys(config('playndgain.log_retention')))
        ->not->toContain('conversions')
        ->not->toContain('ledger_entries')
        ->not->toContain('daily_stats');
});
