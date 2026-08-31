<?php

use App\Models\Network;
use App\Services\Wall\PartnerWall;

require_once __DIR__.'/WallFixtures.php';

it('substitutes every macro it knows', function () {
    wallGeo('US');
    [$app] = wallFixture();

    $network = Network::factory()->create([
        'wall_url' => 'https://p.test/w?s={sub_id}&u={user_id}&a={app_id}&c={country}&d={device}&l={lang}',
    ]);

    $url = PartnerWall::url($network, partnerContext($app, 'USER_1', ['lang' => 'ar']), 'SUB-42');

    expect($url)->toBe(
        "https://p.test/w?s=SUB-42&u=USER_1&a={$app->app_id}&c=US&d=android&l=ar"
    );
});

it('url-encodes what it substitutes', function () {
    wallGeo('US');
    [$app] = wallFixture();

    $network = Network::factory()->create(['wall_url' => 'https://p.test/w?u={user_id}']);

    // `|` and `:` are legal in an end user ID (WallGate::endUserId) and both
    // have meaning in a URL.
    $url = PartnerWall::url($network, partnerContext($app, 'a|b:c'), 'SUB-1');

    expect($url)->toBe('https://p.test/w?u=a%7Cb%3Ac');
});

it('leaves a macro it does not know exactly where it found it', function () {
    wallGeo('US');
    [$app] = wallFixture();

    $network = Network::factory()->create(['wall_url' => 'https://p.test/w?s={sub_id}&x={clickid}']);

    // Blanking it would turn a setup mistake into a payout mystery weeks later.
    expect(PartnerWall::url($network, partnerContext($app), 'SUB-1'))
        ->toBe('https://p.test/w?s=SUB-1&x={clickid}');
});

it('empties a macro whose value we never established', function () {
    wallGeo(null);
    [$app] = wallFixture();

    $network = Network::factory()->create(['wall_url' => 'https://p.test/w?c={country}']);

    expect(PartnerWall::url($network, partnerContext($app), 'SUB-1'))->toBe('https://p.test/w?c=');
});

it('refuses a wall url that is not https', function () {
    expect(PartnerWall::isEmbeddable(Network::factory()->create(['wall_url' => 'http://p.test/w'])))->toBeFalse()
        ->and(PartnerWall::isEmbeddable(Network::factory()->create(['wall_url' => 'javascript:alert(1)'])))->toBeFalse()
        ->and(PartnerWall::isEmbeddable(Network::factory()->create(['wall_url' => null])))->toBeFalse()
        ->and(PartnerWall::isEmbeddable(Network::factory()->disabled()->create(['wall_url' => 'https://p.test/w'])))->toBeFalse()
        ->and(PartnerWall::isEmbeddable(Network::factory()->create(['wall_url' => 'https://p.test/w'])))->toBeTrue();
});
