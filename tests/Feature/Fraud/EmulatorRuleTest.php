<?php

use App\Enums\FraudAction;
use App\Enums\FraudStage;
use App\Fraud\Contracts\RenderGateRule;
use App\Fraud\FraudPipeline;
use App\Fraud\RuleResult;
use App\Fraud\Rules\EmulatorDeviceRule;
use App\Models\FraudRule;

require_once __DIR__.'/FraudFixtures.php';

/** A real mid-range Android, for the false-positive side of every assertion. */
const REAL_ANDROID_UA = 'Mozilla/5.0 (Linux; Android 13; SM-A536B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36';

function emulatorRuleBlocks(?string $userAgent, array $config = []): bool
{
    $click = fraudClick(['user_agent' => $userAgent]);
    FraudRule::query()->where('key', 'emulator_device')->delete();
    fraudRule('emulator_device', FraudStage::PreClick, FraudAction::Block, $config, weight: 45);

    return app(FraudPipeline::class)->evaluate(FraudStage::PreClick, fraudContext($click))->blocked;
}

it('blocks each seeded emulator signature', function (string $userAgent) {
    expect(emulatorRuleBlocks($userAgent))->toBeTrue();
})->with([
    'stock AVD' => 'Mozilla/5.0 (Linux; Android 11; sdk_gphone_x86 Build/RSR1.201013.001) Chrome/83.0.4103.106 Mobile Safari/537.36',
    'legacy AVD' => 'Mozilla/5.0 (Linux; Android 9; Android SDK built for x86 Build/PSR1.180720.012) Chrome/69.0.3497.100 Mobile Safari/537.36',
    'VirtualBox image' => 'Mozilla/5.0 (Linux; Android 7.1.2; vbox86p Build/N2G48H) Chrome/58.0.3029.83 Mobile Safari/537.36',
    'BlueStacks' => 'Mozilla/5.0 (Linux; Android 9; BlueStacks) Chrome/86.0.4240.198 Mobile Safari/537.36',
    'MEmu' => 'Mozilla/5.0 (Linux; Android 7.1.2; MEmu Build/N2G48H) Chrome/66.0.3359.158 Mobile Safari/537.36',
    'LDPlayer' => 'Mozilla/5.0 (Linux; Android 9; LDPlayer Build/PQ3A) Chrome/86.0.4240.198 Mobile Safari/537.36',
]);

it('leaves a real android handset alone', function () {
    expect(emulatorRuleBlocks(REAL_ANDROID_UA))->toBeFalse();
});

/**
 * The false-positive side the datasets above cannot cover: a bare English word
 * is a substring, so it matches any build string that merely contains it. This
 * is why `emulator` is not a default signature even though it reads like the
 * strongest possible one.
 */
it('does not ship a bare english word as a default signature', function () {
    $wrapper = 'Mozilla/5.0 (Linux; Android 12; SM-G991B Build/SP1A; wv) AppleWebKit/537.36 EmulatorQA/2.1 Chrome/107.0 Mobile Safari/537.36';

    expect(emulatorRuleBlocks($wrapper))->toBeFalse();
});

it('leaves an ordinary desktop browser alone', function () {
    $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15';

    expect(emulatorRuleBlocks($ua))->toBeFalse();
});

/** `ua_anomaly` owns the missing-UA case; two rules blocking on one fact is two events for one decision. */
it('leaves a missing user agent to ua_anomaly', function () {
    expect(emulatorRuleBlocks(null))->toBeFalse();
});

/**
 * The whole reason the list lives in `config`: an operator who finds a new farm
 * signature adds it from the panel, not in a deploy.
 */
it('picks up a signature added to the rule config without a deploy', function () {
    $ua = 'Mozilla/5.0 (Linux; Android 10; SomeNewFarmBuild) Chrome/90.0 Mobile Safari/537.36';

    expect(emulatorRuleBlocks($ua))->toBeFalse()
        ->and(emulatorRuleBlocks($ua, ['signatures' => ['somenewfarmbuild']]))->toBeTrue();
});

/** A configured list replaces the defaults rather than adding to them. */
it('honours a narrowed config list', function () {
    $stock = 'Mozilla/5.0 (Linux; Android 11; sdk_gphone_x86) Chrome/83.0 Mobile Safari/537.36';

    expect(emulatorRuleBlocks($stock, ['signatures' => ['bluestacks']]))->toBeFalse();
});

/**
 * The refusal names nothing. docs/05 §3 allows naming a category only where a
 * real person is helped by knowing; nobody reaches an offerwall on an emulator
 * by accident, so a named screen would only tell a farm what to change.
 */
it('refuses the render with a notice that names no rule', function () {
    fraudBoot();
    $rule = new EmulatorDeviceRule;
    $key = $rule->noticeKey(RuleResult::trigger('x', []));

    expect($rule)->toBeInstanceOf(RenderGateRule::class)
        ->and($key)->toBe('blocked_unavailable')
        ->and($key)->not->toContain('emulator');

    expect(__("wall.notice.{$key}.title"))->not->toBe("wall.notice.{$key}.title")
        ->and(__("wall.notice.{$key}.body"))->not->toBe("wall.notice.{$key}.body");
});

it('translates the refusal in arabic too', function () {
    app()->setLocale('ar');

    expect(__('wall.notice.blocked_unavailable.title'))->not->toBe('wall.notice.blocked_unavailable.title')
        ->and(__('wall.notice.blocked_unavailable.body'))->not->toBe('wall.notice.blocked_unavailable.body');
});
