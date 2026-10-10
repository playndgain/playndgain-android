<?php

use App\Enums\AppStatus;
use App\Enums\BlacklistType;
use App\Enums\ConversionStatus;
use App\Enums\FraudAction;
use App\Enums\FraudStage;
use App\Enums\ProxyType;
use App\Fraud\IpVerdict;
use App\Models\Blacklist;
use App\Models\Consent;
use App\Models\Conversion;
use App\Models\FraudEvent;
use App\Models\IdentitySession;
use App\Services\Wall\SdkPayload;
use Database\Factories\BannedEndUserFactory;
use Database\Factories\OfferClickFactory;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/WallFixtures.php';
require_once __DIR__.'/../Fraud/FraudFixtures.php';

/** What each SDK actually sends: the platform WebView UA plus a suffix. */
const SDK_ANDROID_UA = 'Mozilla/5.0 (Linux; Android 14; Pixel 7 Build/UQ1A.240205.004; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/129.0 Mobile Safari/537.36 PlayndgainSDK/1.0.0';
const SDK_IOS_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 PlayndgainSDK/1.0.0';

function sdkGet(string $path, App\Models\App $app, string $user = 'USER_1', array $extra = [], string $ua = SDK_ANDROID_UA): TestResponse
{
    $query = http_build_query(['appid' => $app->app_id, 'userid' => $user, 'sdk' => 'android', 'sdk_v' => '1.0.0', ...$extra]);

    return test()->withHeaders(['User-Agent' => $ua, 'Accept' => 'application/json'])->get("/sdk/v1/{$path}?{$query}");
}

beforeEach(fn () => wallGeo('US'));

it('serves offers to the android sdk and filters by device for ios', function () {
    [$app, $network] = wallFixture();
    $any = wallOffer($network, ['name' => 'Any Device Offer', 'countries' => [], 'devices' => []]);
    $android = wallOffer($network, ['name' => 'Android Offer', 'countries' => [], 'devices' => ['android']]);

    $json = sdkGet('wall', $app)->assertOk()->assertJsonPath('status', 'ok')->json();
    expect(collect($json['data']['offers'])->pluck('id')->all())->toContain($any->id, $android->id);

    $ios = sdkGet('wall', $app, extra: ['sdk' => 'ios'], ua: SDK_IOS_UA)->assertOk()->json();
    expect(collect($ios['data']['offers'])->pluck('id')->all())->toContain($any->id)->not->toContain($android->id);
});

it('returns unplaced for a user agent that parses to no device', function () {
    [$app, $network] = wallFixture();
    wallOffer($network, ['countries' => [], 'devices' => []]);

    sdkGet('wall', $app, ua: 'MyApp/1 CFNetwork/1490 Darwin/23.0.0')
        ->assertOk()
        ->assertJsonPath('status', 'unplaced')
        ->assertJsonPath('data.offers', []);
});

it('refuses a missing signature on a signed app with json', function () {
    [$app] = wallFixture(['require_signed_wall' => true]);

    sdkGet('wall', $app)->assertForbidden()->assertExactJson(['status' => 'invalid']);
    sdkGet('wall', $app, extra: ['sig' => wallSig($app)])->assertOk()->assertJsonPath('status', 'ok');
});

it('answers a bad user id and an unknown app with json', function () {
    [$app] = wallFixture();

    sdkGet('wall', $app, user: 'has space')->assertStatus(400)->assertExactJson(['status' => 'invalid']);
    test()->getJson('/sdk/v1/wall?appid=99999999&userid=U')->assertNotFound()->assertExactJson(['status' => 'invalid']);
});

