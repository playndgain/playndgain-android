<?php

namespace App\Providers\Filament;

use App\Filament\Publisher\Auth\EditProfile;
use App\Filament\Publisher\Auth\Login;
use App\Filament\Publisher\Auth\Register;
use App\Filament\Publisher\Pages\Dashboard;
use App\Filament\Publisher\Support\CurrentPublisher;
use App\Filament\Support\PanelSwitcher;
use App\Support\Identity;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Publisher panel. Scoped to the authenticated publisher, which is enforced by
 * PublisherScope on the models rather than by anything in this file.
 *
 * 2FA is available here but not required — requiring it on the panel our
 * customers use daily is a support burden the admin panel can carry and this
 * one cannot.
 *
 * Registration points at our own page rather than Filament's: the default one
 * creates a user with no role and no publisher row, which canAccessPanel would
 * then reject. Email verification, by contrast, is entirely stock — the only
 * thing this panel adds to it is one exempted page, and that exemption lives on
 * the page.
 */
class PublisherPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('publisher')
            ->path('dashboard')
            // Kept for the browser tab: Filament renders brandLogo instead of
            // brandName — see AdminPanelProvider.
            ->brandName(fn () => Identity::name().' Publisher')
            ->brandLogo(fn () => view('components.brand-mark', ['size' => 26]))
            ->brandLogoHeight('1.75rem')
            ->favicon(asset('favicon.ico'))
            ->login(Login::class)
            ->userMenuItems(PanelSwitcher::items('publisher'))
            // Directly after the logo, reading as part of the lockup: the
            // panel name belongs to the brand, not to the account controls
            // at the far end of the bar.
            ->renderHook(PanelsRenderHook::TOPBAR_LOGO_AFTER, fn () => view('filament.panel-badge', ['panel' => 'publisher']))
            // Sits above the page, not inside it, so it shows on every route an
            // admin lands on rather than only the dashboard.
            ->renderHook(PanelsRenderHook::PAGE_START, fn () => view('filament.admin-context-banner'))
            ->registration(Register::class)
            ->passwordReset()
            // docs/08 §1. Required, which is Filament's default: an unverified
            // address is one we cannot reach with a withdrawal alert or an
            // account-status notice, and signup is open, so the address is the
            // only thing tying an application to a person before an admin
            // reads it. Everything a fresh registrant needs — the credential
            // handover — is exempted on the page itself, so there is no step
            // where the panel asks for something it has not yet let them see.
            ->emailVerification()
            ->profile(EditProfile::class)
            ->multiFactorAuthentication(
                AppAuthentication::make()
                    ->brandName((string) config('app.name'))
                    ->recoverable(),
            )
            // Brand, not Filament defaults — design.md §10. Tokens live once in
            // resources/css/tokens.css and reach the panel through the theme.
            // Dark is forced: there is no light theme to keep in sync.
            ->colors([
                'primary' => Color::hex('#F5A524'),
                'gray' => Color::hex('#6E7784'),
            ])
            ->viteTheme('resources/css/filament/theme.css')
            ->darkMode(isForced: true)
            // Local provider with no URL emits nothing: the faces are already in
            // the theme bundle, and the default provider would add a Google
            // Fonts request to a product that sells auditability.
            ->font('Archivo', provider: LocalFontProvider::class)
            ->monoFont('Geist Mono', provider: LocalFontProvider::class)
            ->renderHook(PanelsRenderHook::SIMPLE_LAYOUT_START, fn () => view('filament.auth-brand'))
            // An admin looking at a publisher's own dashboard must never be able
            // to forget they are doing it — docs/07 §5.
            ->renderHook(PanelsRenderHook::BODY_START, fn () => view('filament.impersonation-banner'))
            // Pending and suspended accounts say so on every page, not just on
            // the one that rejected them — docs/08 §1.
            ->renderHook(PanelsRenderHook::PAGE_START, fn () => view('filament.publisher.account-banner'))
            ->navigationItems([
                NavigationItem::make('Documentation')
                    ->url('/docs', shouldOpenInNewTab: true)
                    ->icon(Heroicon::OutlinedBookOpen)
                    ->sort(90),
            ])
            // Every date and time the panel renders goes through Filament's
            // timezone manager, so pointing it at the publisher's own column is
            // the whole of docs/08 §14's "drives every date shown in the
            // panel". A closure, not a value: it is resolved per render, and at
            // provider-boot time there is no authenticated user to read.
            ->bootUsing(fn () => FilamentTimezone::set(
                fn (): string => CurrentPublisher::get()?->resolvedTimezone() ?? (string) config('app.timezone'),
            ))
            ->discoverResources(in: app_path('Filament/Publisher/Resources'), for: 'App\Filament\Publisher\Resources')
            // Dashboard is discovered from this directory, so it is not listed
            // in ->pages() as well — that would register two of it.
            ->discoverPages(in: app_path('Filament/Publisher/Pages'), for: 'App\Filament\Publisher\Pages')
            ->discoverWidgets(in: app_path('Filament/Publisher/Widgets'), for: 'App\Filament\Publisher\Widgets')
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
