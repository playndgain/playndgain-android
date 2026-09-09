<?php

use App\Fraud\Contracts\IpIntelligence;
use App\Fraud\IpVerdict;
use App\Models\App;
use App\Models\Network;
use App\Models\Offer;
use App\Models\Publisher;
use App\Models\Setting;
use App\Services\Wall\WallContext;
use App\Services\Wall\WallGate;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;

/**
 * Shared arrangement for the offerwall suite.
 *
 * Pest only auto-loads `tests/Pest.php`, so each file in this directory
 * `require_once`s this one.
 */
const WALL_UA = 'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Mobile Safari/537.36';

/**
 * A publisher, an active app, and a driver-backed network to hang offers on.
 *
 * @param  array<string, mixed>  $app
 * @param  array<string, mixed>  $publisher
 * @return array{App, Network}
 */
function wallFixture(array $app = [], array $publisher = [], string $driver = 'fake'): array
{
    // `networks.driver` is unique, so a second fixture in the same test shares
    // the first one's network — which is also what two publishers pulling the
    // same inventory looks like in production.
    $network = Network::query()->where('driver', $driver)->first()
        ?? Network::factory()->create(['driver' => $driver, 'priority' => 10]);

    $owner = Publisher::factory()->create($publisher);
    $placement = App::factory()->create(['publisher_id' => $owner->id, ...$app]);

    return [$placement, $network];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function wallOffer(Network $network, array $attributes = []): Offer
{
    return Offer::factory()->create(['network_id' => $network->id, ...$attributes]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function partnerNetwork(array $attributes = []): Network
{
    return Network::factory()->create([
        'wall_url' => 'https://p.test/w?s={sub_id}&u={user_id}',
        ...$attributes,
    ]);
}

/**
 * The real binding places nobody (no GeoLite2 on disk), which is exactly the
 * unknown-country path — so a test that wants a country has to say so.
 */
function wallGeo(?string $country): void
{
    app()->instance(IpIntelligence::class, new class($country) implements IpIntelligence
    {
        public function __construct(private readonly ?string $country) {}

        public function inspect(string $ip, bool $consented = false): IpVerdict
        {
            return new IpVerdict($this->country);
        }
    });
}

/**
 * @param  array<string, string>  $extra
 */
function wallUrl(App $app, string $user = 'USER_1', array $extra = []): string
{
    return '/wall?'.http_build_query(['appid' => $app->app_id, 'userid' => $user, ...$extra]);
}

/** Every request carries a real device UA — the wall filters on it. */
function wallGet(App $app, string $user = 'USER_1', array $extra = []): TestResponse
{
    return test()->withHeaders(['User-Agent' => WALL_UA])->get(wallUrl($app, $user, $extra));
}

/**
 * @param  array<string, string>  $extra
 */
function wallClick(App $app, Offer $offer, string $user = 'USER_1', array $extra = []): TestResponse
{
    $query = http_build_query(['appid' => $app->app_id, 'userid' => $user, ...$extra]);

    return test()->withHeaders(['User-Agent' => WALL_UA])->get("/click/{$offer->id}?{$query}");
}

/**
 * @param  array<string, string>  $extra
 */
function wallDetail(App $app, Offer $offer, string $user = 'USER_1', array $extra = []): TestResponse
{
    $query = http_build_query(['appid' => $app->app_id, 'userid' => $user, ...$extra]);

    return test()->withHeaders(['User-Agent' => WALL_UA])->get("/wall/offer/{$offer->id}?{$query}");
}

function wallSig(App $app, string $user = 'USER_1'): string
{
    return hash('sha256', $app->app_id.$user.(string) $app->secret_key);
}

/**
 * @param  list<string>  $codes
 */
function wallConsentCountries(array $codes = ['DE']): void
{
    Setting::set('consent_required_countries', json_encode($codes));
}

/**
 * A real WallContext, built the way a wall controller builds one.
 *
 * @param  array<string, string>  $extra
 */
function partnerContext(App $app, string $user = 'USER_1', array $extra = []): WallContext
{
    $request = Request::create(wallUrl($app, $user, $extra), 'GET', server: ['HTTP_USER_AGENT' => WALL_UA]);

    return app(WallGate::class)->context($request, $app);
}
