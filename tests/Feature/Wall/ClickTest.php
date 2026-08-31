<?php

use App\Enums\DeviceType;
use App\Enums\FraudAction;
use App\Enums\FraudStage;
use App\Enums\NetworkStatus;
use App\Enums\OfferStatus;
use App\Fraud\Contracts\Rule;
use App\Fraud\FraudContext;
use App\Fraud\RuleRegistry;
use App\Fraud\RuleResult;
use App\Models\Consent;
use App\Models\FraudEvent;
use App\Models\FraudRule;
use App\Models\OfferClick;
use Database\Factories\BannedEndUserFactory;
use Database\Factories\ConsentFactory;

require_once __DIR__.'/WallFixtures.php';

/** A pre-click rule that always fires, so the block path is exercised for real. */
class WallAlwaysBlockRule implements Rule
{
    public static function key(): string
    {
        return 'always_block';
    }

    public static function stage(): FraudStage
    {
        return FraudStage::PreClick;
    }

    public function evaluate(FraudContext $context, array $config): RuleResult
    {
        return RuleResult::trigger('test_rule', ['ip' => $context->ip]);
    }
}

function wallBlockingRule(): void
{
    app(RuleRegistry::class)->register(WallAlwaysBlockRule::class);

    FraudRule::query()->create([
        'key' => 'always_block',
        'name' => 'Always block',
        'description' => 'Test rule.',
        'stage' => FraudStage::PreClick,
        'enabled' => true,
        'action' => FraudAction::Block,
        'score_weight' => 60,
        'config' => [],
    ]);
}

it('redirects with a 302 to the tracking url carrying the click id', function () {
    [$app, $network] = wallFixture();
    wallGeo('US');
    $offer = wallOffer($network, ['countries' => [], 'devices' => []]);

    $response = wallClick($app, $offer);

    $click = OfferClick::query()->withoutGlobalScopes()->sole();

    // 301 is cached permanently and the tracking URL carries a single-use
    // click_id — a cached redirect attaches the next conversion to this row.
    $response->assertStatus(302)->assertRedirect(str_replace('{click_id}', (string) $click->click_id, $offer->tracking_url));

    expect($click->blocked)->toBeFalse()
        ->and($click->app_id)->toBe($app->id)
        ->and($click->publisher_id)->toBe($app->publisher_id)
        ->and($click->network_id)->toBe($network->id)
        ->and($click->end_user_id)->toBe('USER_1')
        ->and($click->country)->toBe('US')
        ->and($click->device_type)->toBe(DeviceType::Android)
        ->and($click->os)->toBe('Android')
        ->and($click->browser)->toBe('Chrome')
        ->and($click->is_test)->toBeFalse();
});

it('writes the click row even when a pre-click rule blocks the redirect', function () {
    [$app, $network] = wallFixture();
    wallGeo('US');
    wallBlockingRule();
    $offer = wallOffer($network, ['countries' => [], 'devices' => []]);

    wallClick($app, $offer)->assertForbidden()->assertSee(__('wall.notice.blocked.title'));

    $click = OfferClick::query()->withoutGlobalScopes()->sole();

    expect($click->blocked)->toBeTrue()
        ->and($click->blocked_reason)->toBe('always_block')
        ->and($click->risk_score)->toBe(60)
        // The rule that caught them is never shown to the user.
        ->and(FraudEvent::query()->where('rule_key', 'always_block')->count())->toBe(1);
});

it('refuses a banned end user on a hand-crafted click url and still records it', function () {
    [$app, $network] = wallFixture();
    wallGeo('US');
    $offer = wallOffer($network, ['countries' => [], 'devices' => []]);

    BannedEndUserFactory::new()->create(['publisher_id' => $app->publisher_id, 'end_user_id' => 'USER_1']);

    wallClick($app, $offer)->assertForbidden();

    $click = OfferClick::query()->withoutGlobalScopes()->sole();

    expect($click->blocked)->toBeTrue()->and($click->blocked_reason)->toBe('banned_end_user');
});

it('refuses a click without consent in a consent country and stores nothing', function () {
    [$app, $network] = wallFixture();
    wallConsentCountries(['DE']);
    wallGeo('DE');
    $offer = wallOffer($network, ['countries' => [], 'devices' => []]);

    // No row: storing IP, user agent and publisher user ID for someone who has
    // not consented is the processing the gate exists to prevent.
    wallClick($app, $offer)->assertForbidden();
    expect(OfferClick::query()->withoutGlobalScopes()->count())->toBe(0);

    ConsentFactory::new()->declined()->create(['app_id' => $app->id, 'end_user_id' => 'USER_1']);
    wallClick($app, $offer)->assertForbidden();
    expect(OfferClick::query()->withoutGlobalScopes()->count())->toBe(0);

    // One row per (app, end_user) — the answer is updated, never duplicated.
    Consent::query()->where('app_id', $app->id)->where('end_user_id', 'USER_1')->update(['granted' => true]);
    wallClick($app, $offer)->assertStatus(302);
    expect(OfferClick::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('refuses a click on an offer that is no longer active', function () {
    [$app, $network] = wallFixture();
    wallGeo('US');
    $offer = wallOffer($network, ['status' => OfferStatus::Inactive]);

    wallClick($app, $offer)->assertStatus(410);

    expect(OfferClick::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('refuses a click when the offer\'s network is disabled', function () {
    [$app, $network] = wallFixture();
    wallGeo('US');
    $offer = wallOffer($network, ['countries' => [], 'devices' => []]);

    $network->update(['status' => NetworkStatus::Disabled]);

    wallClick($app, $offer)->assertStatus(410);

    expect(OfferClick::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('applies the app signature and userid rules to clicks too', function () {
    [$app, $network] = wallFixture(['require_signed_wall' => true]);
    wallGeo('US');
    $offer = wallOffer($network, ['countries' => [], 'devices' => []]);

    wallClick($app, $offer, 'USER_1', ['sig' => 'nope'])->assertForbidden();
    wallClick($app, $offer, 'bad user')->assertStatus(400);
    wallClick($app, $offer, 'USER_1', ['sig' => wallSig($app)])->assertStatus(302);
});

it('masks the stored ip when the app asks for anonymisation', function () {
    [$app, $network] = wallFixture(['anonymize_ip' => true]);
    wallGeo('US');
    $offer = wallOffer($network, ['countries' => [], 'devices' => []]);

    wallClick($app, $offer)->assertStatus(302);

    expect(OfferClick::query()->withoutGlobalScopes()->sole()->ip)->toBe('127.0.0.0');
});

it('marks a click from an overridden country as test earnings', function () {
    // Test mode is publisher-editable and the parameter is readable off the
    // iframe URL, so an app left in test mode must not be a way to earn real
    // money on offers whose geo targeting was never met.
    [$app, $network] = wallFixture(['test_mode' => true]);
    wallGeo('DE');

    $offer = wallOffer($network, ['countries' => ['US'], 'devices' => []]);

    wallClick($app, $offer, 'USER_1', ['country' => 'US'])->assertRedirect();

    $click = OfferClick::query()->sole();

    expect($click->is_test)->toBeTrue()
        ->and($click->country)->toBe('US');
});

it('keeps a click real when test mode is on but the country was not overridden', function () {
    [$app, $network] = wallFixture(['test_mode' => true]);
    wallGeo('US');

    $offer = wallOffer($network, ['countries' => ['US'], 'devices' => []]);

    wallClick($app, $offer)->assertRedirect();

    expect(OfferClick::query()->sole()->is_test)->toBeFalse();
});
