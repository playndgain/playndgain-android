<?php

use App\Enums\ConversionStatus;
use App\Fraud\Rules\AppDailyCapRule;
use App\Fraud\Rules\AutoBanThresholdRule;
use App\Fraud\Rules\ConversionGeoMismatchRule;
use App\Fraud\Rules\DuplicateConversionRule;
use App\Fraud\Rules\ImpossibleSpeedRule;
use App\Fraud\Rules\NoClickMatchRule;
use App\Fraud\Rules\PublisherSpikeRule;
use App\Fraud\Rules\RepeatOfferCompletionRule;
use App\Fraud\Rules\UserDailyCapRule;
use App\Fraud\Rules\UserDailyPayoutCapRule;
use App\Models\Network;
use App\Models\OfferClick;
use Carbon\CarbonInterface;
use Database\Factories\OfferClickFactory;

require_once __DIR__.'/FraudFixtures.php';

// --- duplicate_conversion ----------------------------------------------------

it('duplicate_conversion triggers when the network transaction id was already seen', function () {
    $click = fraudClick();
    $first = fraudConversion($click, ['network_txid' => 'NTX-DUPE']);

    // A second network row: the (network_id, network_txid) index cannot see
    // across rows, which is exactly the replay this rule is left to catch.
    $second = fraudConversion($click, [
        'network_txid' => 'NTX-DUPE',
        'network_id' => Network::factory()->create()->id,
    ]);

    $result = app(DuplicateConversionRule::class)
        ->evaluate(fraudContext($click, conversion: $second), []);

    expect($result->triggered)->toBeTrue()
        ->and($result->context['original_conversion_id'])->toBe($first->id)
        ->and($result->context['network_txid'])->toBe('NTX-DUPE');
});

it('duplicate_conversion does not trigger on a transaction id seen once', function () {
    $click = fraudClick();
    $conversion = fraudConversion($click, ['network_txid' => 'NTX-UNIQUE']);

    expect(app(DuplicateConversionRule::class)
        ->evaluate(fraudContext($click, conversion: $conversion), [])->triggered)->toBeFalse();
});

// --- repeat_offer_completion -------------------------------------------------

it('repeat_offer_completion triggers on a second completion of a single-reward offer', function () {
    $click = fraudClick(offer: ['is_multi_reward' => false]);
    fraudConversion($click);

    $second = fraudConversion($click, ['network_txid' => 'NTX-2']);

    $result = app(RepeatOfferCompletionRule::class)
        ->evaluate(fraudContext($click, conversion: $second), []);

    expect($result->triggered)->toBeTrue()
        ->and($result->context['previous_completions'])->toBe(1);
});

it('repeat_offer_completion does not trigger on a multi-reward offer', function () {
    $click = fraudClick(offer: ['is_multi_reward' => true]);
    fraudConversion($click);

    $second = fraudConversion($click, ['network_txid' => 'NTX-2']);

    expect(app(RepeatOfferCompletionRule::class)
        ->evaluate(fraudContext($click, conversion: $second), [])->triggered)->toBeFalse();
});

// --- impossible_speed --------------------------------------------------------

it('impossible_speed triggers when the conversion beats the configured floor', function () {
    $click = fraudClick(['created_at' => now()->subSeconds(3)]);

    $result = app(ImpossibleSpeedRule::class)
        ->evaluate(fraudContext($click), ['min_seconds' => 15]);

    expect($result->triggered)->toBeTrue()
        ->and($result->context['elapsed_seconds'])->toBeLessThan(15)
        ->and($result->context['min_seconds'])->toBe(15);
});

it('impossible_speed does not trigger once the floor is cleared', function () {
    $click = fraudClick(['created_at' => now()->subSeconds(60)]);

    expect(app(ImpossibleSpeedRule::class)
        ->evaluate(fraudContext($click), ['min_seconds' => 15])->triggered)->toBeFalse();
});

// --- no_click_match ----------------------------------------------------------

it('no_click_match triggers when the conversion resolved no click', function () {
    $click = fraudClick();
    $conversion = fraudConversion($click, ['offer_click_id' => null, 'click_id' => 'ffffffff-0000-0000-0000-000000000000']);

    $result = app(NoClickMatchRule::class)
        ->evaluate(fraudContext($click, click: null, conversion: $conversion), []);

    expect($result->triggered)->toBeTrue()
        ->and($result->context['click_id'])->toBe('ffffffff-0000-0000-0000-000000000000');
});

it('no_click_match does not trigger when a click resolved', function () {
    $click = fraudClick();

    expect(app(NoClickMatchRule::class)->evaluate(fraudContext($click), [])->triggered)->toBeFalse();
});

// --- conversion_geo_mismatch -------------------------------------------------

it('conversion_geo_mismatch triggers when the two countries differ', function () {
    $click = fraudClick(['country' => 'US']);
    $conversion = fraudConversion($click, ['country' => 'VN']);

    $result = app(ConversionGeoMismatchRule::class)
        ->evaluate(fraudContext($click, conversion: $conversion), []);

    expect($result->triggered)->toBeTrue()
        ->and($result->context['click_country'])->toBe('US')
        ->and($result->context['conversion_country'])->toBe('VN');
});

it('conversion_geo_mismatch does not trigger when one side is unplaced', function () {
    $click = fraudClick(['country' => 'US']);
    $conversion = fraudConversion($click, ['country' => null]);

    expect(app(ConversionGeoMismatchRule::class)
        ->evaluate(fraudContext($click, country: null, conversion: $conversion), [])->triggered)->toBeFalse();
});

// --- app_daily_cap -----------------------------------------------------------

