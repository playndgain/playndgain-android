<?php

use App\Filament\Admin\Resources\Publishers\Pages\EditPublisher;
use App\Models\AuditLog;
use App\Models\Publisher;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

/**
 * Password and email verification live on the user row. The publisher edit
 * form never writes them, so both have to be header actions — same reason
 * regenerate-secret is not a field on the app form.
 */
beforeEach(function () {
    Config::set('playndgain.require_admin_2fa', false);
    Filament::setCurrentPanel('admin');

    $this->actingAs(User::factory()->admin()->create([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_recovery_codes' => ['aaaa-bbbb'],
    ]));
});

it('changes the publisher password without writing it to the audit log', function () {
    $publisher = Publisher::factory()->create();

    Livewire::test(EditPublisher::class, ['record' => $publisher->getRouteKey()])
        ->callAction('change_password', [
            'password' => 'correct-horse-9-battery',
            'password_confirmation' => 'correct-horse-9-battery',
        ])
        ->assertHasNoActionErrors();

    $user = $publisher->user->fresh();
    $log = AuditLog::query()->where('action', 'publisher.password_changed')->sole();

    expect(Hash::check('correct-horse-9-battery', $user->password))->toBeTrue()
        ->and(Hash::check('password', $user->password))->toBeFalse()
        ->and($log->subject_id)->toBe($publisher->id)
        ->and(json_encode([$log->before, $log->after]))->not->toContain('correct-horse-9-battery');
});

it('rejects a publisher password that would fail signup', function () {
    $publisher = Publisher::factory()->create();

    Livewire::test(EditPublisher::class, ['record' => $publisher->getRouteKey()])
        ->callAction('change_password', [
            'password' => 'short',
            'password_confirmation' => 'short',
        ])
        ->assertHasActionErrors(['password']);

    expect(Hash::check('password', $publisher->user->fresh()->password))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'publisher.password_changed')->exists())->toBeFalse();
});

it('marks an unverified publisher email as verified', function () {
    $publisher = Publisher::factory()->create([
        'user_id' => User::factory()->unverified(),
    ]);

    expect($publisher->user->hasVerifiedEmail())->toBeFalse();

    Livewire::test(EditPublisher::class, ['record' => $publisher->getRouteKey()])
        ->assertActionVisible('verify_account')
        ->callAction('verify_account')
        ->assertHasNoActionErrors();

    $user = $publisher->user->fresh();
    $log = AuditLog::query()->where('action', 'publisher.email_verified')->sole();

    expect($user->hasVerifiedEmail())->toBeTrue()
        ->and($log->subject_id)->toBe($publisher->id);
});

it('hides verify when the address is already confirmed', function () {
    $publisher = Publisher::factory()->create();

    expect($publisher->user->hasVerifiedEmail())->toBeTrue();

    Livewire::test(EditPublisher::class, ['record' => $publisher->getRouteKey()])
        ->assertActionHidden('verify_account');
});
