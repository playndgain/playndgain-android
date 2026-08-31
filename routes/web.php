<?php

use App\Http\Controllers\ContactController;
use App\Http\Controllers\DocsController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PostbackController;
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
| Public routes
|--------------------------------------------------------------------------
|
| Owned by the orchestrator. Phase agents add their controllers and wire them
| here only through the placeholders below — this file is the one place where
| the surfaces meet, so it is edited in one place.
|
| The Filament panels (/admin, /dashboard) register their own routes from
| their panel providers and are deliberately absent here.
|
*/

// ---------------------------------------------------------------------------
// Marketing site  →  docs/09-landing-site.md              (phase 6, agent C)
// ---------------------------------------------------------------------------

// Cached for an hour, and on this route only — docs/11 §2. The landing page is
// the one public page that runs aggregate counts; Blade is cheap enough that
// the docs and legal pages, whose Markdown is already cached as parsed HTML, do
// not need it. The deploy sequence clears this after `npm run build`, because
// cached HTML names hashed asset files.
Route::get('/', LandingController::class)
    ->middleware('cacheResponse')
    ->name('landing');

// ---------------------------------------------------------------------------
// Written pages  →  docs/09-landing-site.md §7                (phase 6, agent C)
// ---------------------------------------------------------------------------
//
// Legal, about, advertise and status are Markdown through the same parser the
// documentation uses (docs/09 §6 puts legal on that pipeline explicitly), so
// one controller serves all six from resources/pages/*.md.
//
// The route table is the allowlist. `where()` on the legal slug and a `page`
// default on each literal path mean nothing user-supplied ever reaches
// resource_path(), which is why PageController carries no traversal guard.

Route::get('/legal/{page}', PageController::class)
    ->where('page', 'privacy|terms|publisher-agreement')
    ->name('legal');

Route::get('/about', PageController::class)->defaults('page', 'about')->name('about');
Route::get('/advertise', PageController::class)->defaults('page', 'advertise')->name('advertise');

// ponytail: a written page, not a monitor. External HTTP checks are an external
// tool (docs/11 §7 — UptimeRobot or Better Stack) because an in-app status page
// cannot report that the app is down. The board's URL goes into
// resources/pages/status.md once one exists.
Route::get('/status', PageController::class)->defaults('page', 'status')->name('status');

// The form posts to this app: no CORS preflight, no third-party form service,
// no JavaScript between the button and the mailbox. Throttled because a public
// endpoint that sends mail is a public endpoint that sends mail for anyone.
Route::get('/contact', [ContactController::class, 'show'])->name('contact');
Route::post('/contact', [ContactController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('contact.send');

// ---------------------------------------------------------------------------
// Impersonation exit  →  docs/07-admin-panel.md §5
// ---------------------------------------------------------------------------
//
// Entering impersonation is an action inside the admin panel; leaving it has to
// be reachable from the publisher panel, which has no admin routes.

Route::get('/impersonate/stop', ImpersonationController::class)
    ->middleware('auth')
    ->name('impersonate.stop');

// ---------------------------------------------------------------------------
// Developer docs  →  docs/10-developer-docs.md
// ---------------------------------------------------------------------------

Route::get('/docs/{slug?}', DocsController::class)
    ->where('slug', '[A-Za-z0-9\-\/]+')
    ->name('docs');

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

// ---------------------------------------------------------------------------
// Inbound network postbacks  →  docs/04-conversions-postbacks.md
// ---------------------------------------------------------------------------
//
// One route, driver resolved from the path segment. CSRF is exempted for
// `postback/*` in bootstrap/app.php.
//
// Throttled per source IP — docs/11 §10. The controller writes an
// `incoming_postbacks` row before it authenticates anything, which is the right
// design (a network claiming "we sent you 400 conversions" is answerable only
// from those rows) and also means an unthrottled flood fills the table at link
// speed. The limit is deliberately generous: a network catching up after an
// outage sends a legitimate burst, and shedding that is how a publisher stops
// being paid for conversions we were told about.

Route::match(['get', 'post'], '/postback/{driver}', PostbackController::class)
    ->middleware('throttle:postback')
    ->name('postback');
