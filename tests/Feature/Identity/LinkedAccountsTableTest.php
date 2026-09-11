<?php

use App\Enums\IdentityKind;
use App\Enums\IdentitySignal;
use App\Filament\Admin\Resources\AccountMatches\Pages\ListAccountMatches;
use App\Models\AccountMatch;
use App\Models\Publisher;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;

beforeEach(function () {
    Config::set('playndgain.require_admin_2fa', false);
    Filament::setCurrentPanel('admin');

    $this->actingAs(User::factory()->admin()->create([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_recovery_codes' => ['aaaa-bbbb'],
    ]));
});

it('lists a wall match on the linked accounts table without a fraud action', function () {
    $publisher = Publisher::factory()->create();

    AccountMatch::query()->create([
        'match_key' => sha1('wall-ip-fixture'),
        'scope' => IdentityKind::Wall,
        'signal' => IdentitySignal::Ip,
        'signal_value' => '203.0.113.44',
        'publisher_id' => $publisher->id,
        'left_end_user_id' => 'USER_A',
        'right_end_user_id' => 'USER_B',
        'seen_count' => 3,
        'first_seen_at' => now()->subHour(),
        'last_seen_at' => now(),
    ]);

    Livewire::test(ListAccountMatches::class)
        ->assertSuccessful()
        ->assertSee('USER_A')
        ->assertSee('USER_B')
        ->assertSee('wall')
        ->assertSee('IP')
        ->assertDontSee('Blocked')
        ->assertDontSee('Flagged');
});

it('keeps the linked accounts page off the publisher panel', function () {
    Filament::setCurrentPanel('publisher');
    $publisher = Publisher::factory()->create();
    $this->actingAs($publisher->user);

    $this->get('/admin/account-matches')->assertForbidden();
});
