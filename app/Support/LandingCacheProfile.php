<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Spatie\ResponseCache\CacheProfiles\CacheAllSuccessfulGetRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * The landing route is the only response-cached page. HTML captured while
 * Vite is hot names `http://[::1]:5173/...`. Serve that after `npm run dev`
 * stops and the page is unstyled until the cache expires.
 */
final class LandingCacheProfile extends CacheAllSuccessfulGetRequests
{
    public function shouldCacheRequest(Request $request): bool
    {
        if (Vite::isRunningHot()) {
            return false;
        }

        return parent::shouldCacheRequest($request);
    }

    public function shouldCacheResponse(Response $response): bool
    {
        if (! parent::shouldCacheResponse($response)) {
            return false;
        }

        $html = (string) $response->getContent();

        return ! str_contains($html, '@vite/client');
    }
}
