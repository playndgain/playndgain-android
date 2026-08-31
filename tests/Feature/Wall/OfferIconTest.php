<?php

use App\Http\Controllers\Wall\OfferIconController;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/WallFixtures.php';

/**
 * ORCHESTRATOR: `routes/web.php` is outside this phase's scope, so the route is
 * declared here to cover the controller. It has to be added to the `wall` group
 * before the wall renders icons in production:
 *
 *     Route::get('/img/offer/{offer}', OfferIconController::class)->name('wall.icon');
 */
beforeEach(function () {
    Storage::fake('local');

    Route::middleware(SubstituteBindings::class)
        ->get('/img/offer/{offer}', OfferIconController::class);
});

it('proxies an icon once and serves the local copy afterwards', function () {
    [, $network] = wallFixture();
    $offer = wallOffer($network, ['icon_url' => 'https://cdn.example.test/icon.png']);

    Http::fake(['cdn.example.test/*' => Http::response(base64_decode('iVBORw0KGgo='), 200, ['Content-Type' => 'image/png'])]);

    $response = test()->get("/img/offer/{$offer->id}")->assertOk();

    // A year, immutable: the copy is keyed by offer ID and replaced by a sync.
    expect($response->headers->get('Cache-Control'))
        ->toContain('max-age=31536000')
        ->toContain('immutable');

    test()->get("/img/offer/{$offer->id}")->assertOk();

    // Never hotlinked, and never fetched twice: networks delete their CDN
    // assets without warning.
    Http::assertSentCount(1);
    Storage::disk('local')->assertExists("offer-icons/{$offer->id}.png");
});

it('404s a missing icon and does not retry it on every card', function () {
    [, $network] = wallFixture();
    $offer = wallOffer($network, ['icon_url' => 'https://cdn.example.test/gone.png']);

    Http::fake(['cdn.example.test/*' => Http::response('', 404)]);

    test()->get("/img/offer/{$offer->id}")->assertNotFound();
    test()->get("/img/offer/{$offer->id}")->assertNotFound();

    Http::assertSentCount(1);
});

it('404s an offer that has no icon at all', function () {
    [, $network] = wallFixture();
    $offer = wallOffer($network, ['icon_url' => null]);

    Http::fake();

    test()->get("/img/offer/{$offer->id}")->assertNotFound();

    Http::assertNothingSent();
});
