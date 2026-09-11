<?php

use App\Enums\PublisherStatus;
use App\Filament\Admin\Resources\Publishers\PublisherResource;
use App\Models\Publisher;
use App\Models\User;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Config;

it('renders publisher identity as a user cell, flag, and status mark', function () {
    Config::set('playndgain.require_admin_2fa', false);

    $publisher = Publisher::factory()->create([
        'company_name' => 'Iberia Media',
        'country' => 'PT',
        'status' => PublisherStatus::Pending,
    ]);
    $publisher->user->update([
        'name' => 'Ana Costa',
        'email' => 'ana@iberia.test',
    ]);

    $this->actingAs(User::factory()->admin()->create([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_recovery_codes' => ['aaaa-bbbb'],
    ]));

    $this->get('/admin/publishers')
        ->assertSuccessful()
        ->assertSee('Ana Costa')
        ->assertSee('ana@iberia.test')
        ->assertSee('Iberia Media')
        ->assertSee('AC')
        ->assertSee('flags/pt.svg', escape: false)
        ->assertSee('pg-flag--solo', escape: false)
        ->assertSee('width="25"', escape: false)
        ->assertSee('Portugal')
        ->assertSee('ad-status--pending', escape: false)
        ->assertSee('Pending');
});

it('names a missing country instead of inventing a flag', function () {
    Config::set('playndgain.require_admin_2fa', false);

    Publisher::factory()->create([
        'country' => null,
        'status' => PublisherStatus::Approved,
    ]);

    $this->actingAs(User::factory()->admin()->create([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_recovery_codes' => ['aaaa-bbbb'],
    ]));

    $html = $this->get('/admin/publishers')->assertSuccessful()->getContent();

    expect($html)
        ->toContain('pg-flag-cell')
        ->not->toContain('flags/.svg');
});

it('labels each publisher status for the admin table', function (PublisherStatus $status, string $label, string $color, Heroicon $icon) {
    expect($status->getLabel())->toBe($label)
        ->and($status->getColor())->toBe($color)
        ->and($status->getIcon())->toBe($icon);
})->with([
    [PublisherStatus::Pending, 'Pending', 'warning', Heroicon::OutlinedClock],
    [PublisherStatus::Approved, 'Approved', 'success', Heroicon::OutlinedCheckBadge],
    [PublisherStatus::Suspended, 'Suspended', 'warning', Heroicon::OutlinedPauseCircle],
    [PublisherStatus::Banned, 'Banned', 'danger', Heroicon::OutlinedNoSymbol],
]);

it('resolves a stored country code to a human name', function () {
    expect(PublisherResource::countryName('PT'))->toBe('Portugal')
        ->and(PublisherResource::countryName('xx'))->toBe('Other');
});