it('app_daily_cap triggers once the app reaches its own cap', function () {
    $click = fraudClick(app: ['daily_conversion_cap' => 2]);
    fraudConversion($click);
    fraudConversion($click, ['network_txid' => 'NTX-2']);

    $result = app(AppDailyCapRule::class)->evaluate(fraudContext($click), []);

    expect($result->triggered)->toBeTrue()
        ->and($result->context['conversions_today'])->toBe(2)
        ->and($result->context['cap_source'])->toBe('apps.daily_conversion_cap');
});

it('app_daily_cap does not trigger when the app has no cap configured', function () {
    $click = fraudClick(app: ['daily_conversion_cap' => null]);
    fraudConversion($click);

    expect(app(AppDailyCapRule::class)->evaluate(fraudContext($click), [])->triggered)->toBeFalse();
});

it('app_daily_cap ignores rejected conversions so a fraud reject cannot eat the cap', function () {
    $click = fraudClick(app: ['daily_conversion_cap' => 2]);
    fraudConversion($click, ['status' => ConversionStatus::Rejected]);
    fraudConversion($click, ['network_txid' => 'NTX-2', 'status' => ConversionStatus::Rejected]);

    expect(app(AppDailyCapRule::class)->evaluate(fraudContext($click), [])->triggered)->toBeFalse();
});

// --- user_daily_cap ----------------------------------------------------------

it('user_daily_cap triggers at the configured maximum', function () {
    $click = fraudClick();
    fraudConversion($click);
    fraudConversion($click, ['network_txid' => 'NTX-2']);
    fraudConversion($click, ['network_txid' => 'NTX-3']);

    $result = app(UserDailyCapRule::class)->evaluate(fraudContext($click), ['max' => 3]);

    expect($result->triggered)->toBeTrue()
        ->and($result->context['conversions_today'])->toBe(3);
});

it('user_daily_cap does not trigger one under the maximum', function () {
    $click = fraudClick();
    fraudConversion($click);
    fraudConversion($click, ['network_txid' => 'NTX-2']);

    expect(app(UserDailyCapRule::class)->evaluate(fraudContext($click), ['max' => 3])->triggered)->toBeFalse();
});

// --- user_daily_payout_cap ---------------------------------------------------

it('user_daily_payout_cap triggers when today plus this payout clears the ceiling', function () {
    $click = fraudClick();
    fraudConversion($click, ['payout' => '40.000000']);

    $result = app(UserDailyPayoutCapRule::class)
        ->evaluate(fraudContext($click, payout: '10.500000'), ['max_usd' => 50]);

    expect($result->triggered)->toBeTrue()
        ->and($result->context['payout_today'])->toBe('40.000000')
        ->and($result->context['total_usd'])->toBe('50.500000');
});

it('user_daily_payout_cap does not trigger exactly on the ceiling', function () {
    $click = fraudClick();
    fraudConversion($click, ['payout' => '40.000000']);

    expect(app(UserDailyPayoutCapRule::class)
        ->evaluate(fraudContext($click, payout: '10.000000'), ['max_usd' => 50])->triggered)->toBeFalse();
});

// --- publisher_spike ---------------------------------------------------------

it('publisher_spike triggers when today beats the publisher baseline rate', function () {
    $click = fraudClick();

    // Baseline: 100 clicks, 5 conversions over the previous week — 5%.
    fraudSeedTraffic($click, clicks: 100, conversions: 5, at: now()->subDays(3));
    // Today: 60 clicks, 55 conversions — 92%.
    fraudSeedTraffic($click, clicks: 60, conversions: 55, at: now());

    $result = app(PublisherSpikeRule::class)
        ->evaluate(fraudContext($click), ['multiplier' => 5, 'min_conversions' => 50]);

    expect($result->triggered)->toBeTrue()
        ->and($result->context['today_conversions'])->toBe(55)
        ->and($result->context['baseline_conversions'])->toBe(5);
});

it('publisher_spike does not trigger below the minimum conversion floor', function () {
    $click = fraudClick();

    fraudSeedTraffic($click, clicks: 100, conversions: 5, at: now()->subDays(3));
    fraudSeedTraffic($click, clicks: 10, conversions: 10, at: now());

    expect(app(PublisherSpikeRule::class)
        ->evaluate(fraudContext($click), ['multiplier' => 5, 'min_conversions' => 50])->triggered)->toBeFalse();
});

// --- auto_ban_threshold ------------------------------------------------------

it('auto_ban_threshold does not trigger one block under the threshold', function () {
    $click = fraudClick();
    fraudSeedBlocks($click, 4);

    expect(app(AutoBanThresholdRule::class)
        ->evaluate(fraudContext($click), ['blocks' => 5, 'window_hours' => 24])->triggered)->toBeFalse();
});

it('auto_ban_threshold triggers at exactly the threshold', function () {
    $click = fraudClick();
    fraudSeedBlocks($click, 5);

    $result = app(AutoBanThresholdRule::class)
        ->evaluate(fraudContext($click), ['blocks' => 5, 'window_hours' => 24]);

    expect($result->triggered)->toBeTrue()
        ->and($result->context['blocked_events'])->toBe(5);
});

/**
 * Clicks and their conversions on one publisher, stamped at a given moment.
 */
function fraudSeedTraffic(OfferClick $sample, int $clicks, int $conversions, CarbonInterface $at): void
{
    $made = OfferClickFactory::new()->count($clicks)->create([
        'app_id' => $sample->app_id,
        'publisher_id' => $sample->publisher_id,
        'offer_id' => $sample->offer_id,
        'network_id' => $sample->network_id,
        'created_at' => $at,
    ]);

    foreach ($made->take($conversions) as $click) {
        fraudConversion($click, [
            'network_txid' => 'SPIKE-'.$click->id,
            'created_at' => $at,
        ]);
    }
}
