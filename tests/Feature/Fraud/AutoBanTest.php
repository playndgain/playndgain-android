<?php

use App\Enums\BannedBy;
use App\Enums\FraudAction;
use App\Enums\FraudStage;
use App\Fraud\FraudPipeline;
use App\Fraud\Rules\AutoBanThresholdRule;
use App\Fraud\Rules\BannedEndUserRule;
use App\Models\BannedEndUser;
use App\Models\FraudEvent;

require_once __DIR__.'/FraudFixtures.php';

it('does not ban one blocked event under the threshold', function () {
    $click = fraudClick();
    fraudSeedBlocks($click, 4);
    fraudRule('auto_ban_threshold', FraudStage::PostConversion, FraudAction::Block, ['blocks' => 5, 'window_hours' => 24], weight: 50);

    $decision = app(FraudPipeline::class)->evaluate(FraudStage::PostConversion, fraudContext($click));

    expect($decision->blocked)->toBeFalse()
        ->and(BannedEndUser::query()->count())->toBe(0);
});

it('bans at exactly the threshold with a seven day expiry', function () {
    $click = fraudClick();
    fraudSeedBlocks($click, 5);
    fraudRule('auto_ban_threshold', FraudStage::PostConversion, FraudAction::Block, ['blocks' => 5, 'window_hours' => 24], weight: 50);

    $decision = app(FraudPipeline::class)->evaluate(FraudStage::PostConversion, fraudContext($click));

    $ban = BannedEndUser::query()->sole();

    expect($decision->blocked)->toBeTrue()
        ->and($decision->reason)->toBe('auto_ban_threshold')
        ->and($ban->end_user_id)->toBe($click->end_user_id)
        ->and($ban->publisher_id)->toBe($click->publisher_id)
        ->and($ban->app_id)->toBe($click->app_id)
        ->and($ban->banned_by)->toBe(BannedBy::System)
        ->and($ban->reason)->toBe('auto: 5 blocked events in 24h')
        ->and($ban->expires_at?->toDateString())->toBe(now()->addDays(7)->toDateString());
});

it('counts only blocked events inside the configured window', function () {
    $click = fraudClick();
    fraudSeedBlocks($click, 5);
    BannedEndUser::query()->delete();

    // Push every event outside a one hour window.
    FraudEvent::query()->update(['created_at' => now()->subHours(6)]);

    expect(app(AutoBanThresholdRule::class)
        ->evaluate(fraudContext($click), ['blocks' => 5, 'window_hours' => 1])->triggered)->toBeFalse();
});

it('never softens a publisher ban into a seven day system ban', function () {
    $click = fraudClick();
    fraudSeedBlocks($click, 5);

    BannedEndUser::query()->create([
        'publisher_id' => $click->publisher_id,
        'app_id' => $click->app_id,
        'end_user_id' => $click->end_user_id,
        'banned_by' => BannedBy::Publisher,
        'reason' => 'manual',
        'expires_at' => null,
    ]);

    app(AutoBanThresholdRule::class)
        ->evaluate(fraudContext($click), ['blocks' => 5, 'window_hours' => 24]);

    $ban = BannedEndUser::query()->sole();

    expect($ban->banned_by)->toBe(BannedBy::Publisher)
        ->and($ban->expires_at)->toBeNull()
        ->and($ban->reason)->toBe('manual');
});

it('does not let publisher A\'s ban block the same end user on publisher B\'s app', function () {
    $a = fraudClick(['end_user_id' => 'user_1']);
    $b = fraudClick(['end_user_id' => 'user_1']);

    BannedEndUser::query()->create([
        'publisher_id' => $a->publisher_id,
        'app_id' => null,
        'end_user_id' => 'user_1',
        'banned_by' => BannedBy::System,
        'expires_at' => now()->addDays(7),
    ]);

    $rule = app(BannedEndUserRule::class);

    expect($a->publisher_id)->not->toBe($b->publisher_id)
        ->and($rule->evaluate(fraudContext($a), [])->triggered)->toBeTrue()
        ->and($rule->evaluate(fraudContext($b), [])->triggered)->toBeFalse();
});
