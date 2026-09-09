<?php

use App\Support\LandingCacheProfile;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * The landing route is response-cached for an hour. HTML captured while
 * `npm run dev` is up names `http://[::1]:5173/...`. Serve that after Vite
 * stops and the page is unstyled until the cache expires.
 */
it('refuses to cache HTML that still points at the Vite dev server', function () {
    $profile = new LandingCacheProfile;

    $hot = new Response(
        '<script type="module" src="http://[::1]:5173/@vite/client"></script>',
        200,
        ['Content-Type' => 'text/html'],
    );

    $built = new Response(
        '<link rel="stylesheet" href="/build/assets/app-D-X5jUV8.css">',
        200,
        ['Content-Type' => 'text/html'],
    );

    expect($profile->shouldCacheResponse($hot))->toBeFalse()
        ->and($profile->shouldCacheResponse($built))->toBeTrue();
});

/**
 * The cached copy was rendered for nobody. Serving it to the visitor who just
 * pressed Subscribe swallows both halves of the answer — the confirmation and
 * the reason an address was refused — and the press looks like it did nothing.
 *
 * RESPONSE_CACHE_ENABLED is false under phpunit, so this asks the profile
 * directly rather than driving it through the middleware.
 */
function requestWithSession(callable $fill): Request
{
    $session = new Store('test', new ArraySessionHandler(120));
    $fill($session);

    $request = Request::create('/');
    $request->setLaravelSession($session);

    return $request;
}

it('stands the cache down for the visitor who just subscribed', function () {
    config(['responsecache.enabled' => true]);

    $request = requestWithSession(fn (Store $s) => $s->put('newsletter', 'You are on the list.'));

    expect((new LandingCacheProfile)->enabled($request))->toBeFalse();
});

it('stands the cache down for the visitor whose address was refused', function () {
    config(['responsecache.enabled' => true]);

    $bag = (new ViewErrorBag)->put('newsletter', new MessageBag(['email' => ['Not an address.']]));
    $request = requestWithSession(fn (Store $s) => $s->put('errors', $bag));

    expect((new LandingCacheProfile)->enabled($request))->toBeFalse();
});

it('caches the page for everybody else', function () {
    config(['responsecache.enabled' => true]);

    // A plain visitor, and one carrying the contact form's errors rather than
    // the footer's — neither has anything on this page addressed to them.
    $plain = requestWithSession(fn (Store $s) => null);
    $contact = requestWithSession(fn (Store $s) => $s->put(
        'errors',
        (new ViewErrorBag)->put('default', new MessageBag(['email' => ['Not an address.']])),
    ));

    expect((new LandingCacheProfile)->enabled($plain))->toBeTrue()
        ->and((new LandingCacheProfile)->enabled($contact))->toBeTrue();
});

it('still honours the global off switch', function () {
    config(['responsecache.enabled' => false]);

    expect((new LandingCacheProfile)->enabled(requestWithSession(fn (Store $s) => null)))->toBeFalse();
});
