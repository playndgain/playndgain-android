<?php

use App\Enums\LedgerEntryType;
use App\Models\LedgerEntry;
use App\Models\Publisher;
use App\Models\User;

function entryFor(Publisher $publisher): LedgerEntry
{
    return LedgerEntry::query()->create([
        'publisher_id' => $publisher->id,
        'type' => LedgerEntryType::Earning,
        'amount' => '0.091000',
        'balance_after' => '0.091000',
        'description' => 'test earning',
        'created_at' => now(),
    ]);
}

it('refuses to update a ledger entry', function () {
    $entry = entryFor(Publisher::factory()->create());

    expect(fn () => $entry->update(['amount' => '999.000000']))
        ->toThrow(LogicException::class, 'Ledger entries are immutable.');
});

it('refuses to delete a ledger entry', function () {
    $entry = entryFor(Publisher::factory()->create());

    expect(fn () => $entry->delete())
        ->toThrow(LogicException::class, 'Ledger entries cannot be deleted.');
});

it('denies every mutating policy action, including to admins', function () {
    $publisher = Publisher::factory()->create();
    $entry = entryFor($publisher);
    $admin = User::factory()->admin()->create();

    expect($admin->can('update', $entry))->toBeFalse()
        ->and($admin->can('delete', $entry))->toBeFalse()
        ->and($admin->can('create', LedgerEntry::class))->toBeFalse()
        ->and($publisher->user->can('update', $entry))->toBeFalse()
        ->and($publisher->user->can('delete', $entry))->toBeFalse();
});

it('still lets the owner and an admin read an entry', function () {
    $publisher = Publisher::factory()->create();
    $entry = entryFor($publisher);

    expect($publisher->user->can('view', $entry))->toBeTrue()
        ->and(User::factory()->admin()->create()->can('view', $entry))->toBeTrue();
});

it('keeps a ledger entry out of another publisher\'s reach', function () {
    $owner = Publisher::factory()->create();
    $other = Publisher::factory()->create();
    $entry = entryFor($owner);

    expect($other->user->can('view', $entry))->toBeFalse();

    $this->actingAs($other->user);

    expect(LedgerEntry::query()->count())->toBe(0);
});

it('stores money to six decimal places without drifting', function () {
    $entry = entryFor(Publisher::factory()->create());

    // $0.0037 payouts are normal in this business and they accumulate across
    // millions of rows. decimal(14,6) in, decimal(14,6) out.
    expect($entry->fresh()->amount)->toBe('0.091000');
});