it('walks the consent gate and records the answer', function () {
    wallConsentCountries(['US']);
    [$app, $network] = wallFixture();
    wallOffer($network, ['countries' => [], 'devices' => []]);

    sdkGet('wall', $app)->assertOk()->assertJsonPath('status', 'consent_required')->assertJsonMissingPath('data');

    // Nothing processed before a yes, same as the iframe.
    expect(FraudEvent::query()->count())->toBe(0)
        ->and(IdentitySession::query()->count())->toBe(0);

    $post = fn (string $action) => test()->withHeaders(['User-Agent' => SDK_ANDROID_UA])
        ->postJson('/sdk/v1/consent?'.http_build_query(['appid' => $app->app_id, 'userid' => 'USER_1', 'sdk' => 'android']), ['action' => $action]);

    $post('accept')->assertOk()->assertJsonPath('status', 'ok');
    expect(Consent::query()->sole()->granted)->toBeTrue();

    $post('decline')->assertOk()->assertJsonPath('status', 'declined')->assertJsonPath('notice.key', 'declined');
    $post('maybe')->assertStatus(400)->assertExactJson(['status' => 'invalid']);
});

it('lands on offers after answering a reopened consent', function () {
    // A user who reopened their answer with consent=review and accepts must
    // see offers, not the consent screen again.
    wallConsentCountries(['US']);
    [$app, $network] = wallFixture();
    wallOffer($network, ['countries' => [], 'devices' => []]);

    test()->withHeaders(['User-Agent' => SDK_ANDROID_UA])
        ->postJson('/sdk/v1/consent?'.http_build_query(['appid' => $app->app_id, 'userid' => 'USER_1', 'sdk' => 'android', 'consent' => 'review']), ['action' => 'accept'])
        ->assertOk()
        ->assertJsonPath('status', 'ok');
});

it('answers a wrong method and an unknown sdk path with json', function () {
    [$app] = wallFixture();

    sdkGet('consent', $app)->assertStatus(405)->assertExactJson(['status' => 'invalid']);
    sdkGet('nope', $app)->assertNotFound()->assertExactJson(['status' => 'invalid']);
});

it('refuses a banned user without offers', function () {
    [$app, $network] = wallFixture();
    wallOffer($network, ['countries' => [], 'devices' => []]);
    BannedEndUserFactory::new()->create(['publisher_id' => $app->publisher_id, 'end_user_id' => 'USER_1']);

    sdkGet('wall', $app)->assertOk()->assertJsonPath('status', 'banned')->assertJsonMissingPath('data');
});

it('answers a paused app with unavailable', function () {
    [$app] = wallFixture(['status' => AppStatus::Paused]);

    sdkGet('wall', $app)->assertStatus(503)->assertJsonPath('status', 'unavailable');
});

it('drops a completed single-reward offer', function () {
    [$app, $network] = wallFixture();
    $done = wallOffer($network, ['countries' => [], 'devices' => [], 'is_multi_reward' => false]);
    Conversion::factory()->create([
        'app_id' => $app->id, 'publisher_id' => $app->publisher_id, 'offer_id' => $done->id,
        'network_id' => $network->id, 'end_user_id' => 'USER_1', 'status' => ConversionStatus::Approved,
    ]);

    expect(collect(sdkGet('wall', $app)->json('data.offers'))->pluck('id')->all())->not->toContain($done->id);
});

it('returns offer detail and refuses a blacklisted offer', function () {
    [$app, $network] = wallFixture();
    $offer = wallOffer($network, [
        'countries' => ['US', 'DE', 'zz1'], 'devices' => [],
        'goals' => [['name' => 'Install', 'payout' => 0.5], ['name' => 'Level 10', 'payout' => 1.5]],
    ]);

    sdkGet("offers/{$offer->id}", $app)->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('data.steps.0.name', 'Install')
        ->assertJsonPath('data.countries.0.code', 'US')
        ->assertJsonCount(2, 'data.countries')
        ->assertJsonPath('data.steps_label', '2 steps');

    Blacklist::query()->create([
        'publisher_id' => $app->publisher_id, 'app_id' => $app->id,
        'type' => BlacklistType::Offer, 'value' => (string) $offer->id,
    ]);
    Cache::flush();

    sdkGet("offers/{$offer->id}", $app)->assertStatus(410)->assertJsonPath('status', 'offer_gone');
});

