<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Log Viewer's JSON API. Admins already signed into /admin keep using the
 * session. Coding assistants send `Authorization: Bearer {token}` and are
 * limited to safe methods so a leaked token cannot delete files.
 */
class AuthenticateLogViewerApi
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->hasValidApiToken($request)) {
            abort_unless($request->isMethodSafe(), 403);

            return $next($request);
        }

        Filament::setCurrentPanel('admin');

        $user = Filament::auth()->user();

        if ($user === null) {
            abort(401);
        }

        abort_unless($user->isAdmin(), 403);

        return $next($request);
    }

    private function hasValidApiToken(Request $request): bool
    {
        $expected = (string) config('playndgain.log_viewer_api_token');

        if ($expected === '') {
            return false;
        }

        $header = (string) $request->header('Authorization', '');
        $token = str_starts_with($header, 'Bearer ') ? substr($header, 7) : '';

        return $token !== '' && hash_equals($expected, $token);
    }
}
