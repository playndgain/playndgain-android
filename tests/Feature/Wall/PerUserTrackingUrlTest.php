<?php

use App\Http\Controllers\Wall\ClickController;
use App\Models\Network;
use App\Models\Offer;
use App\Models\OfferClick;
use Database\Factories\BannedEndUserFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/WallFixtures.php';

/**
 * A network whose links are minted per user: the stored link belongs to a
 * probe, and a real click must get its own or nothing.
 */
const PROBE_LINK = 'https://www.ayetstudios.com/s2s/go/example-slug/289948/1000001/TOKEN-probe?pub_id=PUB-00000&sub_id_int=PL-00000&adslot_id=10000';

const USER_LINK = 'https://www.ayetstudios.com/s2s/go/example-slug/289948/1000002/TOKEN-user?pub_id=PUB-00000&sub_id_int=PL-00000&adslot_id=10000';

/** @return array{0: App\Models\App, 1: Network, 2: Offer} */
function perUserFixture(): array
{
    [$app, $network] = wallFixture(driver: 'ayetstudios');
    $network->update(['credentials' => ['adslot_id' => '10000', 'coins_per_usd' => '1000']]);
    wallGeo('US');

    $offer = wallOffer($network, [
        'external_id' => '289948',
        'tracking_url' => PROBE_LINK,
        'countries' => ['US'],
        'devices' => [],
    ]);

    return [$app, $network->fresh(), $offer];
}

/** @param  list<array{id: int, tracking_link: string}>  $offers */
function fakeUserOffers(array $offers): void
{
    Http::fake(['www.ayetstudios.com/*' => Http::response(['status' => 'success', 'num_offers' => count($offers), 'offers' => $offers])]);
}

it('sends the user to their own link with our click id on it', function () {
    [$app, , $offer] = perUserFixture();
    fakeUserOffers([['id' => 289948, 'tracking_link' => USER_LINK]]);

    $response = wallClick($app, $offer);

    $click = OfferClick::query()->withoutGlobalScopes()->sole();

    $response->assertStatus(302)->assertRedirect(USER_LINK.'&custom_1='.$click->click_id);

    expect($click->blocked)->toBeFalse();

    Http::assertSent(function (ClientRequest $request) use ($click) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        // Asked as this user, from where they are, and never under the
        // publisher's own user id.
        return str_starts_with($request->url(), 'https://www.ayetstudios.com/offers/offerwall_api/10000?')
            && preg_match('/^[0-9a-f]{32}$/', $query['external_identifier']) === 1
            && $query['external_identifier'] !== 'USER_1'
            && $query['ip'] === $click->ip
            && $query['user_agent'] === WALL_UA;
    });
});

it('gives the same user the same identifier and a different user another', function () {
    [$app, , $offer] = perUserFixture();
    fakeUserOffers([['id' => 289948, 'tracking_link' => USER_LINK]]);

    wallClick($app, $offer, 'USER_1');
    wallClick($app, $offer, 'USER_2');

    $identifiers = collect(Http::recorded())->map(function (array $pair) {
        parse_str((string) parse_url($pair[0]->url(), PHP_URL_QUERY), $query);

        return $query['external_identifier'];
    });

    expect($identifiers)->toHaveCount(2)
        ->and($identifiers[0])->not->toBe($identifiers[1]);
});

it('never falls back to the stored link when the user is not offered it', function () {
    [$app, , $offer] = perUserFixture();
    fakeUserOffers([['id' => 111111, 'tracking_link' => 'https://www.ayetstudios.com/s2s/go/x/111111/1/T?pub_id=PUB-00000']]);

    $response = wallClick($app, $offer);

    $response->assertStatus(410)->assertSee(__('wall.notice.offer_gone.title'));

    expect($response->headers->get('Location'))->toBeNull()
        ->and($response->getContent())->not->toContain('TOKEN-probe')
        ->and($response->getContent())->not->toContain('ayetstudios');

    $click = OfferClick::query()->withoutGlobalScopes()->sole();

    expect($click->blocked)->toBeTrue()
        ->and($click->blocked_reason)->toBe(ClickController::UNAVAILABLE_FOR_USER);
});

it('treats a throttled or failed lookup as unavailable, and backs off after a 429', function () {
    [$app, , $offer] = perUserFixture();
    Http::fake(['www.ayetstudios.com/*' => Http::response(['status' => 'error', 'error' => 'Too many consecutive API requests, throttled.'], 429)]);

    wallClick($app, $offer, 'USER_1')->assertStatus(410);
    wallClick($app, $offer, 'USER_2')->assertStatus(410);

    // The second click did not call a network that just told us to stop.
    Http::assertSentCount(1);

    expect(OfferClick::query()->withoutGlobalScopes()->where('blocked', true)->count())->toBe(2);
});

it('asks once per user and reuses the answer across offers', function () {
    [$app, $network, $offer] = perUserFixture();
    $other = wallOffer($network, ['external_id' => '281296', 'tracking_url' => 'https://probe.test/281296', 'countries' => ['US'], 'devices' => []]);

    fakeUserOffers([
        ['id' => 289948, 'tracking_link' => USER_LINK],
        ['id' => 281296, 'tracking_link' => 'https://www.ayetstudios.com/s2s/go/example-slug/281296/1000002/TOKEN-user?pub_id=PUB-00000'],
    ]);

    wallClick($app, $offer)->assertStatus(302);
    wallClick($app, $other)->assertStatus(302)
        ->assertRedirectContains('/281296/1000002/TOKEN-user?pub_id=PUB-00000&custom_1=');

    Http::assertSentCount(1);
});

it('never asks the network about a click the fraud engine blocked', function () {
    [$app, , $offer] = perUserFixture();
    fakeUserOffers([['id' => 289948, 'tracking_link' => USER_LINK]]);

    BannedEndUserFactory::new()->create(['publisher_id' => $app->publisher_id, 'end_user_id' => 'USER_1']);

    wallClick($app, $offer)->assertForbidden();

    Http::assertNothingSent();
});