it('never sends the upstream payout or a template string', function () {
    [$app, $network] = wallFixture(['currency_name' => 'Gems']);
    $offer = wallOffer($network, ['countries' => [], 'devices' => [], 'payout' => '1.234567']);

    foreach ([sdkGet('wall', $app), sdkGet("offers/{$offer->id}", $app), sdkGet('history', $app)] as $response) {
        $body = $response->assertOk()->getContent();

        expect($body)->not->toContain('"payout"')->not->toContain('1.234567');
    }

    $strings = sdkGet('wall', $app)->json('strings');
    $flat = collect(Arr::dot($strings));

    expect($strings)->not->toHaveKeys(['partner', 'menu'])
        ->and($strings['earn'])->toBe('Earn Gems')
        ->and($flat->filter(fn ($v) => is_string($v) && SdkPayload::isTemplate($v))->all())->toBe([]);
});

it('sends the arabic strings and rtl for lang=ar', function () {
    [$app] = wallFixture();

    sdkGet('wall', $app, extra: ['lang' => 'ar'])->assertOk()
        ->assertJsonPath('app.rtl', true)
        ->assertJsonPath('strings.title', trans('wall.title', [], 'ar'));
});

it('marks every endpoint private and no-store', function () {
    [$app, $network] = wallFixture();
    $offer = wallOffer($network, ['countries' => [], 'devices' => []]);

    foreach ([sdkGet('wall', $app), sdkGet("offers/{$offer->id}", $app), sdkGet('history', $app)] as $response) {
        expect($response->headers->get('Cache-Control'))->toContain('no-store');
    }
});

it('answers the rate limit with json', function () {
    config()->set('playndgain.rate_limits.wall_per_ip', 60);

    foreach (range(1, 60) as $ignored) {
        test()->getJson('/sdk/v1/wall?appid=99999999&userid=U');
    }

    test()->getJson('/sdk/v1/wall?appid=99999999&userid=U')
        ->assertStatus(429)
        ->assertExactJson(['status' => 'rate_limited']);
});

it('lists a click in history with the generic partner label only', function () {
    [$app, $network] = wallFixture();
    $offer = wallOffer($network, ['name' => 'Clicked Offer', 'countries' => [], 'devices' => []]);

    wallClick($app, $offer)->assertRedirect();

    sdkGet('history', $app)->assertOk()
        ->assertJsonPath('data.rows.0.name', 'Clicked Offer')
        ->assertJsonPath('data.rows.0.status', 'started')
        ->assertJsonPath('data.rows.0.partner', null);
});

it('never shows the network name a partner conversion fell back to', function () {
    // With no offer name on the postback, ConversionProcessor stores the
    // network's own name as offer_name. History must not repeat it.
    [$app] = wallFixture();
    $network = partnerNetwork(['name' => 'Fallback Partner Name']);
    $embed = OfferClickFactory::new()->create([
        'app_id' => $app->id, 'publisher_id' => $app->publisher_id,
        'offer_id' => null, 'network_id' => $network->id, 'end_user_id' => 'USER_1',
    ]);
    Conversion::factory()->create([
        'app_id' => $app->id, 'publisher_id' => $app->publisher_id, 'offer_id' => null,
        'network_id' => $network->id, 'offer_click_id' => $embed->id, 'end_user_id' => 'USER_1',
        'offer_name' => 'Fallback Partner Name', 'status' => ConversionStatus::Approved, 'is_test' => false,
    ]);

    $body = sdkGet('history', $app)->assertOk()
        ->assertJsonPath('data.rows.0.name', __('wall.partner.kicker'))
        ->getContent();
    expect($body)->not->toContain('Fallback Partner Name');

    $html = test()->withHeaders(['User-Agent' => WALL_UA])
        ->get('/wall/history?'.http_build_query(['appid' => $app->app_id, 'userid' => 'USER_1']))
        ->assertOk()->getContent();
    expect($html)->not->toContain('Fallback Partner Name');
});

it('records a ds token from the sdk in the identity ledger', function () {
    [$app] = wallFixture();
    $ds = rtrim(strtr(base64_encode((string) json_encode(['r' => 'android:tensor', 'c' => 8, 'm' => 8, 'p' => 2.625, 't' => 5, 'w' => 0])), '+/', '-_'), '=');

    sdkGet('wall', $app, extra: ['ds' => $ds])->assertOk();

    expect(IdentitySession::query()->sole()->ds_hash)->not->toBeNull();
});

