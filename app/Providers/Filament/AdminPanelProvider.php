<?php

namespace App\Providers\Filament;

use App\Filament\Admin\Auth\EditProfile;
use App\Filament\Support\InitialsAvatarProvider;
use App\Support\Identity;
use EslamRedaDiv\TimezoneDetector\Facades\TimezoneDetector;
use EslamRedaDiv\TimezoneDetector\TimezoneDetectorPlugin;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentTimezone;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use LaBoiteACode\FilamentDashboardWidgets\FilamentDashboardWidgetsPlugin;
use Leek\FilamentRightClick\FilamentRightClickPlugin;
use Marcelodelgado\Announcements\Filament\Widgets\AnnouncementsWidget;
use RickDBCN\FilamentEmail\FilamentEmail;

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
 * authenticator app. It defaults to ON — see config/playndgain.php.
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
            ->brandLogo(fn () => view('components.brand-mark', ['size' => 34, 'label' => 'Admin', 'labelTone' => 'accent']))
            ->brandLogoHeight('2.125rem')
            ->sidebarWidth('18.5rem')
            ->renderHook(PanelsRenderHook::HEAD_START, fn () => view('partials.favicons'))
            ->login()
            // Inside the panel sheet, not the auth card. Email is locked here
            // the same way as the publisher profile — another admin changes
            // it under Admin users.
            ->profile(EditProfile::class, isSimple: false)
            // Directly after the logo, reading as part of the lockup: the
            // panel name belongs to the brand, not to the account controls
            // at the far end of the bar.
            // A rail across the very top, the way a staging environment is
            // marked. Decorative, so aria-hidden — the badge carries the same
            // fact as text for anyone not reading colour.
            ->renderHook(PanelsRenderHook::BODY_START, fn () => new HtmlString('<div class="fi-admin-rail" aria-hidden="true"></div>'))
            ->multiFactorAuthentication(
                AppAuthentication::make()
                    ->brandName((string) config('app.name'))
                    ->recoverable(),
                isRequired: (bool) config('playndgain.require_admin_2fa'),
            )
            // Same brand as the publisher panel and the site — design.md §10.
            ->colors([
                'primary' => Color::hex('#FFB020'),
                'gray' => Color::hex('#8B90A8'),
                // Money direction and nothing else. Columns and entries say
                // `gain`/`loss`: a gain is the site's gold, a loss its rose —
                // "the only rose is a negative amount" (theme.css). Filament's
                // own `success` and `danger` stay for its chrome.
                'gain' => Color::hex('#FFB020'),
                'loss' => Color::hex('#E5484D'),
            ])
            ->viteTheme('resources/css/filament/theme.css')
            ->darkMode(isForced: true)
            ->font('Sora Variable', provider: LocalFontProvider::class)
            // Drawn locally in amber; the stock provider calls ui-avatars.com
            // with the account holder's name in the URL.
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            // The page-open intro and the auth pages' motion script, ahead of the
            // brand panel so the block it flies onto is already in the page.
            ->renderHook(PanelsRenderHook::SIMPLE_LAYOUT_START, fn () => view('filament.auth-intro'))
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
            ->navigationItems([
                NavigationItem::make('Application logs')
                    ->url('/log-viewer', shouldOpenInNewTab: true)
                    ->icon('heroicon-o-document-text')
                    ->group('Logs')
                    ->sort(90),
                NavigationItem::make('Horizon')
                    ->url('/horizon', shouldOpenInNewTab: true)
                    ->icon('heroicon-o-queue-list')
                    ->group('System')
                    ->sort(4),
            ])
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\Filament\Admin\Resources')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\Filament\Admin\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\Filament\Admin\Widgets')
            // Announcements are written here and read on both dashboards. The
            // manage resource is our own subclass (Resources/Announcements) so
            // it can sit in the System group; the plugin class is not used
            // because it would register the vendor resource ungrouped.
            ->widgets([AnnouncementsWidget::class])
            // Dates are stored in UTC. The detector writes the browser's IANA
            // zone into the session; Filament's timezone manager then points
            // every dateTime / since column at it. First paint stays UTC until
            // the script has run once — that is the plugin's documented start.
            ->bootUsing(fn () => FilamentTimezone::set(
                fn (): string => TimezoneDetector::getUserTimezone(),
            ))
            ->plugins([
                // Every email the platform sends, kept for 90 days
                // (config/filament-email.php). Admin only: the log holds
                // publisher addresses and payout notices.
                FilamentEmail::make(),
                FilamentRightClickPlugin::make(),
                FilamentDashboardWidgetsPlugin::make(),
            ])
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
            ])
            // After StartSession: the detector writes the browser zone into
            // the session, and register() appends that middleware here.
            ->plugin(TimezoneDetectorPlugin::make());
    }
}
