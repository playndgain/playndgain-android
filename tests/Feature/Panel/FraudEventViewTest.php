<?php

use App\Enums\FraudActionTaken;
use App\Enums\FraudStage;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\FraudEvents\FraudEventResource;
use App\Filament\Admin\Resources\FraudEvents\Pages\ListFraudEvents;
use App\Models\App;
use App\Models\FraudEvent;
use App\Models\OfferClick;
use App\Models\Publisher;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    Config::set('advault.require_admin_2fa', false);

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
});

it('opens a fraud event view with the full recorded context', function () {
    $publisher = Publisher::factory()->create();
    $app = App::factory()->for($publisher)->create();
    $click = OfferClick::factory()->for($app)->create();

    $event = FraudEvent::query()->create([
        'rule_key' => 'ip_risk_score',
        'stage' => FraudStage::PreClick,
        'subject_type' => $click->getMorphClass(),
        'subject_id' => $click->getKey(),
        'app_id' => $app->id,
        'publisher_id' => $publisher->id,
        'end_user_id' => $click->end_user_id,
        'action_taken' => FraudActionTaken::Flagged,
        'context' => [
            'reason' => 'third-party risk score over threshold',
            'ip' => '203.0.113.10',
            'score' => 92,
            'threshold' => 85,
            'source' => 'ipqualityscore',
        ],
        'created_at' => now(),
    ]);

    Livewire::test(ListFraudEvents::class)
        ->callTableAction('view', $event)
        ->assertSuccessful()
        ->assertSee('third-party risk score over threshold')
        ->assertSee('203.0.113.10')
        ->assertSee('92')
        ->assertSee('ipqualityscore');
});

it('orders context pairs with reason first', function () {
    $pairs = FraudEventResource::contextPairs(new FraudEvent([
        'context' => [
            'ip' => '1.2.3.4',
            'reason' => 'click originates from a hosting provider',
            'asn' => 24940,
        ],
    ]));

    expect(array_key_first($pairs))->toBe('reason')
        ->and($pairs['reason'])->toBe('click originates from a hosting provider')
        ->and($pairs['ip'])->toBe('1.2.3.4');
});