/*
 * Render-level fraud parity: every render-gate rule refuses the SDK exactly as
 * it refuses the iframe, with the same notice and its own fraud_event.
 */
it('refuses the sdk wherever the render gate refuses the iframe', function (string $rule, IpVerdict $verdict, string $notice, array $config) {
    [$app, $network] = wallFixture();
    wallOffer($network, ['countries' => [], 'devices' => []]);
    fraudBoot();
    fakeIpVerdict($verdict);
    fraudRule($rule, FraudStage::PreClick, FraudAction::Block, $config, weight: 40);

    test()->withHeaders(['User-Agent' => WALL_UA])->get(wallUrl($app, 'IFRAME_USER'))
        ->assertForbidden()
        ->assertSee(__("wall.notice.{$notice}.title"));

    sdkGet('wall', $app, user: 'SDK_USER')->assertForbidden()
        ->assertJsonPath('status', 'blocked')
        ->assertJsonPath('notice.key', $notice)
        ->assertJsonMissingPath('data');

    expect(FraudEvent::query()->where('rule_key', $rule)->pluck('end_user_id')->sort()->values()->all())
        ->toBe(['IFRAME_USER', 'SDK_USER']);
})->with([
    'datacenter' => ['datacenter_ip', new IpVerdict(country: 'US', isDatacenter: true), 'blocked_datacenter', []],
    'vpn' => ['vpn_proxy_ip', new IpVerdict(country: 'US', isProxy: true, proxyType: ProxyType::Vpn), 'blocked_proxy', []],
    'tor' => ['tor_ip', new IpVerdict(country: 'US', isProxy: true, proxyType: ProxyType::Tor), 'blocked_proxy', []],
    'residential proxy' => ['residential_proxy_ip', new IpVerdict(country: 'US', isProxy: true, proxyType: ProxyType::Residential), 'blocked_proxy', []],
    'risk score' => ['ip_risk_score', new IpVerdict(country: 'US', riskScore: 99), 'blocked_risk', ['threshold' => 75]],
]);

it('refuses an emulator through the sdk exactly as through the iframe', function () {
    [$app] = wallFixture();
    fraudBoot();
    fakeIpVerdict(new IpVerdict(country: 'US'));
    fraudRule('emulator_device', FraudStage::PreClick, FraudAction::Block, weight: 45);

    $emulatorWebView = 'Mozilla/5.0 (Linux; Android 14; sdk_gphone64_arm64 Build/UE1A.230829.036; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/129.0 Mobile Safari/537.36';

    test()->withHeaders(['User-Agent' => $emulatorWebView])->get(wallUrl($app, 'IFRAME_EMU'))->assertForbidden();
    sdkGet('wall', $app, user: 'SDK_EMU', ua: $emulatorWebView.' PlayndgainSDK/1.0.0')
        ->assertForbidden()->assertJsonPath('notice.key', 'blocked_unavailable');

    // And on the hint alone, for an SDK whose UA came out clean.
    sdkGet('wall', $app, user: 'SDK_EMU_HINT', extra: ['dh' => 'sdk_gphone64_arm64|emu64a|ranchu'])
        ->assertForbidden()->assertJsonPath('notice.key', 'blocked_unavailable');
});

it('refuses the ios sdk wherever the render gate refuses the iframe', function () {
    [$app, $network] = wallFixture();
    wallOffer($network, ['countries' => [], 'devices' => []]);
    fraudBoot();
    fakeIpVerdict(new IpVerdict(country: 'US', isProxy: true, proxyType: ProxyType::Vpn));
    fraudRule('vpn_proxy_ip', FraudStage::PreClick, FraudAction::Block, weight: 40);

    sdkGet('wall', $app, user: 'IOS_VPN', extra: ['sdk' => 'ios', 'dh' => 'iPhone15,2'], ua: SDK_IOS_UA)
        ->assertForbidden()
        ->assertJsonPath('status', 'blocked')
        ->assertJsonPath('notice.key', 'blocked_proxy');
});
