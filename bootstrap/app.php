<?php

use App\Http\Middleware\TrustCloudflare;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Spatie\ResponseCache\Middlewares\CacheResponse;
use Spatie\ResponseCache\Middlewares\DoNotCacheResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // The wall is stateless by specification (docs/03): its routes are
        // registered outside the `web` group so no session starts and no CSRF
        // token is demanded — a consent POST from inside a publisher's iframe
        // cannot carry one. Every other public route keeps the default group.
        then: fn () => require base_path('routes/wall.php'),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust upstream proxies (Valet, Herd, Cloudflare, Nginx, ALB) so
        // $request->isSecure(), client IP, and forwarded protocol/port are recognized.
        $middleware->trustProxies(at: '*');

        // Before anything reads `$request->ip()` — wall geo, ProxyCheck,
        // rate limiters, fraud events. Must be global: the `wall` group is
        // session-less and never inherits the `web` stack.
        $middleware->prepend(TrustCloudflare::class);

        // Networks POST to these endpoints from their own servers and will
        // never carry a CSRF token. Left in, every inbound conversion 419s in
        // production while the test suite stays green — Laravel skips CSRF
        // verification during tests, so no feature test would catch it.
        $middleware->preventRequestForgery(except: [
            'postback/*',
            // JSON-RPC from a coding assistant, which has no session to carry
            // a token. The server is read-only over public pages.
            'mcp',
        ]);

        // The offerwall is stateless by specification (docs/03): no session, no
        // cookie, cache-first, embedded in an iframe on someone else's domain.
        // The default `web` group would start a session and demand a CSRF
        // token, and a session-less iframe cannot carry one — the consent
        // POST would 419 for every EU user. These requests authenticate with
        // appid + userid + sig instead.
        $middleware->group('wall', [
            SubstituteBindings::class,
        ]);

        // Applied to the landing route and nothing else — docs/11 §2. The
        // package ships the alias for the old HTTP kernel, which this skeleton
        // does not have, so it is registered by hand here.
        $middleware->alias([
            'cacheResponse' => CacheResponse::class,
            'doNotCacheResponse' => DoNotCacheResponse::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A malformed `userid` is an end user editing the URL, not a crash, and
        // the wall is an iframe on someone else's page — an exception page is
        // the wrong thing to put there. It gets the same dead-end screen as
        // every other refusal. Registered here rather than in the controllers
        // because the 400 is thrown by `WallGate`, which all of them call.
        //
        // A 404 gets the same treatment: an app id or offer that does not exist
        // is a stale link, and the framework's own error page inside someone
        // else's iframe reads as their site being broken.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            // The native SDKs read JSON, never a page. A bad userid (400), a
            // bad signature (403), an unknown app, offer or path (404) or a
            // wrong method (405) is one status the SDK can branch on. Matched
            // on the path too: an unrouted `sdk/v1/*` URL has no route name.
            if (($request->routeIs('sdk.*') || $request->is('sdk/*')) && in_array($e->getStatusCode(), [400, 403, 404, 405], true)) {
                return response()
                    ->json(['status' => 'invalid'], $e->getStatusCode())
                    ->header('Cache-Control', 'private, no-store');
            }

            if (! in_array($e->getStatusCode(), [400, 404], true) || ! $request->routeIs('wall', 'wall.*')) {
                return null;
            }

            return response()
                ->view('wall.notice', [
                    'context' => null,
                    'title' => __('wall.notice.invalid.title'),
                    'body' => __('wall.notice.invalid.body'),
                ], $e->getStatusCode())
                ->header('Cache-Control', 'private, no-store');
        });
    })->create();
