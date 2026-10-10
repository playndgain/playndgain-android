<?php

use App\Models\App;
use App\Models\Consent;
use App\Models\Conversion;
use App\Models\FraudEvent;
use App\Models\Offer;
use App\Models\OfferClick;
use Database\Factories\ConsentFactory;
use Database\Factories\OfferClickFactory;

require_once __DIR__.'/WallFixtures.php';

/**
 * @return array{App, Offer}
 */
function wallSubject(): array
{
    [$app, $network] = wallFixture();
    $offer = wallOffer($network);

    OfferClickFactory::new()->create([
        'app_id' => $app->id,
        'publisher_id' => $app->publisher_id,
        'offer_id' => $offer->id,
        'network_id' => $network->id,
        'end_user_id' => 'USER_1',
        'ip' => '203.0.113.9',
    ]);

    Conversion::factory()->create([
        'app_id' => $app->id,
        'publisher_id' => $app->publisher_id,
        'offer_id' => $offer->id,
        'network_id' => $network->id,
        'end_user_id' => 'USER_1',
        'ip' => '203.0.113.9',
    ]);

    ConsentFactory::new()->create(['app_id' => $app->id, 'end_user_id' => 'USER_1']);

    return [$app, $offer];
}

it('requires a signature on the data endpoint whatever the app setting says', function () {
    [$app] = wallSubject();

    // require_signed_wall is false here: an unsigned /privacy/data would hand
    // everything held on a user to anyone who can guess two IDs.
    test()->get('/privacy/data?appid='.$app->app_id.'&userid=USER_1')->assertForbidden();
    test()->get('/privacy/data?appid='.$app->app_id.'&userid=USER_1&sig=nope')->assertForbidden();
});

it('returns what is held on an end user', function () {
    [$app] = wallSubject();

    $response = test()->get('/privacy/data?'.http_build_query([
        'appid' => $app->app_id,
        'userid' => 'USER_1',
        'sig' => wallSig($app),
    ]));

    $response->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('end_user_id', 'USER_1')
        ->assertJsonPath('clicks.count', 1)
        ->assertJsonPath('conversions.count', 1)
        ->assertJsonPath('consent.granted', true);
});

it('anonymises clicks and conversions on delete and keeps the money rows', function () {
    [$app] = wallSubject();

    test()->post('/privacy/delete', ['appid' => $app->app_id, 'userid' => 'USER_1', 'sig' => wallSig($app)])
        ->assertOk()
        ->assertJsonPath('clicks_anonymized', 1)
        ->assertJsonPath('conversions_anonymized', 1);

    $click = OfferClick::query()->withoutGlobalScopes()->sole();
    $conversion = Conversion::query()->withoutGlobalScopes()->sole();

    expect($click->ip)->toBe('0.0.0.0')
        ->and($click->user_agent)->toBeNull()
        ->and($click->end_user_id)->toStartWith('anon_')
        ->and($conversion->ip)->toBeNull()
        ->and($conversion->end_user_id)->toBe($click->end_user_id)
        // Financial records are retained on a legitimate-interest basis.
        ->and((float) $conversion->payout)->toBeGreaterThan(0)
        ->and(Consent::query()->count())->toBe(0);
});

it('refuses an unsigned delete', function () {
    [$app] = wallSubject();

    test()->post('/privacy/delete', ['appid' => $app->app_id, 'userid' => 'USER_1'])->assertForbidden();

    expect(OfferClick::query()->withoutGlobalScopes()->sole()->end_user_id)->toBe('USER_1');
});

it('exports and then erases sub ids and native device strings', function () {
    [$app] = wallSubject();

    OfferClick::query()->withoutGlobalScopes()->update([
        'sub1' => 'a@b.c', 'sub5' => 'z', 'device_model' => 'Pixel 7', 'device_hint' => 'Pixel 7|panther',
    ]);

    test()->get('/privacy/data?'.http_build_query(['appid' => $app->app_id, 'userid' => 'USER_1', 'sig' => wallSig($app)]))
        ->assertOk()
        ->assertJsonPath('clicks.rows.0.sub1', 'a@b.c')
        ->assertJsonPath('clicks.rows.0.device_model', 'Pixel 7');

    test()->post('/privacy/delete', ['appid' => $app->app_id, 'userid' => 'USER_1', 'sig' => wallSig($app)])->assertOk();

    $click = OfferClick::query()->withoutGlobalScopes()->sole();

    expect($click->sub1)->toBeNull()
        ->and($click->sub5)->toBeNull()
        ->and($click->device_model)->toBeNull()
        ->and($click->device_hint)->toBeNull();
});

it('strips identifying inputs from fraud events on delete and keeps the decision', function () {
    [$app] = wallSubject();

    $event = FraudEvent::query()->create([
        'rule_key' => 'emulator_device', 'stage' => 'pre_click', 'subject_type' => 'offer_click', 'subject_id' => 1,
        'app_id' => $app->id, 'publisher_id' => $app->publisher_id, 'end_user_id' => 'USER_1', 'action_taken' => 'blocked',
        'context' => ['reason' => 'x', 'signature' => 'sdk_gphone', 'ip' => '203.0.113.9', 'user_agent' => 'UA', 'device_hint' => 'Pixel 7|panther', 'end_user_id' => 'USER_1'],
    ]);

    test()->post('/privacy/delete', ['appid' => $app->app_id, 'userid' => 'USER_1', 'sig' => wallSig($app)])->assertOk();

    $event->refresh();

    expect($event->end_user_id)->not->toBe('USER_1')
        ->and($event->rule_key)->toBe('emulator_device')
        ->and($event->context)->toBe(['reason' => 'x', 'signature' => 'sdk_gphone']);
});
