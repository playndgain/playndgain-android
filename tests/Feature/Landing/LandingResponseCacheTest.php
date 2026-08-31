<?php

use App\Support\LandingCacheProfile;
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
