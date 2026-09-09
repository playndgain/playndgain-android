<?php

use App\Models\Publisher;
use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Facades\Filament;
use Filament\Panel;

it('serves both panel login pages', function (string $path) {
    $this->get($path)->assertOk();
})->with([
    '/admin/login',
    '/dashboard/login',
]);

it('keeps each role out of the other panel', function () {
    $admin = User::factory()->admin()->create();
    $publisher = Publisher::factory()->create();

    // An admin reaches both, so they can switch panels without signing out.
    // A publisher reaches exactly one. Tenancy is enforced by PublisherScope
    // keying on the panel, not by keeping admins out of the door.
    expect($admin->canAccessPanel(Filament::getPanel('admin')))->toBeTrue()
        ->and($admin->canAccessPanel(Filament::getPanel('publisher')))->toBeTrue()
        ->and($publisher->user->canAccessPanel(Filament::getPanel('publisher')))->toBeTrue()
        ->and($publisher->user->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();
});

it('offers multi-factor authentication on both panels but requires it only on admin', function () {
    expect(Filament::getPanel('admin')->hasMultiFactorAuthentication())->toBeTrue()
        ->and(Filament::getPanel('admin')->isMultiFactorAuthenticationRequired())->toBeTrue()
        ->and(Filament::getPanel('publisher')->hasMultiFactorAuthentication())->toBeTrue()
        ->and(Filament::getPanel('publisher')->isMultiFactorAuthenticationRequired())->toBeFalse();
});

it('defaults the admin 2FA requirement to on when the variable is absent', function () {
    // The toggle exists so local dev is not gated behind an authenticator app.
    // Its default has to stay ON, or a deploy that simply never sets the
    // variable silently ships an admin panel with no second factor. Read with
    // the variable unset rather than off config(), which the test environment
    // pins.
    $previous = getenv('PLAYNDGAIN_REQUIRE_ADMIN_2FA');

    putenv('PLAYNDGAIN_REQUIRE_ADMIN_2FA');
    unset($_ENV['PLAYNDGAIN_REQUIRE_ADMIN_2FA'], $_SERVER['PLAYNDGAIN_REQUIRE_ADMIN_2FA']);

    try {
        $config = require config_path('playndgain.php');

        expect($config['require_admin_2fa'])->toBeTrue();
    } finally {
        if ($previous !== false) {
            putenv("PLAYNDGAIN_REQUIRE_ADMIN_2FA={$previous}");
            $_ENV['PLAYNDGAIN_REQUIRE_ADMIN_2FA'] = $previous;
            $_SERVER['PLAYNDGAIN_REQUIRE_ADMIN_2FA'] = $previous;
        }
    }
});

it('lets the config turn the admin 2FA requirement off, and only that', function () {
    config()->set('playndgain.require_admin_2fa', false);

    // Built fresh rather than read off the booted panel, because the booted one
    // was configured before this test changed anything.
    $panel = (new AdminPanelProvider(app()))->panel(Panel::make());

    expect($panel->isMultiFactorAuthenticationRequired())->toBeFalse()
        ->and($panel->hasMultiFactorAuthentication())->toBeTrue();
});

it('requires a verified address on the publisher panel and not on the admin one', function () {
    // Publishers self-register, so the address is the only thing tying an
    // application to a person before an admin reads it. Admins are seeded and
    // never see a verification route at all.
    expect(Filament::getPanel('publisher')->hasEmailVerification())->toBeTrue()
        ->and(Filament::getPanel('publisher')->isEmailVerificationRequired())->toBeTrue()
        ->and(Filament::getPanel('admin')->hasEmailVerification())->toBeFalse();
});

it('parks an unverified publisher on the prompt rather than in a loop', function () {
    $publisher = Publisher::factory()->create();
    $publisher->user->forceFill(['email_verified_at' => null])->save();

    $this->actingAs($publisher->user);

    // Gated, and the page it is gated onto is reachable — the failure mode
    // worth testing here is not "blocked" but "blocked and bounced forever".
    $this->get('/dashboard')->assertRedirect('/dashboard/email-verification/prompt');
    $this->get('/dashboard/email-verification/prompt')->assertOk();
});

it('redirects an anonymous visitor away from both dashboards', function (string $path) {
    $this->get($path)->assertRedirect();
})->with([
    '/admin',
    '/dashboard',
]);
