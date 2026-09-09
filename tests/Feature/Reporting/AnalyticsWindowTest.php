<?php

use App\Services\Reporting\AnalyticsWindow;
use App\Services\Reporting\StatsQuery;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-09-09 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('defaults an unknown range to 7d', function () {
    $window = AnalyticsWindow::fromFilters(['range' => 'nope']);

    expect($window->from->toDateString())->toBe('2026-09-03')
        ->and($window->to->toDateString())->toBe('2026-09-09')
        ->and($window->previousFrom->toDateString())->toBe('2026-08-27')
        ->and($window->previousTo->toDateString())->toBe('2026-09-02');
});

it('maps today, month and last_month windows', function (string $range, array $dates) {
    $window = AnalyticsWindow::fromFilters(['range' => $range]);

    expect($window->from->toDateString())->toBe($dates[0])
        ->and($window->to->toDateString())->toBe($dates[1])
        ->and($window->previousFrom->toDateString())->toBe($dates[2])
        ->and($window->previousTo->toDateString())->toBe($dates[3]);
})->with([
    'today' => ['today', ['2026-09-09', '2026-09-09', '2026-09-08', '2026-09-08']],
    'month' => ['month', ['2026-09-01', '2026-09-09', '2026-08-01', '2026-08-31']],
    'last_month' => ['last_month', ['2026-08-01', '2026-08-31', '2026-07-01', '2026-07-31']],
    '30d' => ['30d', ['2026-08-11', '2026-09-09', '2026-07-12', '2026-08-10']],
]);

it('drops non-numeric filter ids and normalises countries', function () {
    $window = AnalyticsWindow::fromFilters([
        'publisher_id' => 'abc',
        'network_id' => '',
        'app_id' => null,
        'countries' => ['us', 'EG', 'x', ''],
    ]);

    expect($window->publisherId)->toBeNull()
        ->and($window->networkId)->toBeNull()
        ->and($window->appId)->toBeNull()
        ->and($window->countries)->toBe(['US', 'EG']);
});

it('keeps numeric ids', function () {
    $window = AnalyticsWindow::fromFilters([
        'publisher_id' => '12',
        'network_id' => 4,
        'app_id' => '9',
    ]);

    expect($window->publisherId)->toBe(12)
        ->and($window->networkId)->toBe(4)
        ->and($window->appId)->toBe(9);
});

it('returns a StatsQuery from stats()', function () {
    expect(AnalyticsWindow::fromFilters([])->stats())->toBeInstanceOf(StatsQuery::class)
        ->and(AnalyticsWindow::fromFilters([])->previousStats())->toBeInstanceOf(StatsQuery::class);
});
