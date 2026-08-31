<?php

use App\Enums\PublisherStatus;
use App\Models\BannedEndUser;
use App\Models\Blacklist;
use App\Models\PaymentMethod;
use App\Models\Publisher;
use Database\Factories\BannedEndUserFactory;
use Database\Factories\BlacklistFactory;
use Illuminate\Support\Facades\Gate;

/**
 * A suspended account is read-only — docs/08 §1.
 *
 * Asserted at the policy rather than through the panel, because the policy is
 * what actually holds: a hidden button is a suggestion, and every
 * publisher-owned model routes its writes through PublisherOwnedPolicy. The
 * three models below are the ones that were writable while suspension was only
 * checked in AppResource::canCreate() and WithdrawalService.
 */
function ownedRows(Publisher $publisher): array
{
    return [
        'blacklist' => BlacklistFactory::new()->create(['publisher_id' => $publisher->id]),
        'banned end user' => BannedEndUserFactory::new()->create(['publisher_id' => $publisher->id]),
        'payment method' => PaymentMethod::create([
            'publisher_id' => $publisher->id,
            'type' => 'paypal',
            'details' => ['email' => 'payee@example.com'],
            'is_default' => true,
        ]),
    ];
}

it('keeps a suspended publisher out of every write', function () {
    $publisher = Publisher::factory()->create(['status' => PublisherStatus::Suspended]);
    $gate = Gate::forUser($publisher->user);

    foreach (ownedRows($publisher) as $label => $record) {
        expect($gate->allows('create', $record::class))->toBeFalse("create {$label}")
            ->and($gate->allows('update', $record))->toBeFalse("update {$label}")
            ->and($gate->allows('delete', $record))->toBeFalse("delete {$label}");
    }
});

it('still lets a suspended publisher read their own data', function () {
    $publisher = Publisher::factory()->create(['status' => PublisherStatus::Suspended]);
    $gate = Gate::forUser($publisher->user);

    // Suspension is not a lockout. They keep the reads behind the balance we
    // are holding, and the banner explaining the suspension sits on a page
    // they have to be able to open.
    foreach (ownedRows($publisher) as $label => $record) {
        expect($gate->allows('viewAny', $record::class))->toBeTrue("viewAny {$label}")
            ->and($gate->allows('view', $record))->toBeTrue("view {$label}");
    }
});

it('leaves an approved publisher writing normally', function () {
    $publisher = Publisher::factory()->create(['status' => PublisherStatus::Approved]);
    $gate = Gate::forUser($publisher->user);

    foreach (ownedRows($publisher) as $label => $record) {
        expect($gate->allows('create', $record::class))->toBeTrue("create {$label}")
            ->and($gate->allows('update', $record))->toBeTrue("update {$label}")
            ->and($gate->allows('delete', $record))->toBeTrue("delete {$label}");
    }
});

it('does not read the status off the wrong publisher', function () {
    $suspended = Publisher::factory()->create(['status' => PublisherStatus::Suspended]);
    $active = Publisher::factory()->create(['status' => PublisherStatus::Approved]);

    $theirs = BlacklistFactory::new()->create(['publisher_id' => $active->id]);

    // One suspended account must not freeze anybody else's panel, and it must
    // not gain a write on a row it does not own by being the one asked about.
    expect(Gate::forUser($active->user)->allows('update', $theirs))->toBeTrue()
        ->and(Gate::forUser($suspended->user)->allows('update', $theirs))->toBeFalse();

    expect(Blacklist::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(BannedEndUser::query()->withoutGlobalScopes()->count())->toBe(0);
});
