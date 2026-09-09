<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restore the visitor IP when the TCP peer is Cloudflare.
 *
 * Orange-cloud traffic reaches origin as a Cloudflare edge address. Without
 * this, `$request->ip()` is that edge hop — ProxyCheck answers "Cloudflare,
 * Inc., not a VPN" — and the wall render gate never sees the VPN exit the
 * user is actually on. Cloudflare writes the real client in
 * `CF-Connecting-IP` and strips any client-supplied copy of that header.
 *
 * The header is trusted only when `REMOTE_ADDR` falls in Cloudflare's
 * published ranges: a direct-to-origin request forging the header must not
 * rewrite the peer IP.
 */
class TrustCloudflare
{
    public function handle(Request $request, Closure $next): Response
    {
        $peer = $request->server->get('REMOTE_ADDR');
        $visitor = $request->headers->get('CF-Connecting-IP');

        if (
            is_string($peer)
            && is_string($visitor)
            && filter_var($visitor, FILTER_VALIDATE_IP)
            && IpUtils::checkIp($peer, (array) config('playndgain.cloudflare_proxies', []))
        ) {
            $request->server->set('REMOTE_ADDR', $visitor);
        }

        return $next($request);
    }
}
