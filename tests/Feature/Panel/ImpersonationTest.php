<?php

use App\Http\Controllers\ImpersonationController;
use App\Models\AuditLog;
use App\Models\Publisher;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;

/**
 * The impersonation round trip — docs/07 §5.
 *
 * The banner is what stops an admin forgetting whose dashboard they are on, and
 * the exit route is the only way back. Both are asserted here because a
 * one-way impersonation is a stuck session.
 */
it('shows the banner and offers a way out while impersonating', function () {
    $admin = User::factory()->admin()->create();
    $publisher = Publisher::factory()->create();

    $this->actingAs($publisher->user);
    session([ImpersonationController::SESSION_KEY => $admin->id]);

    $this->get('/dashboard')
        ->assertSuccessful()
        ->assertSee('Stop impersonating')
        ->assertSee($publisher->user->name);
});

it('returns the admin to their own session and logs it', function () {
    $admin = User::factory()->admin()->create();
    $publisher = Publisher::factory()->create();

    $this->actingAs($publisher->user);
    session([ImpersonationController::SESSION_KEY => $admin->id]);

    $this->get('/impersonate/stop')->assertRedirect('/admin');

    expect(Auth::id())->toBe($admin->id)
        ->and(session()->has(ImpersonationController::SESSION_KEY))->toBeFalse()
        ->and(AuditLog::query()->where('action', 'publisher.impersonation_ended')->count())->toBe(1);
});

it('does not expose the exit route outside an impersonation session', function () {
    $publisher = Publisher::factory()->create();

    $this->actingAs($publisher->user);

    $this->get('/impersonate/stop')->assertNotFound();
});

it('signs out rather than staying as the publisher when the admin is gone', function () {
    $admin = User::factory()->admin()->create();
    $publisher = Publisher::factory()->create();

    $this->actingAs($publisher->user);
    session([ImpersonationController::SESSION_KEY => $admin->id]);

    $admin->delete();

    $this->get('/impersonate/stop')->assertRedirect('/admin/login');

    expect(Auth::check())->toBeFalse();
});

/**
 * AuthenticateSession pins a session to the password hash of whoever signed in.
 * A real login leaves that key behind; `actingAs` does not, which is why every
 * test above stayed green while impersonation logged admins out in a browser.
 */
it('survives the panel session guard on the way in', function () {
    // Distinct passwords on purpose: the factory default hashes once and hands
    // every user the same hash, which the guard below would happily accept.
    $admin = User::factory()->admin()->create(['password' => 'admin-secret']);
    $publisher = Publisher::factory()->create();
    $publisher->user->update(['password' => 'publisher-secret']);

    $this->actingAs($admin);
    session(['password_hash_web' => $admin->getAuthPassword()]);

    session([ImpersonationController::SESSION_KEY => $admin->id]);
    ImpersonationController::loginAs($publisher->user);

    $this->get('/dashboard')->assertSuccessful();

    expect(Auth::id())->toBe($publisher->user->id);
});

it('survives the panel session guard on the way out', function () {
    $admin = User::factory()->admin()->create(['password' => 'admin-secret']);
    $publisher = Publisher::factory()->create();
    $publisher->user->update(['password' => 'publisher-secret']);

    $this->actingAs($publisher->user);
    session([
        ImpersonationController::SESSION_KEY => $admin->id,
        'password_hash_web' => $publisher->user->getAuthPassword(),
    ]);

    $this->get('/impersonate/stop')->assertRedirect('/admin');

    // The admin panel may bounce an admin without 2FA to the set-up page, so
    // the assertion is on the session surviving, not on where it landed.
    $this->get('/admin');

    expect(Auth::id())->toBe($admin->id);
});

/**
 * Two resources offer impersonation and each wrote its own login call; fixing
 * one left the other logging admins out. Signing in as someone else has to go
 * through the one helper that re-pins the session, so the rule is checked
 * rather than remembered.
 */
it('routes every user swap through the one helper that re-pins the session', function () {
    $callers = collect(File::allFiles(app_path()))
        ->filter(fn ($file): bool => preg_match('/(Auth::|auth\(\)->)login\(/', $file->getContents()) === 1)
        ->map(fn ($file): string => str_replace('\\', '/', $file->getRelativePathname()))
        ->values();

    expect($callers->all())->toBe(['Http/Controllers/ImpersonationController.php']);
});

it('keeps the banner off a normal publisher session', function () {
    $publisher = Publisher::factory()->create();

    $this->actingAs($publisher->user);

    $this->get('/dashboard')->assertSuccessful()->assertDontSee('Stop impersonating');
});
