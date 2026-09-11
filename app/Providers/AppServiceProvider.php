<?php

namespace App\Providers;

use App\Fraud\Contracts\IpIntelligence;
use App\Fraud\LocalIpIntelligence;
use App\Fraud\NullIpIntelligence;
use App\Fraud\RuleRegistry;
use App\Mail\Transport\HostingerTransport;
use App\Models\AccountMatch;
use App\Models\App as PublisherApp;
use App\Models\AuditLog;
use App\Models\BannedEndUser;
use App\Models\Blacklist;
use App\Models\Consent;
use App\Models\Conversion;
use App\Models\DailyStat;
use App\Models\FraudEvent;
use App\Models\FraudRule;
use App\Models\IdentitySession;
use App\Models\IncomingPostback;
use App\Models\LedgerEntry;
use App\Models\Network;
use App\Models\NetworkSync;
use App\Models\Offer;
use App\Models\OfferClick;
use App\Models\OutgoingPostback;
use App\Models\PaymentMethod;
use App\Models\PayoutStatement;
use App\Models\Publisher;
use App\Models\Setting;
use App\Models\User;
use App\Models\Withdrawal;
use App\Networks\NetworkDriverManager;
use App\Policies\AdminOnlyPolicy;
use App\Policies\LedgerEntryPolicy;
use App\Policies\OutgoingPostbackPolicy;
use App\Policies\PublisherOwnedPolicy;
use App\Services\Wall\ThrottleReporter;
use BezhanSalleh\PanelSwitch\PanelSwitch;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpClient\HttpClient;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Publisher-owned models, all governed by the same ownership rule.
     *
     * LedgerEntry is deliberately absent — it gets a stricter policy below.
     *
     * @var list<class-string<Model>>
     */
    private const PUBLISHER_OWNED = [
        PublisherApp::class,
        BannedEndUser::class,
        Blacklist::class,
        Conversion::class,
        DailyStat::class,
        OfferClick::class,
        PaymentMethod::class,
        PayoutStatement::class,
        Withdrawal::class,
    ];

    /**
     * Platform-owned models. Filament resolves `canViewAny` through the policy,
     * so a model with none would authorize every panel visitor — these are
     * registered so the admin panel fails closed.
     *
     * @var list<class-string<Model>>
     */
    private const ADMIN_OWNED = [
        AccountMatch::class,
        AuditLog::class,
        Consent::class,
        FraudEvent::class,
        IdentitySession::class,
        FraudRule::class,
        IncomingPostback::class,
        Network::class,
        NetworkSync::class,
        Offer::class,
        Publisher::class,
        Setting::class,
        User::class,
    ];

    public function register(): void
    {
        // One manager for the whole app, so a driver registered here is the
        // driver every caller resolves. Concrete drivers register themselves
        // into it from NetworkServiceProvider.
        $this->app->singleton(NetworkDriverManager::class);

        $this->app->singleton(RuleRegistry::class);

        // Swapped for the MaxMind-backed reader once GeoLite2 and IP2Proxy are
        // on disk. Until then it places nobody — see NullIpIntelligence for
        // what an unknown country means to the eligibility filter.
        $this->app->bind(IpIntelligence::class, NullIpIntelligence::class);
    }

    /**
     * Wall limits are per docs/03 §6. Exceeding one is a fraud signal as well
     * as a load signal, so the offerwall records a fraud event alongside the
     * 429 rather than only shedding the request.
     *
     * The numbers come from config rather than living here, for one reason
     * that only shows up once: docs/12 Phase 7 requires a sustained load test
     * at 1000 wall renders a minute, and a single load generator is a single
     * IP. With the per-IP limit welded shut at 60 the test measures the rate
     * limiter and nothing else. Staging raises them; production leaves them at
     * the spec's defaults, which is what the `env()` fallbacks are.
     */
    /**
     * The topbar control that moves an admin between the two panels. It only
     * renders when the signed-in user can access two or more panels, which
     * User::canAccessPanel() already limits to admins — a publisher has one
     * panel and never sees it.
     */
    private function panelSwitch(): void
    {
        PanelSwitch::configureUsing(function (PanelSwitch $panelSwitch): void {
            // No explicit ->panels() list: the vendor indexes that list by the
            // panels the user can reach and throws for a publisher, who cannot
            // reach admin. Registration order is admin, publisher already.
            $panelSwitch
                ->simple()
                ->labels(['admin' => 'Admin panel', 'publisher' => 'Publisher panel'])
                ->icons(['admin' => 'heroicon-o-shield-check', 'publisher' => 'heroicon-o-squares-2x2']);
        });
    }

    private function rateLimiters(): void
    {
        // The `response` hook is what makes a rate limit a fraud signal rather
        // than only a load one — without it a shed request returns Laravel's
        // default 429 and writes nothing anyone can review.
        $report = fn (Request $request) => app(ThrottleReporter::class)->report($request);

        // Config is read inside each closure — at request time, not captured
        // at boot — so a test can pin a limit with `config()->set()` instead
        // of depending on whatever the local .env raised it to.
        RateLimiter::for('wall', fn (Request $request) => [
            Limit::perMinute((int) config('playndgain.rate_limits.wall_per_ip'))->by($request->ip())->response($report),
            Limit::perMinute((int) config('playndgain.rate_limits.wall_per_app'))->by('app:'.$request->query('appid'))->response($report),
        ]);

        RateLimiter::for('click', fn (Request $request) => Limit::perMinute((int) config('playndgain.rate_limits.click_per_user'))
            ->by($request->ip().'|'.$request->query('userid'))
            ->response($report));

        RateLimiter::for('privacy', fn (Request $request) => Limit::perMinute((int) config('playndgain.rate_limits.privacy_per_ip'))
            ->by($request->ip())
            ->response($report));

        // docs/11 §10 lists a rate limit on the postback endpoints and there
        // was none. That endpoint writes an `incoming_postbacks` row *before*
        // it authenticates anything — deliberately, so a network's "we sent
        // you 400 conversions" is answerable — which means an unthrottled
        // flood fills the table at link speed with rows nobody asked for.
        //
        // Keyed per source IP and generous by design: a real network sending a
        // burst of retries after an outage must not be shed. No fraud event
        // here, because a network at its limit is an infrastructure fact, not
        // an end user doing something.
        RateLimiter::for('postback', fn (Request $request) => Limit::perMinute((int) config('playndgain.rate_limits.postback_per_ip'))
            ->by((string) $request->ip()));
    }

    public function boot(): void
    {
        // Generated URLs follow the scheme APP_URL declares. Keyed on the URL
        // rather than the environment on purpose: the Lighthouse job serves a
        // production build over plain HTTP on 127.0.0.1, and forcing https
        // there hands the browser stylesheets it cannot fetch.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        $this->rateLimiters();
        $this->localGeo();
        $this->filamentTables();
        $this->panelSwitch();
        $this->hostingerMailer();

        // The announcements resource checks this gate on top of the panel.
        // A string, not a closure, so config:cache can serialise the config.
        Gate::define('manage-announcements', fn (User $user): bool => $user->isAdmin());
        // opcodesio/log-viewer. The page uses Filament Authenticate first;
        // this gate keeps a signed-in publisher out of the UI.
        Gate::define('viewLogViewer', fn (?User $user): bool => (bool) $user?->isAdmin());

        foreach (self::PUBLISHER_OWNED as $model) {
            Gate::policy($model, PublisherOwnedPolicy::class);
        }

        foreach (self::ADMIN_OWNED as $model) {
            Gate::policy($model, AdminOnlyPolicy::class);
        }

        Gate::policy(LedgerEntry::class, LedgerEntryPolicy::class);

        // Read by both panels, owned through the app rather than directly.
        Gate::policy(OutgoingPostback::class, OutgoingPostbackPolicy::class);
    }

    /**
     * List pages and relation managers attach the first view/edit URL to the
     * whole row unless a custom recordUrl is already set. The action buttons
     * already go there; a clickable row fights text selection and the copy
     * control on identity cells. Tables that want a row click set recordUrl
     * or recordAction themselves — audit inspect is one.
     */
    private function filamentTables(): void
    {
        Table::configureUsing(function (Table $table): void {
            $table
                ->recordUrl(null)
                // A visible bar, not a Filter button whose badge reads "0".
                // The custom pages already put their filters on the page;
                // ledger tables were hiding the same controls behind a click.
                ->filtersLayout(FiltersLayout::AboveContent)
                ->deferFilters(false)
                // Sidebar links are clean URLs. Without the session the bar
                // resets the moment you open another page and come back.
                ->persistFiltersInSession();
        });
    }

    /**
     * The wall on a developer's machine, which is otherwise permanently empty:
     * every request arrives from `127.0.0.1`, no database places it, and the
     * eligibility filter fails closed on an unplaced address (docs/03 §2).
     *
     * Two conditions, and both are load-bearing. `local` keeps it off every
     * deployed environment whatever an .env holds — a fabricated country in
     * production is the chargeback the fail-closed rule exists to prevent — and
     * the unset config keeps it off a local install that never asked for it.
     *
     * In `boot()` rather than `register()` because it decorates: FraudServiceProvider
     * binds the real reader in its own `register()`, and `extend` has to run
     * after that to wrap it instead of being overwritten by it.
     */
    private function localGeo(): void
    {
        $country = config('playndgain.geoip_dev_country');

        if (! $this->app->environment('local') || ! is_string($country) || $country === '') {
            return;
        }

        $this->app->extend(
            IpIntelligence::class,
            fn (IpIntelligence $reader): IpIntelligence => new LocalIpIntelligence($reader, $country),
        );
    }

    /**
     * Hostinger Mail API transport so MAIL_MAILER=hostinger works with Mail:: /
     * notifications without a third-party Laravel package.
     */
    private function hostingerMailer(): void
    {
        Mail::extend('hostinger', function (array $config = []): HostingerTransport {
            $token = (string) ($config['token'] ?? config('services.hostinger.token') ?? '');
            $mailboxId = (string) ($config['mailbox_id'] ?? config('services.hostinger.mailbox_id') ?? '');
            $baseUrl = (string) ($config['base_url'] ?? config('services.hostinger.base_url') ?? 'https://api.mail.hostinger.com');

            if ($token === '' || $mailboxId === '') {
                throw new \InvalidArgumentException(
                    'Hostinger mailer requires HOSTINGER_MAIL_API_TOKEN and HOSTINGER_MAIL_MAILBOX_ID.',
                );
            }

            $client = isset($config['client']) && is_array($config['client'])
                ? HttpClient::create($config['client'])
                : null;

            return new HostingerTransport($token, $mailboxId, $baseUrl, $client);
        });
    }
}
