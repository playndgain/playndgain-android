<?php

use App\Enums\FraudAction;
use App\Enums\FraudStage;
use App\Fraud\Contracts\RenderGateRule;
use App\Fraud\IpVerdict;
use App\Fraud\RuleRegistry;
use App\Models\FraudEvent;
use App\Models\OfferClick;
use Database\Factories\BannedEndUserFactory;
use Database\Seeders\FraudRuleSeeder;

require_once __DIR__.'/FraudFixtures.php';
require_once __DIR__.'/../Wall/WallFixtures.php';

/** A fixed IP verdict with the rule classes registered, as RenderGateTest does. */
function parityVerdict(IpVerdict $verdict): void
{
    fraudBoot();
    fakeIpVerdict($verdict);
}

function parityBlockRule(string $key): void
{
    fraudRule($key, FraudStage::PreClick, FraudAction::Block, weight: 45);
}

/*
 * Fraud parity between the iframe wall and the native SDKs.
 *
 * The SDKs reach the same routes the iframe does, so most parity is free. The
 * one place a native client sees less is the click: it opens in the system
 * browser, whose reduced User-Agent carries no model or build. These tests pin
 * that every check still fires with SDK parameters on the request, and that
 * the device hint the SDK sends can only make a verdict stricter.
 */

/** Chrome's reduced UA, as a Custom Tab sends it. No model, no build. */
const REDUCED_CHROME_UA = 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Mobile Safari/537.36';

const SDK_PARAMS = [
    'sdk' => 'android', 'sdk_v' => '1.0.0', 'model' => 'Pixel 7',
    'dh' => 'Pixel 7|panther|panther|tensor|google/panther/panther:14', 'sub1' => 'camp',
];

function parityClick(string $ua, array $extra, string $user): OfferClick
{
    [$app, $network] = wallFixture();
    $offer = wallOffer($network, ['countries' => [], 'devices' => []]);

    test()->withHeaders(['User-Agent' => $ua])
        ->get("/click/{$offer->id}?".http_build_query(['appid' => $app->app_id, 'userid' => $user, ...$extra]));

    return OfferClick::query()->withoutGlobalScopes()->where('end_user_id', $user)->latest('id')->firstOrFail();
}

it('runs every pre-click rule the same way with or without sdk parameters', function (string $ua) {
    parityVerdict(new IpVerdict(country: 'US'));
    $this->seed(FraudRuleSeeder::class);

    $iframe = parityClick($ua, [], 'IFRAME_USER');
    $sdk = parityClick($ua, SDK_PARAMS, 'SDK_USER');

    expect($sdk->blocked)->toBe($iframe->blocked)
        ->and($sdk->blocked_reason)->toBe($iframe->blocked_reason)
        ->and($sdk->risk_score)->toBe($iframe->risk_score);
})->with([
    'clean phone' => [WALL_UA],
    'emulator ua' => ['Mozilla/5.0 (Linux; Android 14; sdk_gphone64_arm64 Build/UE1A.230829.036; wv) AppleWebKit/537.36 Chrome/129.0 Mobile Safari/537.36'],
    'bot ua' => ['curl/8.4.0 something long enough to pass length'],
    'missing ua' => [''],
]);

it('blocks an emulator click from the sdk even though the browser ua is reduced', function () {
    parityVerdict(new IpVerdict(country: 'US'));
    parityBlockRule('emulator_device');

    $click = parityClick(REDUCED_CHROME_UA, [...SDK_PARAMS, 'dh' => 'sdk_gphone64_arm64|sdk_gphone64_arm64|emu64a|ranchu'], 'EMU_USER');

    expect($click->blocked)->toBeTrue()
        ->and($click->blocked_reason)->toBe('emulator_device')
        ->and($click->device_hint)->toBe('sdk_gphone64_arm64|sdk_gphone64_arm64|emu64a|ranchu');
});

it('lets a real phone through the same reduced ua', function () {
    parityVerdict(new IpVerdict(country: 'US'));
    parityBlockRule('emulator_device');

    expect(parityClick(REDUCED_CHROME_UA, SDK_PARAMS, 'PHONE_USER')->blocked)->toBeFalse();
});

it('never lets a clean hint clear an emulator ua', function () {
    parityVerdict(new IpVerdict(country: 'US'));
    parityBlockRule('emulator_device');

    $click = parityClick(
        'Mozilla/5.0 (Linux; Android 14; sdk_gphone64_arm64 Build/UE1A; wv) AppleWebKit/537.36 Chrome/129.0 Mobile Safari/537.36',
        [...SDK_PARAMS, 'dh' => 'Pixel 7'],
        'FORGED_USER',
    );

    expect($click->blocked)->toBeTrue()->and($click->blocked_reason)->toBe('emulator_device');
});

it('ignores a device hint that arrives without an sdk marker', function () {
    parityVerdict(new IpVerdict(country: 'US'));
    parityBlockRule('emulator_device');

    // An iframe user cannot be blocked by appending `dh=` to their own URL —
    // nor can anyone use it to get someone else's click refused.
    $click = parityClick(REDUCED_CHROME_UA, ['dh' => 'sdk_gphone64_arm64'], 'NO_SDK_USER');

    expect($click->blocked)->toBeFalse()->and($click->device_hint)->toBeNull();
});

it('refuses the wall render to an emulator on sdk evidence', function () {
    [$app] = wallFixture();
    parityVerdict(new IpVerdict(country: 'US'));
    parityBlockRule('emulator_device');

    $this->withHeaders(['User-Agent' => REDUCED_CHROME_UA])
        ->get(wallUrl($app, 'EMU_RENDER', [...SDK_PARAMS, 'dh' => 'sdk_gphone64_arm64']))
        ->assertForbidden();

    expect(FraudEvent::query()->where('rule_key', 'emulator_device')->count())->toBe(1);
});

it('writes a blocked banned_end_user click for a banned sdk user, same as the iframe', function () {
    parityVerdict(new IpVerdict(country: 'US'));
    [$app, $network] = wallFixture();
    $offer = wallOffer($network, ['countries' => [], 'devices' => []]);

    BannedEndUserFactory::new()->create([
        'publisher_id' => $app->publisher_id, 'app_id' => $app->id, 'end_user_id' => 'BANNED',
    ]);

    $this->withHeaders(['User-Agent' => REDUCED_CHROME_UA])
        ->get("/click/{$offer->id}?".http_build_query(['appid' => $app->app_id, 'userid' => 'BANNED', ...SDK_PARAMS]))
        ->assertForbidden();

    $click = OfferClick::query()->withoutGlobalScopes()->where('end_user_id', 'BANNED')->sole();

    expect($click->blocked)->toBeTrue()->and($click->blocked_reason)->toBe('banned_end_user');
});

it('covers every render-gate rule in the registry', function () {
    // A render-gate rule added later has to be thought about for the SDK too.
    // The IP-family rules read only the verdict, so the SDK sees exactly what
    // the iframe sees; `emulator_device` is the one that reads the client and
    // is covered above. Anything else that appears here needs its own case.
    $renderRules = collect(app(RuleRegistry::class)->all())
        ->filter(fn (string $class): bool => is_subclass_of($class, RenderGateRule::class))
        ->map(fn (string $class): string => $class::key())
        ->sort()->values()->all();

    expect($renderRules)->toBe([
        'datacenter_ip', 'emulator_device', 'ip_risk_score', 'residential_proxy_ip', 'tor_ip', 'vpn_proxy_ip',
    ]);
});
