<?php

use App\Enums\DeviceType;
use App\Models\Network;
use App\Models\Setting;
use App\Services\Wall\DeviceDetector;
use App\Services\Wall\OfferFeed;
use App\Services\Wall\WallGate;
use Illuminate\Http\Request;

require_once __DIR__.'/WallFixtures.php';

function wallRequest(array $query = []): Request
{
    $request = Request::create('/wall', 'GET', $query);
    $request->headers->set('User-Agent', WALL_UA);

    return $request;
}

it('reads device, os, version and browser out of the user agent', function (string $ua, DeviceType $device, ?string $os, ?string $version, ?string $browser) {
    expect(app(DeviceDetector::class)->detect($ua))->toBe([
        'device' => $device,
        'os' => $os,
        'os_version' => $version,
        'browser' => $browser,
    ]);
})->with([
    [WALL_UA, DeviceType::Android, 'Android', '13', 'Chrome'],
    ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) AppleWebKit/605.1.15 Version/17.2 Mobile Safari/604.1', DeviceType::Ios, 'iOS', '17.2', 'Safari'],
    ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0 Safari/537.36 Edg/121.0', DeviceType::Desktop, 'Windows', '10.0', 'Edge'],
    ['Mozilla/5.0 (X11; Linux x86_64; rv:122.0) Gecko/20100101 Firefox/122.0', DeviceType::Desktop, 'Linux', null, 'Firefox'],
    ['curl/8.4.0', DeviceType::Other, null, null, null],
]);

it('drops an offer whose os floor is above the detected version', function () {
    [$app, $network] = wallFixture();
    wallGeo('US');

    $tooNew = wallOffer($network, ['name' => 'Android 15 Offer', 'countries' => [], 'devices' => [], 'os_min_version' => '15']);
    $fits = wallOffer($network, ['name' => 'Android 9 Offer', 'countries' => [], 'devices' => [], 'os_min_version' => '9']);

    wallGet($app)->assertOk()->assertDontSee($tooNew->name)->assertSee($fits->name);
});

it('sorts featured first, then payout, then network priority', function () {
    [$app, $network] = wallFixture();
    $slower = Network::factory()->create(['priority' => 1]);
    wallGeo('US');

    wallOffer($network, ['name' => 'Cheap Featured', 'payout' => 0.10, 'is_featured' => true, 'countries' => [], 'devices' => []]);
    wallOffer($network, ['name' => 'Rich Plain', 'payout' => 9.00, 'countries' => [], 'devices' => []]);
    wallOffer($slower, ['name' => 'Tied Low Priority', 'payout' => 9.00, 'countries' => [], 'devices' => []]);

    $context = app(WallGate::class)->context(
        wallRequest(['appid' => $app->app_id, 'userid' => 'USER_1']),
        $app,
    );

    expect(collect(app(OfferFeed::class)->for($context))->pluck('name')->all())
        ->toBe(['Cheap Featured', 'Rich Plain', 'Tied Low Priority']);
});

it('caps the wall at the configured offer limit', function () {
    [$app, $network] = wallFixture();
    wallGeo('US');
    Setting::set('wall_offers_limit', '2');

    foreach (range(1, 4) as $i) {
        wallOffer($network, ['name' => "Offer {$i}", 'countries' => [], 'devices' => []]);
    }

    $context = app(WallGate::class)->context(
        wallRequest(['appid' => $app->app_id, 'userid' => 'USER_1']),
        $app,
    );

    expect(app(OfferFeed::class)->for($context))->toHaveCount(2);
});

it('honours the country override and pins the test offer, only in test mode', function () {
    [$app, $network] = wallFixture();
    wallGeo(null);

    $german = wallOffer($network, ['name' => 'German Offer', 'countries' => ['DE'], 'devices' => []]);
    $test = wallOffer($network, ['name' => 'Test Offer', 'payout' => 0.01, 'countries' => [], 'devices' => []]);
    Setting::set('test_offer_id', (string) $test->id);

    $request = wallRequest(['appid' => $app->app_id, 'userid' => 'USER_1', 'country' => 'de']);
    $gate = app(WallGate::class);
    $feed = app(OfferFeed::class);

    // Off: the country parameter is ignored, so nothing places this user — and
    // an unplaced user gets an empty wall, not the worldwide inventory. The
    // test offer is ordinary inventory here and goes with the rest.
    $context = $gate->context($request, $app);
    $rows = $feed->for($context);

    expect($context->country)->toBeNull()
        ->and($rows)->toBe([]);

    $app->update(['test_mode' => true]);
    $context = $gate->context($request, $app);
    $rows = $feed->for($context);

    expect($context->country)->toBe('DE')
        ->and($rows[0]['name'])->toBe('Test Offer')
        ->and($rows[0]['is_test'])->toBeTrue()
        // Pinned once. It is eligible inventory as well, and the same card
        // twice — the second without the badge — reads as a bug in the wall.
        ->and(collect($rows)->pluck('name')->all())->toBe(['Test Offer', $german->name]);
});
