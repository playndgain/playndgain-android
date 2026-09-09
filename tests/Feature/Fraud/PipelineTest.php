<?php

use App\Enums\FraudAction;
use App\Enums\FraudActionTaken;
use App\Enums\FraudStage;
use App\Fraud\FraudPipeline;
use App\Fraud\IpVerdict;
use App\Fraud\RuleRegistry;
use App\Models\FraudEvent;
use App\Models\FraudRule;
use Database\Factories\OfferClickFactory;
use Database\Seeders\FraudRuleSeeder;

require_once __DIR__.'/FraudFixtures.php';

it('blocks when a block rule fires and still records the flag rule that fired with it', function () {
    $click = fraudClick(['user_agent' => null]);

    // A second click by the same user inside the window, so click_velocity
    // with max = 1 is already over before this one is judged.
    OfferClickFactory::new()->create([
        'app_id' => $click->app_id,
        'publisher_id' => $click->publisher_id,
        'offer_id' => $click->offer_id,
        'network_id' => $click->network_id,
        'end_user_id' => $click->end_user_id,
    ]);

    fraudRule('ua_anomaly', FraudStage::PreClick, FraudAction::Block, weight: 35);
    fraudRule('click_velocity', FraudStage::PreClick, FraudAction::Flag, ['max' => 1, 'window_minutes' => 5], weight: 15);

    $decision = app(FraudPipeline::class)->evaluate(FraudStage::PreClick, fraudContext($click));

    expect($decision->blocked)->toBeTrue()
        ->and($decision->reason)->toBe('ua_anomaly')
        ->and($decision->triggered)->toBe(['ua_anomaly', 'click_velocity'])
        ->and($decision->score)->toBe(50);

    // Every enabled rule runs, even after a block is decided — stopping at the
    // first block loses the signal the tuning depends on.
    expect(FraudEvent::query()->pluck('action_taken', 'rule_key')->all())->toBe([
        'ua_anomaly' => FraudActionTaken::Blocked,
        'click_velocity' => FraudActionTaken::Flagged,
    ]);
});

it('caps the accumulated score at 100 across three rules', function () {
    $click = fraudClick(['user_agent' => 'curl/8.4.0']);
    fakeIpVerdict(new IpVerdict(country: 'US', asn: 16509, isDatacenter: true, isProxy: true));

    fraudRule('ua_anomaly', FraudStage::PreClick, FraudAction::Block, weight: 35);
    fraudRule('datacenter_ip', FraudStage::PreClick, FraudAction::Block, weight: 40);
    fraudRule('vpn_proxy_ip', FraudStage::PreClick, FraudAction::Flag, weight: 40);

    $decision = app(FraudPipeline::class)->evaluate(FraudStage::PreClick, fraudContext($click));

    expect($decision->triggered)->toHaveCount(3)
        ->and($decision->score)->toBe(100);
});

it('never runs a disabled rule', function () {
    $click = fraudClick(['user_agent' => null]);

    fraudRule('ua_anomaly', FraudStage::PreClick, FraudAction::Block, weight: 35, enabled: false);

    $decision = app(FraudPipeline::class)->evaluate(FraudStage::PreClick, fraudContext($click));

    expect($decision->blocked)->toBeFalse()
        ->and($decision->triggered)->toBe([])
        ->and($decision->score)->toBe(0)
        ->and(FraudEvent::query()->count())->toBe(0);
});

it('never leaks the rule key through publicReason', function () {
    $click = fraudClick(['user_agent' => null]);
    fraudRule('ua_anomaly', FraudStage::PreClick, FraudAction::Block, weight: 35);

    $decision = app(FraudPipeline::class)->evaluate(FraudStage::PreClick, fraudContext($click));

    expect($decision->reason)->toBe('ua_anomaly')
        ->and($decision->publicReason())->toBe('unavailable')
        ->and($decision->publicReason())->not->toContain('ua_anomaly');

    foreach (FraudRule::query()->pluck('key') as $key) {
        expect($decision->publicReason())->not->toContain($key);
    }
});

it('records the inputs the rule saw, so a block is explainable without re-running it', function () {
    $click = fraudClick(['user_agent' => null, 'ip' => '198.51.100.4']);

    // The fixture click is already persisted, so this makes two in the window.
    OfferClickFactory::new()->create([
        'app_id' => $click->app_id,
        'publisher_id' => $click->publisher_id,
        'offer_id' => $click->offer_id,
        'network_id' => $click->network_id,
        'end_user_id' => $click->end_user_id,
    ]);

    fraudRule('click_velocity', FraudStage::PreClick, FraudAction::Flag, ['max' => 2, 'window_minutes' => 5], weight: 15);

    app(FraudPipeline::class)->evaluate(FraudStage::PreClick, fraudContext($click));

    $event = FraudEvent::query()->sole();

    expect($event->rule_key)->toBe('click_velocity')
        ->and($event->stage)->toBe(FraudStage::PreClick)
        ->and($event->subject_type)->toBe($click->getMorphClass())
        ->and($event->subject_id)->toBe($click->getKey())
        ->and($event->publisher_id)->toBe($click->publisher_id)
        ->and($event->end_user_id)->toBe($click->end_user_id);

    // The threshold, the measurement and the window it was measured over: an
    // admin answering "why wasn't my user credited" needs no second run.
    expect($event->context)->toMatchArray([
        'reason' => 'click velocity over the configured maximum',
        'clicks_in_window' => 2,
        'max' => 2,
        'window_minutes' => 5,
    ])->and($event->context)->toHaveKey('since');
});

it('has a registered class for every key the seeder ships', function () {
    fraudBoot();
    $this->seed(FraudRuleSeeder::class);

    $registry = app(RuleRegistry::class);
    $keys = FraudRule::query()->pluck('key');

    expect($keys)->toHaveCount(23);

    foreach ($keys as $key) {
        expect($registry->has($key))->toBeTrue("no rule class registered for '{$key}'");
    }

    // And nothing registered that the seeder does not ship — a class with a
    // typo'd key would otherwise sit there never running.
    expect(array_keys($registry->all()))->toEqualCanonicalizing($keys->all());
});

it('matches each rule class to the stage its seeded row declares', function () {
    fraudBoot();
    $this->seed(FraudRuleSeeder::class);

    $registry = app(RuleRegistry::class);

    foreach (FraudRule::query()->get() as $row) {
        expect($registry->resolve($row->key)::stage())->toBe($row->stage);
    }
});
