<?php

use App\Enums\FraudStage;
use App\Fraud\Decision;
use App\Fraud\FraudContext;
use App\Fraud\FraudPipeline;
use App\Fraud\RuleRegistry;
use App\Models\Network;
use App\Models\OfferClick;
use App\Services\Wall\EmbedClick;

require_once __DIR__.'/WallFixtures.php';

it('writes one row per app, partner and end user, and reuses it', function () {
    wallGeo('US');
    [$app] = wallFixture();
    $partner = Network::factory()->create(['wall_url' => 'https://p.test/w?s={sub_id}']);

    [$first] = app(EmbedClick::class)->open(partnerContext($app), $partner);
    [$second] = app(EmbedClick::class)->open(partnerContext($app), $partner);

    expect(OfferClick::query()->count())->toBe(1)
        ->and($second->id)->toBe($first->id)
        ->and($first->offer_id)->toBeNull()
        ->and($first->network_id)->toBe($partner->id)
        ->and($first->app_id)->toBe($app->id)
        ->and($first->publisher_id)->toBe($app->publisher_id)
        ->and($first->end_user_id)->toBe('USER_1')
        ->and($first->country)->toBe('US')
        ->and($first->click_id)->not->toBeNull();
});

it('gives a different end user a different row', function () {
    wallGeo('US');
    [$app] = wallFixture();
    $partner = Network::factory()->create(['wall_url' => 'https://p.test/w?s={sub_id}']);

    app(EmbedClick::class)->open(partnerContext($app, 'USER_1'), $partner);
    app(EmbedClick::class)->open(partnerContext($app, 'USER_2'), $partner);

    expect(OfferClick::query()->count())->toBe(2);
});

it('writes the row even when the open is blocked', function () {
    wallGeo('US');
    [$app] = wallFixture();
    $partner = Network::factory()->create(['wall_url' => 'https://p.test/w?s={sub_id}']);

    // A refusal with no row is a support question nobody can answer —
    // ClickController states the principle, this is the same path.
    app()->instance(FraudPipeline::class, new class(app(RuleRegistry::class)) extends FraudPipeline
    {
        public function evaluate(FraudStage $stage, FraudContext $context): Decision
        {
            return new Decision(true, 100, 'stubbed_block');
        }
    });

    [$click, $blocked] = app(EmbedClick::class)->open(partnerContext($app), $partner);

    expect($blocked)->toBeTrue()
        ->and($click->exists)->toBeTrue()
        ->and($click->blocked)->toBeTrue()
        ->and($click->blocked_reason)->toBe('stubbed_block')
        ->and($click->risk_score)->toBe(100);
});

it('re-reads the verdict on every open and still never rewrites the row', function () {
    wallGeo('US');
    [$app] = wallFixture();
    $partner = Network::factory()->create(['wall_url' => 'https://p.test/w?s={sub_id}']);

    [$first, $clean] = app(EmbedClick::class)->open(partnerContext($app), $partner);

    app()->instance(FraudPipeline::class, new class(app(RuleRegistry::class)) extends FraudPipeline
    {
        public function evaluate(FraudStage $stage, FraudContext $context): Decision
        {
            return new Decision(true, 100, 'stubbed_block');
        }
    });

    [$second, $blocked] = app(EmbedClick::class)->open(partnerContext($app), $partner);

    // Both halves of the same rule: the verdict is about now, so the second
    // open is refused; the row is a log of first contact, so it still carries
    // the clean score it was written with.
    expect($clean)->toBeFalse()
        ->and($blocked)->toBeTrue()
        ->and($second->id)->toBe($first->id)
        ->and($second->refresh()->blocked)->toBeFalse()
        ->and($second->risk_score)->toBe($first->risk_score)
        ->and($second->blocked_reason)->toBe($first->blocked_reason);
});

it('masks the ip when the app asks for it', function () {
    wallGeo('US');
    [$app] = wallFixture(['anonymize_ip' => true]);
    $partner = Network::factory()->create(['wall_url' => 'https://p.test/w?s={sub_id}']);

    [$click] = app(EmbedClick::class)->open(partnerContext($app), $partner);

    expect($click->ip)->toBe('127.0.0.0');
});

it('marks the open as test earnings only when the country was overridden', function () {
    wallGeo('US');
    [$live] = wallFixture();
    [$testing] = wallFixture(['test_mode' => true]);
    $partner = Network::factory()->create(['wall_url' => 'https://p.test/w?s={sub_id}']);

    [$plain] = app(EmbedClick::class)->open(partnerContext($live), $partner);
    [$overridden] = app(EmbedClick::class)->open(partnerContext($testing, 'USER_9', ['country' => 'DE']), $partner);

    expect($plain->is_test)->toBeFalse()
        ->and($overridden->is_test)->toBeTrue()
        ->and($overridden->country)->toBe('DE');
});

it('starts a second row rather than reusing a live one once the app is flipped to test mode', function () {
    wallGeo('US');
    [$app] = wallFixture();
    $partner = Network::factory()->create(['wall_url' => 'https://p.test/w?s={sub_id}']);

    [$live] = app(EmbedClick::class)->open(partnerContext($app), $partner);

    // `test_mode` is a publisher-editable toggle on a live app and `country`
    // is readable off the iframe URL. Reusing the live row here would keep
    // `is_test` false, and `ConversionProcessor` copies it onto the conversion
    // — so the override would write a real ledger entry from a country the
    // targeting never allowed. The mirror case is worse still: a row stamped
    // `is_test` during a publisher's test would silently stop a real user from
    // ever earning again.
    $app->update(['test_mode' => true]);

    [$testing] = app(EmbedClick::class)->open(partnerContext($app, 'USER_1', ['country' => 'DE']), $partner);

    expect(OfferClick::query()->count())->toBe(2)
        ->and($testing->id)->not->toBe($live->id)
        ->and($testing->is_test)->toBeTrue()
        ->and($live->refresh()->is_test)->toBeFalse();
});
