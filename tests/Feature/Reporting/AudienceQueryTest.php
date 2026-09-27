<?php

use App\Enums\IdentityKind;
use App\Models\App;
use App\Models\IdentitySession;
use App\Models\Publisher;
use App\Models\User;
use App\Services\Reporting\AudienceQuery;
use App\Services\Reporting\AudienceWindow;
use Illuminate\Support\Carbon;

/**
 * Rows are written directly: IdentityLedger::recordWall() dedupes per hour on
 * a key with no time in it, so repeated calls under setTestNow() would drop
 * the very rows these tests count.
 */
function audienceSession(App $app, string $user, string $at, ?string $country = null): IdentitySession
{
    return IdentitySession::query()->create([
        'kind' => IdentityKind::Wall,
        'publisher_id' => $app->publisher_id,
        'app_id' => $app->id,
        'end_user_id' => $user,
        'country' => $country,
        'ip' => '203.0.113.10',
        'ua_hash' => str_repeat('a', 64),
        'created_at' => $at,
    ]);
}

function audience(array $filters = []): AudienceQuery
{
    return AudienceQuery::for(AudienceWindow::fromFilters($filters));
}

function audienceApp(?Publisher $publisher = null): App
{
    return App::factory()->create(['publisher_id' => ($publisher ?? Publisher::factory()->create())->id]);
}

