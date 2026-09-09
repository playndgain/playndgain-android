<?php

use App\Enums\FraudAction;
use App\Enums\FraudStage;
use App\Filament\Admin\Resources\FraudRules\Pages\ListFraudRules;
use App\Models\FraudRule;
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

it('truncates a long rule description and keeps the full text in the cell tooltip', function () {
    $full = 'Consumer VPN or unclassified proxy exit node. Blocks at wall render: the user is told they are on a VPN, the fix is one toggle, and a flagged VPN conversion is paid out and then charged back.';

    FraudRule::query()->create([
        'key' => 'tooltip_preview_rule',
        'name' => 'Tooltip preview rule',
        'description' => $full,
        'stage' => FraudStage::PreClick,
        'action' => FraudAction::Block,
        'enabled' => true,
    ]);

    $preview = (string) str($full)->limit(72);

    expect($preview)->not->toBe($full);

    Livewire::test(ListFraudRules::class)
        ->assertSuccessful()
        ->assertSee($preview)
        ->assertSeeHtml('x-tooltip')
        ->assertSeeHtml($full);
});

it('keeps table filters after leaving the page and coming back', function () {
    FraudRule::query()->create([
        'key' => 'session_pre_click',
        'name' => 'Session pre-click',
        'description' => 'Short.',
        'stage' => FraudStage::PreClick,
        'action' => FraudAction::Block,
        'enabled' => true,
    ]);
    FraudRule::query()->create([
        'key' => 'session_post_conversion',
        'name' => 'Session post-conversion',
        'description' => 'Short.',
        'stage' => FraudStage::PostConversion,
        'action' => FraudAction::Flag,
        'enabled' => true,
    ]);

    Livewire::test(ListFraudRules::class)
        ->filterTable('stage', FraudStage::PreClick->value)
        ->assertSee('Session pre-click')
        ->assertDontSee('Session post-conversion');

    Livewire::test(ListFraudRules::class)
        ->assertSee('Session pre-click')
        ->assertDontSee('Session post-conversion');
});
