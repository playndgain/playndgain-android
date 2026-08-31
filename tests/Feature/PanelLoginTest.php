<?php

use App\Enums\PublisherStatus;
use App\Filament\Publisher\Auth\Login as PublisherLogin;
use App\Models\Publisher;
use App\Models\User;
use App\Support\Identity;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * The Phase 0 gate is "both panels log in" — not "both login pages return 200".
 * These drive the real Filament login component on each panel.
 */
it('logs an admin into the admin panel', function () {
    Filament::setCurrentPanel('admin');

    $admin = User::factory()->admin()->create();

    Livewire::test(Login::class)
        ->fillForm([
            'email' => $admin->email,
            'password' => 'password',
        ])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    $this->assertAuthenticatedAs($admin);
});

it('logs a publisher into the publisher panel', function () {
    Filament::setCurrentPanel('publisher');

    $publisher = Publisher::factory()->create();

    Livewire::test(PublisherLogin::class)
        ->fillForm([
            'email' => $publisher->user->email,
            'password' => 'password',
        ])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    $this->assertAuthenticatedAs($publisher->user);
});

it('refuses a banned publisher at the publisher panel login', function () {
    Filament::setCurrentPanel('publisher');

    $publisher = Publisher::factory()->create(['status' => PublisherStatus::Banned]);

    Livewire::test(PublisherLogin::class)
        ->fillForm([
            'email' => $publisher->user->email,
            'password' => 'password',
        ])
        ->call('authenticate')
        ->assertHasFormErrors(['email'])
        ->assertSee('This account has been banned')
        ->assertSee(Identity::supportEmail());

    $this->assertGuest();
});

it('does not reveal a ban when the password is wrong', function () {
    Filament::setCurrentPanel('publisher');

    $publisher = Publisher::factory()->create(['status' => PublisherStatus::Banned]);

    Livewire::test(PublisherLogin::class)
        ->fillForm([
            'email' => $publisher->user->email,
            'password' => 'wrong-password',
        ])
        ->call('authenticate')
        ->assertHasFormErrors(['email'])
        ->assertDontSee('This account has been banned');

    $this->assertGuest();
});

it('refuses a publisher at the admin panel login', function () {
    Filament::setCurrentPanel('admin');

    $publisher = Publisher::factory()->create();

    Livewire::test(Login::class)
        ->fillForm([
            'email' => $publisher->user->email,
            'password' => 'password',
        ])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    $this->assertGuest();
});

it('admits an admin at the publisher panel login', function () {
    Filament::setCurrentPanel('publisher');

    $admin = User::factory()->admin()->create();

    // Admins reach both panels so they can switch without signing out. What
    // stops this being a tenancy hole is PublisherScope, which keys on the
    // panel — the admin gets in and sees no publisher's rows. Asserted in
    // tests/Feature/Panel/PanelSwitchingTest.php.
    Livewire::test(PublisherLogin::class)
        ->fillForm([
            'email' => $admin->email,
            'password' => 'password',
        ])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    $this->assertAuthenticatedAs($admin);
});

it('sends an admin without confirmed 2FA to the set-up page, not the dashboard', function () {
    $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => null]);

    $this->actingAs($admin);

    $this->get('/admin')->assertRedirect(
        Filament::getPanel('admin')->getSetUpRequiredMultiFactorAuthenticationUrl()
    );
});