beforeEach(function () {
    Carbon::setTestNow('2026-09-09 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('counts one user once however many sessions they have in an app', function () {
    $app = audienceApp();

    audienceSession($app, 'U1', '2026-09-09 08:00:00');
    audienceSession($app, 'U1', '2026-09-09 09:00:00');
    audienceSession($app, 'U1', '2026-09-09 11:00:00');

    expect(audience()->todayDau())->toBe(1)
        ->and(audience()->mau())->toBe(1);
});

it('counts the same user id in two apps as two identities', function () {
    $publisher = Publisher::factory()->create();
    $first = audienceApp($publisher);
    $second = audienceApp($publisher);

    audienceSession($first, 'SAME', '2026-09-09 08:00:00');
    audienceSession($second, 'SAME', '2026-09-09 08:00:00');

    expect(audience()->todayDau())->toBe(2)
        ->and(audience()->mau())->toBe(2);
});

it('ignores panel sessions', function () {
    $app = audienceApp();

    IdentitySession::query()->create([
        'kind' => IdentityKind::Panel,
        'user_id' => User::factory()->create()->id,
        'publisher_id' => $app->publisher_id,
        'app_id' => $app->id,
        'end_user_id' => 'PANEL',
        'ip' => '198.51.100.1',
        'ua_hash' => str_repeat('b', 64),
        'created_at' => '2026-09-09 10:00:00',
    ]);

    expect(audience()->todayDau())->toBe(0)
        ->and(audience()->mau())->toBe(0)
        ->and(audience()->rangeActive())->toBe(0);
});

it('splits today and yesterday at midnight in the application timezone', function (string $timezone) {
    config(['app.timezone' => $timezone]);
    date_default_timezone_set($timezone);
    Carbon::setTestNow(Carbon::parse('2026-09-09 00:30:00', $timezone));

    $app = audienceApp();

    audienceSession($app, 'LATE', '2026-09-08 23:50:00');
    audienceSession($app, 'EARLY', '2026-09-09 00:10:00');
    audienceSession($app, 'OLDER', '2026-09-07 23:59:59');

    expect(audience()->todayDau())->toBe(1)
        ->and(audience()->yesterdayDau())->toBe(1);
})->with(['UTC', 'Asia/Dubai', 'America/New_York'])->after(function () {
    config(['app.timezone' => 'UTC']);
    date_default_timezone_set('UTC');
});

it('bounds MAU at 30 calendar days including today', function () {
    $app = audienceApp();

    audienceSession($app, 'FIRST_DAY', '2026-08-11 00:00:00');
    audienceSession($app, 'TOO_OLD', '2026-08-10 23:59:59');
    audienceSession($app, 'NOW', '2026-09-09 12:00:00');
    audienceSession($app, 'FUTURE', '2026-09-09 12:00:01');

    expect(audience()->mau())->toBe(2);
});

it('averages DAU over 30 complete days with quiet days as zeros', function () {
    $app = audienceApp();

    // Three active complete days: 2 + 1 + 3 = 6 user-days over 30 days.
    audienceSession($app, 'A', '2026-09-08 10:00:00');
    audienceSession($app, 'B', '2026-09-08 11:00:00');
    audienceSession($app, 'A', '2026-09-01 10:00:00');
    audienceSession($app, 'A', '2026-08-10 10:00:00');
    audienceSession($app, 'B', '2026-08-10 10:00:00');
    audienceSession($app, 'C', '2026-08-10 10:00:00');
    // Outside the average: today, and the 31st day back.
    audienceSession($app, 'D', '2026-09-09 10:00:00');
    audienceSession($app, 'E', '2026-08-09 10:00:00');

    $query = audience();

    expect($query->averageDau())->toBe(6 / 30)
        ->and($query->completeDays())->toHaveCount(30)
        ->and($query->daily())->toHaveCount(31)
        ->and($query->daily()->first())->toBe(3)
        ->and($query->daily()->last())->toBe(1);
});

it('scopes every metric to the publisher and app filters', function () {
    $publisher = Publisher::factory()->create();
    $mine = audienceApp($publisher);
    $mySecond = audienceApp($publisher);
    $theirs = audienceApp();

    audienceSession($mine, 'M1', '2026-09-09 08:00:00', 'DE');
    audienceSession($mySecond, 'M2', '2026-09-08 08:00:00', 'FR');
    audienceSession($theirs, 'T1', '2026-09-09 08:00:00', 'US');
    audienceSession($theirs, 'T2', '2026-09-08 08:00:00', 'US');

    $byPublisher = audience(['publisher_id' => (string) $publisher->id]);
    $byApp = audience(['app_id' => $mine->id]);

    expect([$byPublisher->todayDau(), $byPublisher->yesterdayDau(), $byPublisher->mau(), $byPublisher->rangeActive()])
        ->toBe([1, 1, 2, 2])
        ->and($byPublisher->averageDau())->toBe(1 / 30)
        ->and($byPublisher->countries()->all())->toBe(['DE' => 1, 'FR' => 1])
        ->and([$byApp->todayDau(), $byApp->yesterdayDau(), $byApp->mau(), $byApp->rangeActive()])
        ->toBe([1, 0, 1, 1])
        ->and($byApp->countries()->all())->toBe(['DE' => 1])
        ->and(audience(['publisher_id' => $publisher->id, 'app_id' => $theirs->id])->mau())->toBe(0);
});

it('groups known countries and keeps unknown apart', function () {
    $app = audienceApp();

    audienceSession($app, 'U1', '2026-09-09 08:00:00', 'US');
    audienceSession($app, 'U2', '2026-09-08 08:00:00', 'US');
    audienceSession($app, 'U3', '2026-09-07 08:00:00', 'DE');
    audienceSession($app, 'U4', '2026-09-06 08:00:00');
    audienceSession($app, 'U5', '2026-09-05 08:00:00');

    $query = audience();

    expect($query->countries()->all())->toBe(['US' => 2, 'DE' => 1])
        ->and($query->unknownCountry())->toBe(2)
        ->and($query->rangeActive())->toBe(5)
        ->and($query->rangeActive())->toBe($query->mau());
});

it('puts a user who moved country under their latest known country', function () {
    $app = audienceApp();

    // Moved from FR to DE.
    audienceSession($app, 'MOVER', '2026-09-01 08:00:00', 'FR');
    audienceSession($app, 'MOVER', '2026-09-08 08:00:00', 'DE');
    // Latest lookup failed: falls back to their own earlier country.
    audienceSession($app, 'LAPSED', '2026-09-02 08:00:00', 'ES');
    audienceSession($app, 'LAPSED', '2026-09-09 08:00:00');

    expect(audience()->countries()->all())->toBe(['DE' => 1, 'ES' => 1])
        ->and(audience()->unknownCountry())->toBe(0);
});

it('reads the range filter only for countries and the range summary', function () {
    $app = audienceApp();

    audienceSession($app, 'TODAY', '2026-09-09 08:00:00', 'US');
    audienceSession($app, 'EARLIER', '2026-09-01 08:00:00', 'DE');
    audienceSession($app, 'LAST_MONTH', '2026-08-20 08:00:00', 'FR');

    $today = audience(['range' => 'today']);
    $lastMonth = audience(['range' => 'last_month']);

    expect($today->countries()->all())->toBe(['US' => 1])
        ->and($today->mau())->toBe(3)
        ->and($lastMonth->countries()->all())->toBe(['FR' => 1])
        ->and($lastMonth->todayDau())->toBe(1)
        ->and(audience(['range' => 'month'])->rangeActive())->toBe(2);
});

it('returns zeros and a null ratio on an empty ledger', function () {
    $query = audience();

    expect([$query->todayDau(), $query->yesterdayDau(), $query->mau(), $query->rangeActive(), $query->unknownCountry()])
        ->toBe([0, 0, 0, 0, 0])
        ->and($query->averageDau())->toBe(0.0)
        ->and($query->stickiness())->toBeNull()
        ->and($query->countries())->toBeEmpty()
        ->and($query->daily()->sum())->toBe(0);
});

it('reports historical rows without a country as unknown', function () {
    $app = audienceApp();

    audienceSession($app, 'LEGACY_A', '2026-09-03 08:00:00');
    audienceSession($app, 'LEGACY_B', '2026-09-09 08:00:00');

    $query = audience();

    expect($query->countries())->toBeEmpty()
        ->and($query->unknownCountry())->toBe(2)
        ->and($query->stickiness())->toBe(50.0);
});
