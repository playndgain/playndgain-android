<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ViewErrorBag;
use Spatie\ResponseCache\CacheProfiles\CacheAllSuccessfulGetRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * The landing route is the only response-cached page. HTML captured while
 * Vite is hot names `http://[::1]:5173/...`. Serve that after `npm run dev`
 * stops and the page is unstyled until the cache expires.
 *
 * The cache is also stood down for the one request that carries a footer
 * newsletter outcome — see `enabled()`.
 */
final class LandingCacheProfile extends CacheAllSuccessfulGetRequests
{
    /**
     * Checked before the cached copy is read, so returning false here serves a
     * freshly rendered page and stores nothing.
     *
     * The footer's subscribe form redirects back to the landing page with a
     * flash: "You are on the list", or the reason the address was refused. The
     * cached copy was rendered for nobody and carries neither, so the press
     * looked like it did nothing at all — the row was written and the page came
     * back identical. One uncached render for the one request that has
     * something to say to the person who made it; every other visitor still
     * gets the cache.
     */
    public function enabled(Request $request): bool
    {
        if (! parent::enabled($request)) {
            return false;
        }

        return ! $this->hasNewsletterOutcome($request);
    }

    /** A flash this request has to render, from either arm of the form. */
    private function hasNewsletterOutcome(Request $request): bool
    {
        if (! $request->hasSession()) {
            return false;
        }

        $session = $request->session();

        if ($session->has('newsletter')) {
            return true;
        }

        $errors = $session->get('errors');

        // The form validates into its own bag so the footer never shows the
        // contact form's mistakes; the bypass has to look in the same place.
        return $errors instanceof ViewErrorBag && $errors->hasBag('newsletter');
    }

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
