<?php

use App\Enums\LedgerEntryType;
use App\Models\Conversion;
use App\Models\LedgerEntry;

/**
 * RefreshDatabase already ran this migration against empty tables, so the
 * held rows are seeded afterwards and the migration is invoked by hand.
 */
beforeEach(function () {
    $this->freezeTime();
    $this->migration = require database_path('migrations/2026_09_01_000001_release_held_earnings.php');
});

it('releases still-held earnings immediately and leaves everything else alone', function () {
    $held = Conversion::factory()->create(['clears_at' => now()->addDays(10)]);
    $cleared = Conversion::factory()->create(['clears_at' => now()->subDay()]);

    $heldEntry = LedgerEntry::query()->create([
        'publisher_id' => $held->publisher_id,
        'type' => LedgerEntryType::Earning,
        'amount' => '1.000000',
        'balance_after' => '1.000000',
        'available_at' => now()->addDays(10),
        'created_at' => now(),
    ]);
    $clearedEntry = LedgerEntry::query()->create([
        'publisher_id' => $cleared->publisher_id,
        'type' => LedgerEntryType::Earning,
        'amount' => '2.000000',
        'balance_after' => '2.000000',
        'available_at' => now()->subDay(),
        'created_at' => now()->subDay(),
    ]);
    $chargeback = LedgerEntry::query()->create([
        'publisher_id' => $held->publisher_id,
        'type' => LedgerEntryType::Chargeback,
        'amount' => '-1.000000',
        'balance_after' => '0.000000',
        'available_at' => now()->addDays(10),
        'created_at' => now(),
    ]);

    $this->migration->up();

    expect($held->fresh()->clears_at->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and($cleared->fresh()->clears_at->toDateTimeString())->toBe(now()->subDay()->toDateTimeString())
        ->and($heldEntry->fresh()->available_at->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and($clearedEntry->fresh()->available_at->toDateTimeString())->toBe(now()->subDay()->toDateTimeString())
        ->and($chargeback->fresh()->available_at->toDateTimeString())->toBe(now()->addDays(10)->toDateTimeString());
});
