<?php

use App\Enums\FraudAction;
use App\Enums\FraudStage;
use App\Filament\Admin\Pages\Protection;
use App\Fraud\IpScoreHistogram;
use App\Models\FraudRule;

/**
 * The risk score distribution — design spec §4.5.
 *
 * It exists because `ip_risk_score` runs at a threshold well under the score its
 * vendor calls suspicious, and nothing else can say what share of real traffic
 * that refuses.
 */
it('counts a scored render and ignores an unscored one', function () {
    IpScoreHistogram::record(42);
    IpScoreHistogram::record(42);
    IpScoreHistogram::record(null);

    $scores = IpScoreHistogram::scores(1);

    expect($scores[42])->toBe(2)
        ->and(IpScoreHistogram::total($scores))->toBe(2);
});

/**
 * A null score is "nobody rated this address", which is most of them until a
 * provider is configured. Counting it as zero would put a spike at the bottom of
 * the chart that is really an absence of data.
 */
it('does not record an absent score as a zero', function () {
    IpScoreHistogram::record(null);

    expect(IpScoreHistogram::scores(1)[0])->toBe(0);
});

it('refuses a score outside the vendor range rather than storing it', function () {
    IpScoreHistogram::record(-1);
    IpScoreHistogram::record(101);

    expect(IpScoreHistogram::total(IpScoreHistogram::scores(1)))->toBe(0);
});

/**
 * `IpRiskScoreRule` triggers at `>=`, so the page must count from the threshold
 * itself. Off by one here misreports the exact boundary an operator is placing.
 */
it('counts from the threshold inclusive, matching the rule', function () {
    IpScoreHistogram::record(26);
    IpScoreHistogram::record(27);
    IpScoreHistogram::record(90);

    $scores = IpScoreHistogram::scores(1);

    expect(IpScoreHistogram::atOrAbove($scores, 27))->toBe(2)
        ->and(IpScoreHistogram::atOrAbove($scores, 28))->toBe(1);
});

it('folds a perfect score into the top band rather than leaving a band of one', function () {
    IpScoreHistogram::record(100);
    IpScoreHistogram::record(90);

    $bands = IpScoreHistogram::bands(IpScoreHistogram::scores(1));

    expect($bands)->toHaveCount(10)
        ->and(end($bands)['label'])->toBe('90–100')
        ->and(end($bands)['count'])->toBe(2);
});

it('places every score in exactly one band', function () {
    foreach (range(0, 100) as $score) {
        IpScoreHistogram::record($score);
    }

    $bands = IpScoreHistogram::bands(IpScoreHistogram::scores(1));

    expect(array_sum(array_column($bands, 'count')))->toBe(101);
});

it('reads the live threshold off the rule row rather than a constant', function () {
    FraudRule::query()->where('key', 'ip_risk_score')->delete();
    FraudRule::query()->create([
        'key' => 'ip_risk_score', 'name' => 'x', 'description' => 'x',
        'stage' => FraudStage::PreClick, 'enabled' => true,
        'action' => FraudAction::Block, 'score_weight' => 40,
        'config' => ['threshold' => 27],
    ]);

    IpScoreHistogram::record(30);
    IpScoreHistogram::record(10);

    $page = new Protection;
    $page->setScoreWindow(7);
    $distribution = $page->getScoreDistributionProperty();

    expect($distribution['threshold'])->toBe(27)
        ->and($distribution['blocking'])->toBeTrue()
        ->and($distribution['refused'])->toBe(1)
        ->and($distribution['total'])->toBe(2)
        ->and($distribution['share'])->toBe(50.0);
});

it('marks exactly the bands the threshold refuses', function () {
    FraudRule::query()->where('key', 'ip_risk_score')->delete();
    FraudRule::query()->create([
        'key' => 'ip_risk_score', 'name' => 'x', 'description' => 'x',
        'stage' => FraudStage::PreClick, 'enabled' => true,
        'action' => FraudAction::Block, 'score_weight' => 40,
        'config' => ['threshold' => 27],
    ]);

    IpScoreHistogram::record(50);

    $page = new Protection;
    $refused = array_column($page->getScoreDistributionProperty()['bands'], 'refused', 'floor');

    // 27 sits inside the 20–29 band, so that band and everything above it is
    // partly or wholly refused; 10–19 is entirely below and must not be marked.
    expect($refused[10])->toBeFalse()
        ->and($refused[20])->toBeTrue()
        ->and($refused[90])->toBeTrue();
});

it('only offers windows it can actually serve', function () {
    $page = new Protection;

    $page->setScoreWindow(999);

    expect($page->scoreWindow)->toBe(7)
        ->and(IpScoreHistogram::WINDOWS)->toBe([1, 7, 30]);
});
