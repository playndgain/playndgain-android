<?php

use App\Models\Publisher;
use App\Models\Setting;
use App\Models\User;

/**
 * `settings.signups_enabled` is the switch an admin reaches for when a signup
 * flood arrives. It shipped seeded, documented in docs/01 §8, and read by
 * nothing — which is the worst state for a control of this kind, because the
 * admin panel would have shown it turning off while registrations kept
 * landing.
 */
it('serves the registration page while signups are enabled', function () {
    Setting::set('signups_enabled', '1');

    $this->get('/dashboard/register')->assertOk();
});

it('withdraws the registration page when signups are closed', function () {
    Setting::set('signups_enabled', '0');

    $this->get('/dashboard/register')->assertNotFound();
});

it('keeps login and password reset up while signups are closed', function (string $path) {
    Setting::set('signups_enabled', '0');

    // Closing the door to new accounts must not lock out the publishers who
    // already have one.
    $this->get($path)->assertOk();
})->with([
    '/dashboard/login',
    '/dashboard/password-reset/request',
]);

it('creates the user and the publisher together or not at all', function () {
    Setting::set('signups_enabled', '1');
    Setting::set('default_rev_share', '65.00');

    $this->post('/dashboard/register'); // no payload — the Livewire form owns the write

    // Guard against a half-registered account: a User with no Publisher is a
    // login that canAccessPanel rejects with no way back.
    expect(User::query()->count())->toBe(Publisher::query()->count());
});
