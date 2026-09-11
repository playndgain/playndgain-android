<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bind the admin panel before Filament's Authenticate runs on /log-viewer.
 * Without a current panel, getLoginUrl() and canAccessPanel() have nothing
 * to hang off, and the Gate sees a guest even when /admin is signed in.
 */
class SetAdminPanel
{
    public function handle(Request $request, Closure $next): Response
    {
        Filament::setCurrentPanel('admin');

        return $next($request);
    }
}
