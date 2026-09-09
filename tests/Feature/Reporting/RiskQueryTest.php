<?php

use App\Enums\ConversionStatus;
use App\Enums\FraudActionTaken;
use App\Enums\FraudStage;
use App\Enums\SourceDimension;
use App\Models\App;
use App\Models\Conversion;
use App\Models\FraudEvent;
use App\Models\Network;
use App\Models\Offer;
use App\Models\Publisher;
use App\Services\Reporting\AnalyticsWindow;
use App\Services\Reporting\RiskQuery;
use Database\Factories\OfferClickFactory;
use Illuminate\Support\Carbon;

function riskPublisher(): array
{
    $publisher = Publisher::factory()->create();
    $app = App::factory()->create(['publisher_id' => $publisher->id]);
    $network = Network::factory()->create();
    $offer = Offer::factory()->create(['network_id' => $network->id]);

    return [$publisher, $app, $network, $offer];
}

function reversedConversion(array $attributes, Publisher $publisher, App $app, Network $network, Offer $offer): Conversion
{
    return Conversion::factory()->create([
        'publisher_id' => $publisher->id,
        'app_id' => $app->id,
        'network_id' => $network->id,
        'offer_id' => $offer->id,
        'offer_name' => $offer->name,
        'country' => 'EG',
        'ip' => '203.0.113.10',
        'status' => ConversionStatus::Reversed,
        'is_test' => false,
        'revenue' => '1.000000',
        'payout' => '0.700000',
        'created_at' => now()->subDays(10),
        'reversed_at' => now(),
        ...$attributes,
    ]);
}

function fraudRow(Publisher $publisher, App $app, array $context = [], array $attributes = []): FraudEvent
{
    $click = OfferClickFactory::new()->create([
        'app_id' => $app->id,
        'publisher_id' => $publisher->id,
    ]);

    return FraudEvent::query()->create([
        'rule_key' => 'ip_risk_score',
        'stage' => FraudStage::PreClick,
        'subject_type' => $click->getMorphClass(),
        'subject_id' => $click->getKey(),
        'app_id' => $app->id,
        'publisher_id' => $publisher->id,
        'end_user_id' => $click->end_user_id,
        'action_taken' => FraudActionTaken::Flagged,
        'context' => $context,
        'created_at' => now(),
        ...$attributes,
    ]);
}

beforeEach(function () {
    Carbon::setTestNow('2026-09-09 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('ranks chargebacks by payout and changes group key with the source', function () {
    [$heavy, $heavyApp, $network, $offer] = riskPublisher();
    [$light, $lightApp] = riskPublisher();

    reversedConversion(['payout' => '5.000000'], $heavy, $heavyApp, $network, $offer);
    reversedConversion(['payout' => '1.000000'], $light, $lightApp, $network, $offer);

    $query = RiskQuery::make(AnalyticsWindow::fromFilters(['range' => 'today']));
    $byPublisher = $query->chargebacksBy(SourceDimension::Publisher);

    expect($query->chargebacks())->toBe(['count' => 2, 'payout' => '6.000000'])
        ->and($byPublisher[0]->key)->toBe((string) $heavy->id)
        ->and($byPublisher[0]->payout)->toBe('5.000000')
        ->and($byPublisher[1]->key)->toBe((string) $light->id);

    $byOffer = $query->chargebacksBy(SourceDimension::Offer);

    expect($byOffer)->toHaveCount(1)
        ->and($byOffer[0]->key)->toBe((string) $offer->id)
        ->and($byOffer[0]->label)->toBe($offer->name);
});

it('hides another publisher when the window is scoped', function () {
    [$keep, $keepApp, $network, $offer] = riskPublisher();
    [$other, $otherApp] = riskPublisher();

    reversedConversion(['payout' => '2.000000'], $keep, $keepApp, $network, $offer);
    reversedConversion(['payout' => '9.000000'], $other, $otherApp, $network, $offer);

    $query = RiskQuery::make(AnalyticsWindow::fromFilters([
        'range' => 'today',
        'publisher_id' => $keep->id,
    ]));

    expect($query->chargebacks()['count'])->toBe(1)
        ->and($query->chargebacksBy(SourceDimension::Publisher))->toHaveCount(1)
        ->and($query->chargebacksBy(SourceDimension::Publisher)[0]->key)->toBe((string) $keep->id);
});

it('ignores test reversals and reversals outside reversed_at', function () {
    [$publisher, $app, $network, $offer] = riskPublisher();

    reversedConversion(['is_test' => true], $publisher, $app, $network, $offer);
    reversedConversion(['reversed_at' => now()->subDays(20)], $publisher, $app, $network, $offer);

    $query = RiskQuery::make(AnalyticsWindow::fromFilters(['range' => 'today']));

    expect($query->chargebacks()['count'])->toBe(0);
});

it('counts conversion statuses in the created_at window, not revenue', function () {
    [$publisher, $app, $network, $offer] = riskPublisher();

    Conversion::factory()->create([
        'publisher_id' => $publisher->id,
        'app_id' => $app->id,
        'network_id' => $network->id,
        'offer_id' => $offer->id,
        'status' => ConversionStatus::Approved,
        'revenue' => '40.000000',
        'payout' => '20.000000',
        'created_at' => now(),
    ]);
    Conversion::factory()->create([
        'publisher_id' => $publisher->id,
        'app_id' => $app->id,
        'network_id' => $network->id,
        'offer_id' => $offer->id,
        'status' => ConversionStatus::Pending,
        'revenue' => '10.000000',
        'created_at' => now(),
    ]);
    reversedConversion([], $publisher, $app, $network, $offer);

    $counts = RiskQuery::make(AnalyticsWindow::fromFilters(['range' => 'today']))->statusCounts();

    expect($counts)->toBe([
        'pending' => 1,
        'approved' => 1,
        'reversed' => 0,
        'rejected' => 0,
    ])
        ->and(array_sum($counts))->toBe(2);
});

it('puts a fraud event without context.ip in Unknown', function () {
    [$publisher, $app] = riskPublisher();

    fraudRow($publisher, $app, ['reason' => 'no ip stored']);
    fraudRow($publisher, $app, ['ip' => '198.51.100.2']);

    $query = RiskQuery::make(AnalyticsWindow::fromFilters(['range' => 'today']));
    $rows = $query->fraudBy(SourceDimension::Ip);

    $keys = array_column($rows, 'key');

    expect($keys)->toContain('unknown')
        ->and($keys)->toContain('198.51.100.2')
        ->and($query->fraudByRule()[0]->key)->toBe('ip_risk_score')
        ->and($query->fraudByRule()[0]->count)->toBe(2);
});

it('returns no fraud rows when a network filter is set', function () {
    [$publisher, $app, $network] = riskPublisher();
    fraudRow($publisher, $app, ['ip' => '198.51.100.2']);

    $query = RiskQuery::make(AnalyticsWindow::fromFilters([
        'range' => 'today',
        'network_id' => $network->id,
    ]));

    expect($query->fraudActions())->toBe(['blocked' => 0, 'flagged' => 0, 'scored' => 0])
        ->and($query->fraudByRule())->toBe([])
        ->and($query->latestFraud())->toBe([]);
});
