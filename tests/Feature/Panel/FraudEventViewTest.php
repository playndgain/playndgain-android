<?php

use App\Enums\FraudActionTaken;
use App\Enums\FraudStage;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\FraudEvents\FraudEventResource;
use App\Filament\Admin\Resources\FraudEvents\Pages\ListFraudEvents;
use App\Models\App;
use App\Models\FraudEvent;
use App\Models\Publisher;
use App\Models\User;
use Database\Factories\OfferClickFactory;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    Config::set('playndgain.require_admin_2fa', false);

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
});

it('opens a fraud event view with the full recorded context', function () {
    $publisher = Publisher::factory()->create();
    $app = App::factory()->for($publisher)->create();
    $click = OfferClickFactory::new()->for($app)->create();

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

    // The slide-over is rendered client-side once the action is mounted, so
    // the component HTML never carries it: the assertion is on the schema the
    // modal is built from — mounted for this record, with every recorded input
    // and the reason first.
    $component = Livewire::test(ListFraudEvents::class)
        ->mountAction(TestAction::make('view')->table($event))
        ->assertSuccessful()
        ->assertActionMounted(TestAction::make('view')->table($event));

    $component->assertSchemaComponentStateSet('context_pairs', [
        'reason' => 'third-party risk score over threshold',
        'ip' => '203.0.113.10',
        'score' => '92',
        'threshold' => '85',
        'source' => 'ipqualityscore',
    ], $component->instance()->getMountedActionSchemaName());
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
