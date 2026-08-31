<?php

use App\Models\Consent;

require_once __DIR__.'/WallFixtures.php';

function wallConsentPost(array $payload)
{
    return test()->withHeaders(['User-Agent' => WALL_UA])->post('/wall/consent', $payload);
}

it('stores an accepted consent per app and end user and returns to the wall', function () {
    [$app, $network] = wallFixture();
    wallConsentCountries(['DE']);
    wallGeo('DE');
    $offer = wallOffer($network, ['countries' => [], 'devices' => []]);

    wallConsentPost(['appid' => $app->app_id, 'userid' => 'USER_1', 'action' => 'accept'])
        ->assertStatus(302);

    $consent = Consent::query()->sole();

    expect($consent->granted)->toBeTrue()
        ->and($consent->app_id)->toBe($app->id)
        ->and($consent->end_user_id)->toBe('USER_1')
        ->and($consent->country)->toBe('DE')
        ->and($consent->purposes)->toContain('fraud_prevention')
        // Hashed, not stored raw: the row proves which client agreed without
        // keeping a fingerprintable string.
        ->and($consent->user_agent_hash)->toBe(hash('sha256', WALL_UA));

    wallGet($app)->assertOk()->assertSee($offer->name);
});

it('honours a decline: no offers, no click, and the answer survives a reload', function () {
    [$app, $network] = wallFixture();
    wallConsentCountries(['DE']);
    wallGeo('DE');
    $offer = wallOffer($network, ['countries' => [], 'devices' => []]);

    wallConsentPost(['appid' => $app->app_id, 'userid' => 'USER_1', 'action' => 'decline'])
        ->assertStatus(302);

    expect(Consent::query()->sole()->granted)->toBeFalse();

    wallGet($app)->assertOk()->assertSee(__('wall.notice.declined.title'))->assertDontSee($offer->name);
    wallClick($app, $offer)->assertForbidden();
});

it('updates the existing row rather than writing a second one', function () {
    [$app] = wallFixture();
    wallConsentCountries(['DE']);
    wallGeo('DE');

    wallConsentPost(['appid' => $app->app_id, 'userid' => 'USER_1', 'action' => 'decline']);
    wallConsentPost(['appid' => $app->app_id, 'userid' => 'USER_1', 'action' => 'accept']);

    expect(Consent::query()->count())->toBe(1)
        ->and(Consent::query()->sole()->granted)->toBeTrue();
});

it('rejects a consent post with no answer or a bad signature', function () {
    [$app] = wallFixture(['require_signed_wall' => true]);
    wallGeo('DE');

    wallConsentPost(['appid' => $app->app_id, 'userid' => 'USER_1', 'action' => 'maybe', 'sig' => wallSig($app)])
        ->assertStatus(400);

    wallConsentPost(['appid' => $app->app_id, 'userid' => 'USER_1', 'action' => 'accept'])
        ->assertForbidden();

    expect(Consent::query()->count())->toBe(0);
});

it('anonymises the consent ip when the app asks for it', function () {
    [$app] = wallFixture(['anonymize_ip' => true]);
    wallGeo('DE');

    wallConsentPost(['appid' => $app->app_id, 'userid' => 'USER_1', 'action' => 'accept']);

    expect(Consent::query()->sole()->ip)->toBe('127.0.0.0');
});
