<?php

use App\Http\Controllers\Wall\ClickController;
use App\Http\Controllers\Wall\ConsentController;
use App\Http\Controllers\Wall\HistoryController;
use App\Http\Controllers\Wall\OfferDetailController;
use App\Http\Controllers\Wall\OfferIconController;
use App\Http\Controllers\Wall\PartnerWallController;
use App\Http\Controllers\Wall\PrivacyController;
use App\Http\Controllers\Wall\WallController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Offerwall routes  →  docs/03-offerwall.md
|--------------------------------------------------------------------------
|
| Loaded from bootstrap/app.php, deliberately outside the `web` group. The
| wall is stateless by specification: no session, no cookie, no CSRF. It is
| an iframe on someone else's domain and authenticates by appid + userid +
| sig, not by a cookie it could never receive — and a consent POST from
| inside that frame cannot carry a CSRF token, so under `web` it returned
| 419 for every EU user while the test suite (where CSRF is off) stayed
| green. Only the `wall` middleware group (route-model binding) and the
| named rate limiters apply here; limits live in
| AppServiceProvider::rateLimiters().
|
*/

// ---------------------------------------------------------------------------
// Offerwall  →  docs/03-offerwall.md
// ---------------------------------------------------------------------------
//
// The `wall` group is stateless on purpose: no session, no cookie, no CSRF.
// The wall is an iframe on someone else's domain and authenticates by
// appid + userid + sig, not by a cookie it could never receive. Limits are
// defined in AppServiceProvider::rateLimiters().

Route::middleware('wall')->group(function (): void {
    Route::get('/wall', WallController::class)
        ->middleware('throttle:wall')
        ->name('wall');

    // What the offer actually asks of the user, before they leave for it: the
    // steps, what each one pays, and who it is open to. It is a page and not a
    // client-side expansion because it has to survive JS being off — the card
    // links straight here, and the dialog on the wall is an enhancement that
    // fetches this same URL.
    Route::get('/wall/offer/{offer}', OfferDetailController::class)
        ->middleware('throttle:wall')
        ->name('wall.offer');

    // The user's own clicks and what each one earned. It runs the full gate,
    // consent included: the history of a user is exactly the data a declined
    // consent refuses, so it must not be reachable by deep link either.
    Route::get('/wall/history', HistoryController::class)
        ->middleware('throttle:wall')
        ->name('wall.history');

    // A partner's own wall, framed. Server-rendered with its own URL rather
    // than a pane swap, for the same reason `wall.offer` is: the back button
    // behaves, it survives JS being off, and a publisher can deep-link one.
    Route::get('/wall/partners', [PartnerWallController::class, 'index'])
        ->middleware('throttle:wall')
        ->name('wall.partners');

    Route::get('/wall/partners/{network}', [PartnerWallController::class, 'show'])
        ->middleware('throttle:wall')
        ->name('wall.partner');

    Route::get('/click/{offer}', ClickController::class)
        ->middleware('throttle:click')
        ->name('wall.click');

    // Icons are proxied, never hotlinked — networks rotate and delete their CDN
    // assets without warning, and a wall of broken images reads as dead.
    Route::get('/img/offer/{offer}', OfferIconController::class)
        ->middleware('throttle:wall')
        ->name('wall.icon');

    Route::post('/wall/consent', [ConsentController::class, 'store'])
        ->middleware('throttle:wall')
        ->name('wall.consent');

    Route::get('/privacy/data', [PrivacyController::class, 'show'])
        ->middleware('throttle:privacy')
        ->name('privacy.data');

    Route::post('/privacy/delete', [PrivacyController::class, 'destroy'])
        ->middleware('throttle:privacy')
        ->name('privacy.delete');
});
