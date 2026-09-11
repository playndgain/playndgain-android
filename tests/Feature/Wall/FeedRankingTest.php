<?php

use App\Enums\OfferStatus;
use App\Models\Network;
use App\Services\Wall\OfferFeed;
use App\Services\Wall\WallGate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/WallFixtures.php';

/**
 * What the wall ranks on — and, just as much, what it does not.
 *
 * A platform connector is the first thing in this codebase that could tempt
 * the feed into knowing *whose* inventory an offer is: a Swaarm row and a
 * direct row now sit side by side on the same wall. The ordering is three
 * columns and none of them is the connector, so an offer bought through a
 * tenant competes on exactly the terms a direct one does. Asserted against the
 * SQL rather than the result order, because a result order can come out right
 * under a clause that is wrong.
 */
function rankingRequest(array $query = []): Request
{
    $request = Request::create('/wall', 'GET', $query);
    $request->headers->set('User-Agent', WALL_UA);

    return $request;
}

it('orders the feed on exactly three columns, none of them the network', function () {
    [$app, $network] = wallFixture();
    wallGeo('US');
    wallOffer($network, ['countries' => [], 'devices' => []]);

    $context = app(WallGate::class)->context(rankingRequest(['appid' => $app->app_id, 'userid' => 'USER_1']), $app);

    Cache::flush();
    DB::enableQueryLog();
    app(OfferFeed::class)->for($context);
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $catalogue = collect($log)
        ->pluck('query')
        ->first(fn (string $sql): bool => str_contains($sql, 'from `offers`') && str_contains($sql, 'join `networks`'));

    expect($catalogue)->not->toBeNull()
        ->and($catalogue)->toContain(
            'order by `offers`.`is_featured` desc, `offers`.`payout` desc, `networks`.`priority` desc'
        )
        // Nothing else may tie-break, and nothing may order on the connector.
        ->and(substr_count((string) $catalogue, 'order by'))->toBe(1)
        ->and(strtolower((string) $catalogue))->not->toContain('networks`.`driver')
        ->and(strtolower((string) $catalogue))->not->toContain('networks`.`name');
});

it('ranks a platform offer against a direct one on payout and priority alone', function () {
    [$app, $direct] = wallFixture();
    wallGeo('US');

    // The tenant's account is the *lower* priority of the two, so if the feed
    // knew or cared what a platform connector was, this is where it would show.
    $platform = Network::factory()->platform('swaarm')->create(['priority' => 1]);

    $rich = wallOffer($platform, ['name' => 'Tenant Rich', 'payout' => 4.00, 'countries' => [], 'devices' => []]);
    $poor = wallOffer($direct, ['name' => 'Direct Poor', 'payout' => 1.00, 'countries' => [], 'devices' => []]);
    $featured = wallOffer($direct, ['name' => 'Direct Featured', 'payout' => 0.10, 'is_featured' => true, 'countries' => [], 'devices' => []]);

    $context = app(WallGate::class)->context(rankingRequest(['appid' => $app->app_id, 'userid' => 'USER_1']), $app);

    Cache::flush();

    expect(collect(app(OfferFeed::class)->for($context))->pluck('name')->all())
        ->toBe([$featured->name, $rich->name, $poor->name]);

    // Same payout, and the direct network's higher priority is what breaks the
    // tie — the connector is not consulted in either direction.
    $rich->update(['payout' => 1.00]);
    $featured->update(['status' => OfferStatus::Inactive]);
    Cache::flush();

    expect(collect(app(OfferFeed::class)->for($context))->pluck('name')->all())
        ->toBe([$poor->name, $rich->name]);
});
