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
    )
    ->withMiddleware(function (Middleware $middleware): void {
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
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($e->getStatusCode() !== 400 || ! $request->routeIs('wall', 'wall.*')) {
                return null;
            }

            return response()
                ->view('wall.notice', [
                    'context' => null,
                    'title' => __('wall.notice.invalid.title'),
                    'body' => __('wall.notice.invalid.body'),
                ], 400)
                ->header('Cache-Control', 'private, no-store');
        });
    })->create();
