<?php

use App\Models\OfferClick;
use App\Services\Wall\WallGate;
use Illuminate\Http\Request;

require_once __DIR__.'/WallFixtures.php';

beforeEach(fn () => wallGeo('US'));

function latestClick(): OfferClick
{
    return OfferClick::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
}

it('carries valid sdk info, device hint and sub ids on every wall link', function () {
    [$app, $network] = wallFixture();
    wallOffer($network, ['countries' => [], 'devices' => []]);

    $html = wallGet($app, extra: [
        'sdk' => 'android', 'sdk_v' => '1.0.0', 'model' => 'Pixel 7',
        'dh' => 'Pixel 7|panther|google/panther/panther:14',
        'sub1' => 'campaign_a', 'sub5' => 'x-9',
    ])->assertOk()->getContent();

    // Blade escapes `&` in hrefs, so match on the encoded values only.
    expect($html)->toContain('sdk=android')
        ->toContain('sdk_v=1.0.0')
        ->toContain('model=Pixel+7')
        ->toContain('dh=Pixel+7%7Cpanther%7Cgoogle%2Fpanther%2Fpanther%3A14')
        ->toContain('sub1=campaign_a')
        ->toContain('sub5=x-9');
});

it('drops sdk values and sub ids that fail the whitelist', function (string $key, string $value) {
    [$app] = wallFixture();

    // `sdk=android` is always sent: without it `sdk_v`, `model` and `dh` are
    // dropped whatever the regex says, and the case would pass for nothing.
    $context = app(WallGate::class)->context(
        Request::create(wallUrl($app, extra: ['sdk' => 'android', $key => $value]), 'GET', server: ['HTTP_USER_AGENT' => WALL_UA]),
        $app,
    );

    parse_str($context->params(), $params);

    expect($params)->not->toHaveKey($key);
})->with([
    ['sdk_v', '1.0;drop'],
    ['model', str_repeat('a', 65)],
    ['model', '<script>'],
    ['dh', str_repeat('a', 257)],
    ['dh', 'a"b'],
    ['sub1', str_repeat('a', 101)],
    ['sub2', 'has space'],
]);

it('treats an unknown sdk value, and no sdk at all, as an iframe request', function (array $extra) {
    [$app] = wallFixture();

    $context = app(WallGate::class)->context(
        Request::create(wallUrl($app, extra: $extra), 'GET', server: ['HTTP_USER_AGENT' => WALL_UA]),
        $app,
    );

    expect($context->sdkPlatform)->toBeNull()
        ->and($context->sdkVersion)->toBeNull()
        ->and($context->deviceModel)->toBeNull()
        ->and($context->deviceHint)->toBeNull();
})->with([
    'unknown sdk' => [['sdk' => 'windows', 'sdk_v' => '1.0.0', 'model' => 'Pixel', 'dh' => 'sdk_gphone']],
    'no sdk' => [['sdk_v' => '1.0.0', 'model' => 'Pixel', 'dh' => 'sdk_gphone']],
]);

it('records sdk info, the device hint and sub ids on the click row', function () {
    [$app, $network] = wallFixture();
    $offer = wallOffer($network, ['countries' => [], 'devices' => []]);

    wallClick($app, $offer, extra: [
        'sdk' => 'ios', 'sdk_v' => '1.2.3', 'model' => 'iPhone15,2', 'dh' => 'iPhone15,2',
        'sub1' => 'a', 'sub3' => 'c', 'sub4' => 'bad value',
    ])->assertRedirect();

    $click = latestClick();

    expect($click->sdk_platform)->toBe('ios')
        ->and($click->sdk_version)->toBe('1.2.3')
        ->and($click->device_model)->toBe('iPhone15,2')
        ->and($click->device_hint)->toBe('iPhone15,2')
        ->and($click->sub1)->toBe('a')
        ->and($click->sub3)->toBe('c')
        ->and($click->sub4)->toBeNull();
});

it('leaves the sdk columns empty for an iframe click', function () {
    [$app, $network] = wallFixture();
    $offer = wallOffer($network, ['countries' => [], 'devices' => []]);

    wallClick($app, $offer)->assertRedirect();

    $click = latestClick();

    expect($click->sdk_platform)->toBeNull()
        ->and($click->device_hint)->toBeNull()
        ->and($click->sub1)->toBeNull();
});

it('keeps sub ids and sdk info through the consent screen', function () {
    wallConsentCountries(['US']);
    [$app] = wallFixture();

    $html = wallGet($app, extra: ['sub1' => 'abc', 'sdk' => 'android', 'dh' => 'Pixel 7'])->assertOk()->getContent();

    expect($html)->toContain('name="sub1" value="abc"')
        ->toContain('name="sdk" value="android"')
        ->toContain('name="dh" value="Pixel 7"')
        ->toContain('/wall/consent?appid='.$app->app_id);

    $this->withHeaders(['User-Agent' => WALL_UA])
        ->post('/wall/consent?appid='.$app->app_id, [
            'appid' => $app->app_id, 'userid' => 'USER_1', 'action' => 'accept',
            'sub1' => 'abc', 'sdk' => 'android', 'dh' => 'Pixel 7',
        ])
        ->assertRedirectContains('sub1=abc')
        ->assertRedirectContains('sdk=android');
});
