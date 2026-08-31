<?php

namespace App\Providers\Filament;

use App\Filament\Support\PanelSwitcher;
use App\Support\Identity;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Admin panel. Full platform control — networks, offers, publishers, money,
 * fraud, logs.
 *
 * Access is `role = admin` AND confirmed 2FA. The role half is enforced by
 * User::canAccessPanel(); the 2FA half by `isRequired` below, which routes an
 * admin without 2FA to the set-up page before anything else. The spec sketched
 * two custom middleware for this — Filament v5 does both, so there is no
 * bespoke middleware to keep in sync with the panel config.
 *
 * The requirement is config-driven so local development is not gated behind an
 * authenticator app. It defaults to ON — see config/advault.php.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            // Named, not just styled. Two dark dashboards on one domain sharing
            // a brand are easy to confuse, and this is the one that moves money.
            //
            // Filament renders brandLogo *instead of* brandName, so the name
            // survives only in the browser tab — which is the other place you
            // look to tell two open dashboards apart. Inside the panel the
            // topbar badge says which one this is, once, on the right.
            ->brandName(fn () => Identity::name().' Admin')
            ->brandLogo(fn () => view('components.brand-mark', ['size' => 26]))
            ->brandLogoHeight('1.75rem')
            ->favicon(asset('favicon.ico'))
            ->login()
            ->profile()
            ->userMenuItems(PanelSwitcher::items('admin'))
            // Directly after the logo, reading as part of the lockup: the
            // panel name belongs to the brand, not to the account controls
            // at the far end of the bar.
            ->renderHook(PanelsRenderHook::TOPBAR_LOGO_AFTER, fn () => view('filament.panel-badge', ['panel' => 'admin']))
            // A rail across the very top, the way a staging environment is
            // marked. Decorative, so aria-hidden — the badge carries the same
            // fact as text for anyone not reading colour.
            ->renderHook(PanelsRenderHook::BODY_START, fn () => new HtmlString('<div class="fi-admin-rail" aria-hidden="true"></div>'))
            ->multiFactorAuthentication(
                AppAuthentication::make()
                    ->brandName((string) config('app.name'))
                    ->recoverable(),
                isRequired: (bool) config('advault.require_admin_2fa'),
            )
            // Same brand as the publisher panel and the site — design.md §10.
            ->colors([
                'primary' => Color::hex('#F5A524'),
                'gray' => Color::hex('#6E7784'),
            ])
            ->viteTheme('resources/css/filament/theme.css')
            ->darkMode(isForced: true)
            ->font('Archivo', provider: LocalFontProvider::class)
            ->monoFont('Geist Mono', provider: LocalFontProvider::class)
            ->renderHook(PanelsRenderHook::SIMPLE_LAYOUT_START, fn () => view('filament.auth-brand'))
            ->navigationGroups([
                NavigationGroup::make('Overview'),
                NavigationGroup::make('Supply'),
                NavigationGroup::make('Publishers'),
                NavigationGroup::make('Money'),
                NavigationGroup::make('Risk'),
                NavigationGroup::make('Logs'),
                NavigationGroup::make('System'),
            ])
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\Filament\Admin\Resources')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\Filament\Admin\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\Filament\Admin\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
