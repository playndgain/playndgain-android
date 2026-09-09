<?php

use App\Filament\Admin\Auth\EditProfile;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;

beforeEach(function () {
    Config::set('playndgain.require_admin_2fa', false);
    Filament::setCurrentPanel('admin');
});

it('does not change the email from the profile page', function () {
    $admin = User::factory()->admin()->create([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_recovery_codes' => ['aaaa-bbbb'],
    ]);
    $email = $admin->email;

    $this->actingAs($admin);

    Livewire::test(EditProfile::class)
        ->assertFormFieldIsDisabled('email')
        ->fillForm([
            'name' => $admin->name,
            'email' => 'hijacked@example.com',
            'currentPassword' => 'password',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($admin->fresh()->email)->toBe($email);
});
