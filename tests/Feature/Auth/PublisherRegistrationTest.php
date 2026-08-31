<?php

use App\Enums\AppStatus;
use App\Enums\PublisherStatus;
use App\Enums\UserRole;
use App\Filament\Publisher\Auth\Register;
use App\Filament\Publisher\Pages\Welcome;
use App\Models\App;
use App\Models\Setting;
use App\Models\User;
use Filament\Auth\Notifications\VerifyEmail;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('publisher');
});

/** @return array<string, mixed> */
function signup(array $overrides = []): array
{
    return array_merge([
        'name' => 'Ziad Talaat',
        'email' => 'ziad@justrewards.app',
        'password' => 'correct-horse-9-battery',
        'country' => 'EG',
        'traffic_type' => 'Rewards app',
        'monthly_actives' => '10,000 – 100,000',
        'audience' => 'Rewards app for Egyptian and Gulf users, mostly Android, organic from TikTok.',
        'app_name' => 'Just Rewards',
        'app_url' => 'https://justrewards.app',
        'platform' => 'android',
        'currency_name' => 'Coins',
        'currency_rate' => 1000,
        'terms' => true,
        'incentivised' => true,
    ], $overrides);
}

it('serves the registration page', function () {
    $this->get('/dashboard/register')
        ->assertOk()
        ->assertSee('Apply as a publisher')
        ->assertSee('Every cent that reaches your users', false)
        ->assertDontSee('Your revenue share')
        ->assertDontSee('of every payout is yours');
});

it('creates the user, a pending publisher and a pending first app in one go', function () {
    Setting::set('default_rev_share', 65);

    Livewire::test(Register::class)
        ->fillForm(signup())
        ->call('register')
        ->assertHasNoFormErrors();

    $user = User::query()->where('email', 'ziad@justrewards.app')->sole();
    $publisher = $user->publisher;
    $app = $publisher->apps()->sole();

    // The role is what canAccessPanel checks; without it registration would
    // create an account that cannot reach the panel it just signed up for.
    expect($user->role)->toBe(UserRole::Publisher)
        ->and($publisher->status)->toBe(PublisherStatus::Pending)
        ->and($publisher->approved_at)->toBeNull()
        ->and($publisher->country)->toBe('EG')
        ->and((float) $publisher->rev_share)->toBe(65.0)
        ->and($publisher->pub_id)->toBeGreaterThanOrEqual(10000)
        // All three traffic answers survive into the column the admin reads.
        ->and($publisher->traffic_source)
        ->toContain('Rewards app', '10,000 – 100,000', 'organic from TikTok')
        ->and($app->status)->toBe(AppStatus::Pending)
        ->and($app->approved_at)->toBeNull()
        ->and($app->name)->toBe('Just Rewards')
        ->and($app->currency_name)->toBe('Coins')
        ->and((float) $app->currency_rate)->toBe(1000.0)
        ->and($app->app_id)->toBeGreaterThanOrEqual(10000)
        ->and($app->secret_key)->not->toBeEmpty();
});

it('reads the answers back on the review step, and prices the currency rate', function () {
    Livewire::test(Register::class)
        ->fillForm(signup())
        // The review list resolves stored values to what the applicant typed —
        // 'EG' is a column value, 'Egypt' is what they picked.
        ->assertSee('Egypt')
        ->assertSee('Just Rewards')
        ->assertSee('1,000 Coins per $1')
        // The line that prevents the most common setup error: 50c at 70% share
        // over 1000 units per $1. Wrong here means every user is credited wrong.
        ->assertSee('credits your user 350 Coins');
});

/**
 * docs/08 §15: the completion screen shows the app ID *and the secret key*.
 *
 * It is the only moment the secret exists in plaintext outside the database —
 * the Integration tab masks it, and the sole other route to one is Regenerate,
 * which invalidates whatever the publisher has already embedded.
 */
it('hands over the app ID and the secret once, on the screen that created them', function () {
    $component = Livewire::test(Register::class)
        ->fillForm(signup())
        ->call('register')
        ->assertHasNoFormErrors();

    $app = App::query()->sole();

    $component->assertRedirect(Welcome::getUrl());

    $this->actingAs($app->publisher->user)
        ->get(Welcome::getUrl())
        ->assertOk()
        ->assertSee((string) $app->app_id)
        ->assertSee($app->secret_key)
        ->assertSee('shown once', false);

    // Once means once: a reload has nothing left to show and says so by
    // leaving rather than by rendering a blank card.
    $this->get(Welcome::getUrl())->assertRedirect('/dashboard');
});

it('reaches the credential screen without a verified address', function () {
    // Registration and verification cannot both be prerequisites of the other.
    // Every other page in the panel is gated; this one cannot be.
    expect(Welcome::isEmailVerificationRequired(Filament::getPanel('publisher')))->toBeFalse()
        ->and(Filament::getPanel('publisher')->isEmailVerificationRequired())->toBeTrue();
});

it('sends the verification email the signup promises', function () {
    Notification::fake();

    Livewire::test(Register::class)
        ->fillForm(signup())
        ->call('register')
        ->assertHasNoFormErrors();

    $user = User::query()->where('email', 'ziad@justrewards.app')->sole();

    expect($user->hasVerifiedEmail())->toBeFalse();

    Notification::assertSentTo($user, VerifyEmail::class);
});

it('does not render deal terms beside the wizard', function () {
    Livewire::test(Register::class)
        ->fillForm(signup(['app_name' => '']))
        ->assertDontSee('Your revenue share')
        ->assertDontSee('of every payout is yours')
        ->assertDontSee('WHAT YOU ARE APPLYING FOR');
});

it('refuses a signup that skips a step', function (array $missing, string $field) {
    Livewire::test(Register::class)
        ->fillForm(signup($missing))
        ->call('register')
        ->assertHasFormErrors([$field]);

    expect(User::query()->where('email', 'ziad@justrewards.app')->exists())->toBeFalse();
})->with([
    'no audience detail' => [['audience' => 'Traffic.'], 'audience'],
    'no traffic type' => [['traffic_type' => null], 'traffic_type'],
    'no country' => [['country' => null], 'country'],
    'no app url' => [['app_url' => ''], 'app_url'],
    // A promise nobody ticked is a promise nobody made.
    'terms unticked' => [['terms' => false], 'terms'],
    'incentivised unticked' => [['incentivised' => false], 'incentivised'],
    'short password' => [['password' => 'short1'], 'password'],
]);
