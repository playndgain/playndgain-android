<?php

use App\Http\Controllers\ContactController;
use App\Http\Controllers\DocsController;
use App\Http\Controllers\DocsTextController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\McpController;
use App\Http\Controllers\NewsletterController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PostbackController;
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

// The footer's mailing list: the same plain POST to this app as the contact
// form, for the same reasons, and throttled for the same reason too.
Route::post('/newsletter', [NewsletterController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('newsletter.subscribe');

// Off the list. `signed` is the whole credential — the link lives in a mail
// footer, so it has to work with no session, and the signature is what stops a
// crawler or a stranger opting somebody else out by counting up the ids.
Route::get('/newsletter/unsubscribe/{subscriber}', [NewsletterController::class, 'unsubscribe'])
    ->middleware('signed')
    ->name('newsletter.unsubscribe');

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

// The docs as text, for the "Copy page" menu and for tools that read
// documentation. Registered before the page route so the dot in each name is
// never read as a slug.
Route::get('/docs/llms.txt', [DocsTextController::class, 'all'])->name('docs.llms');
Route::get('/docs/prompt.txt', [DocsTextController::class, 'prompt'])->name('docs.prompt');
Route::get('/docs/{slug}.md', [DocsTextController::class, 'page'])
    ->where('slug', '[A-Za-z0-9\-]+')
    ->name('docs.markdown');

// A read-only MCP server over the same pages. POST only; the controller
// answers 405 to anything else, as the transport specification asks.
Route::any('/mcp', McpController::class)
    ->middleware('throttle:60,1')
    ->name('mcp');

Route::get('/docs/{slug?}', DocsController::class)
    ->where('slug', '[A-Za-z0-9\-\/]+')
    ->name('docs');

// ---------------------------------------------------------------------------
// Offerwall  →  routes/wall.php
// ---------------------------------------------------------------------------
//
// Not here on purpose. Everything in this file inherits the `web` group —
// session, cookies, CSRF — and the wall is stateless by specification
// (docs/03). Its routes are registered from bootstrap/app.php without that
// group, so a consent POST from inside a publisher's iframe is never asked
// for a token it cannot hold.

// ---------------------------------------------------------------------------
// Inbound network postbacks  →  docs/04-conversions-postbacks.md
// ---------------------------------------------------------------------------
//
// One route; the network is resolved from the path segment against
// `networks.postback_key`. The segment used to be the driver key, and every row
// backfilled its key from its driver, so URLs already pasted into partner
// dashboards still land on the same network. CSRF is exempted for
// `postback/*` in bootstrap/app.php.
//
// Throttled per source IP — docs/11 §10. The controller writes an
// `incoming_postbacks` row before it authenticates anything, which is the right
// design (a network claiming "we sent you 400 conversions" is answerable only
// from those rows) and also means an unthrottled flood fills the table at link
// speed. The limit is deliberately generous: a network catching up after an
// outage sends a legitimate burst, and shedding that is how a publisher stops
// being paid for conversions we were told about.

// The publisher panel's Withdrawals and Payment details pages became one
// Payments page — docs/08 §10. Old bookmarks and mails land on it.
Route::redirect('/dashboard/withdrawals', '/dashboard/payments', 301);
Route::redirect('/dashboard/payment-methods', '/dashboard/payments', 301);

Route::match(['get', 'post'], '/postback/{postbackKey}', PostbackController::class)
    ->middleware('throttle:postback')
    ->name('postback');
