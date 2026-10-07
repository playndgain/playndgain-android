<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Seed admin account
    |--------------------------------------------------------------------------
    |
    | Used by AdminUserSeeder. When no password is configured the seeder
    | generates one and prints it once — there is no default password.
    |
    */

    'seed_admin_email' => env('PLAYNDGAIN_SEED_ADMIN_EMAIL', 'ziadtallat33@gmail.com'),
    'seed_admin_password' => env('PLAYNDGAIN_SEED_ADMIN_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | Admin 2FA
    |--------------------------------------------------------------------------
    |
    | The admin panel can move money and ban accounts, so docs/00-overview.md §8
    | requires 2FA on it. This toggle exists so local development is not gated
    | behind an authenticator app — it defaults to ON and must stay ON in
    | production. Multi-factor auth remains *available* on both panels either
    | way; this only controls whether an admin is forced to set it up.
    |
    */

    'require_admin_2fa' => (bool) env('PLAYNDGAIN_REQUIRE_ADMIN_2FA', true),

    /*
    |--------------------------------------------------------------------------
    | Wall base URL
    |--------------------------------------------------------------------------
    |
    | The wall is framed on publisher domains, so every embed URL we hand out
    | must point at the wall host: the site host sends X-Frame-Options
    | SAMEORIGIN and renders as "refused to connect" inside an iframe. Blank
    | falls back to https://WALL_DOMAIN (set by the container entrypoint), then
    | to APP_URL for local hosts that serve everything on one name.
    |
    */

    'wall_url' => env('PLAYNDGAIN_WALL_URL') ?: (env('WALL_DOMAIN') ? 'https://'.env('WALL_DOMAIN') : null),

    /*
    |--------------------------------------------------------------------------
    | Money defaults
    |--------------------------------------------------------------------------
    |
    | Fallbacks for when the `settings` table has not been seeded yet. The
    | table is the runtime source of truth — see App\Models\Setting.
    |
    */

    'default_rev_share' => 70.00,
    'default_hold_days' => 30,
    'default_min_payout' => 20.00,

    /*
    |--------------------------------------------------------------------------
    | Public identity
    |--------------------------------------------------------------------------
    |
    | App name and URL come from APP_NAME / APP_URL. Support mail defaults to
    | MAIL_FROM_ADDRESS so changing the mailbox in .env updates invoices,
    | mail footers and the publisher banner without a second constant.
    |
    */

    'support_email' => env('PLAYNDGAIN_SUPPORT_EMAIL', env('MAIL_FROM_ADDRESS', 'support@playndgain.com')),

    /*
    |--------------------------------------------------------------------------
    | Credit notes
    |--------------------------------------------------------------------------
    |
    | Legal issuer printed on publisher payout PDFs. Empty address fields are
    | allowed — the note still renders, it just looks unfinished until they
    | are set. A settings-table override wins when the admin fills the form.
    |
    */

    'invoice' => [
        'company_name' => env('INVOICE_COMPANY_NAME'),
        'address' => env('INVOICE_ADDRESS', ''),
        'city' => env('INVOICE_CITY', ''),
        'country' => env('INVOICE_COUNTRY', ''),
        'vat' => env('INVOICE_VAT', ''),
        'website' => env('INVOICE_WEBSITE'),
        'email' => env('INVOICE_EMAIL'),
        'directors' => env('INVOICE_DIRECTORS', ''),
        'vat_rate' => env('INVOICE_VAT_RATE', '0'),
    ],

    /*
    |--------------------------------------------------------------------------
    | IP intelligence databases
    |--------------------------------------------------------------------------
    |
    | GeoLite2 and IP2Proxy LITE are licensed downloads, not vendored files —
    | docs/05-fraud-engine.md §4. `geoip:update` fetches them into `geoip_path`
    | weekly; without credentials it skips and every lookup stays unknown.
    |
    | Both credentials are free-tier and account-bound: a MaxMind licence key
    | from maxmind.com/en/accounts/current/license-key, an IP2Location download
    | token from lite.ip2location.com/file-download.
    |
    */

    'geoip_path' => env('PLAYNDGAIN_GEOIP_PATH'),
    'geoip_maxmind_license_key' => env('MAXMIND_LICENSE_KEY'),
    'geoip_ip2location_token' => env('IP2LOCATION_DOWNLOAD_TOKEN'),

    /*
    | Local development only. No database will ever place `127.0.0.1`, and the
    | wall fails closed on an address it cannot place — so a developer's wall is
    | empty until this names a country for private and reserved addresses.
    |
    | Read exclusively by AppServiceProvider, and only while the environment is
    | `local`. Unset is today's behaviour; a value in a production .env does
    | nothing, because the binding it feeds is never registered there.
    */
    'geoip_dev_country' => env('PLAYNDGAIN_DEV_COUNTRY'),

    /*
    |--------------------------------------------------------------------------
    | Cloudflare edge ranges
    |--------------------------------------------------------------------------
    |
    | `TrustCloudflare` only rewrites REMOTE_ADDR from CF-Connecting-IP when
    | the TCP peer sits in one of these CIDRs — otherwise a forged header on
    | a direct-to-origin hit would let anyone pick the IP the fraud engine
    | and rate limiters see. Published at https://www.cloudflare.com/ips/.
    |
    */

    'cloudflare_proxies' => [
        // IPv4 — https://www.cloudflare.com/ips-v4
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        // IPv6 — https://www.cloudflare.com/ips-v6
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ],

    /*
    |--------------------------------------------------------------------------
    | Log retention
    |--------------------------------------------------------------------------
    |
    | Days to keep each log table, from docs/11-infrastructure.md §5. `logs:prune`
    | reads this map and nothing else, so a table absent from it is never touched
    | — which is how `conversions`, `ledger_entries` and `daily_stats` stay
    | forever. Adding one of them here would delete money history; there is no
    | second guard, because the right guard is not writing the line.
    |
    | Deletes run in batches with a pause between so the wall can keep writing
    | clicks while the prune walks six months of them.
    |
    */

    'log_retention' => [
        'offer_clicks' => (int) env('PLAYNDGAIN_RETAIN_CLICKS_DAYS', 180),
        'incoming_postbacks' => (int) env('PLAYNDGAIN_RETAIN_INCOMING_DAYS', 90),
        'outgoing_postbacks' => (int) env('PLAYNDGAIN_RETAIN_OUTGOING_DAYS', 180),
        'fraud_events' => (int) env('PLAYNDGAIN_RETAIN_FRAUD_DAYS', 180),
        'identity_sessions' => (int) env('PLAYNDGAIN_RETAIN_IDENTITY_DAYS', 180),

        // A log, not a record: `networks` holds the current sync state and
        // nothing references a `network_syncs` row. Its whole job is answering
        // "when did the wall go empty", and that question is asked within days.
        'network_syncs' => (int) env('PLAYNDGAIN_RETAIN_SYNCS_DAYS', 90),
    ],

    'log_prune_batch' => (int) env('PLAYNDGAIN_PRUNE_BATCH', 10000),
    'log_prune_pause_ms' => (int) env('PLAYNDGAIN_PRUNE_PAUSE_MS', 200),

    /*
    |--------------------------------------------------------------------------
    | Rate limits
    |--------------------------------------------------------------------------
    |
    | The defaults are the table in docs/03-offerwall.md §6 and belong in
    | production untouched. They are env-readable for one reason: docs/12
    | Phase 7 requires a sustained load test at 1000 wall renders a minute, and
    | one load generator is one IP — against a welded-shut per-IP limit of 60
    | that test measures the limiter and nothing else.
    |
    | `postback_per_ip` is new. docs/11 §10 lists a rate limit on the postback
    | endpoints and there was none; it is generous because a network catching
    | up after an outage sends a legitimate burst, and shedding that is how a
    | publisher stops getting paid for conversions we were told about.
    |
    */

    'rate_limits' => [
        'wall_per_ip' => (int) env('PLAYNDGAIN_LIMIT_WALL_IP', 60),
        'wall_per_app' => (int) env('PLAYNDGAIN_LIMIT_WALL_APP', 600),
        'click_per_user' => (int) env('PLAYNDGAIN_LIMIT_CLICK', 20),
        'privacy_per_ip' => (int) env('PLAYNDGAIN_LIMIT_PRIVACY', 10),
        'postback_per_ip' => (int) env('PLAYNDGAIN_LIMIT_POSTBACK', 600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Viewer API
    |--------------------------------------------------------------------------
    |
    | Shared secret for GET /log-viewer/api/*. Coding assistants send it as
    | `Authorization: Bearer …`. An empty value leaves the API session-only.
    | LOG_MCP_TOKEN is read as a fallback so an existing .env still works.
    |
    */

    'log_viewer_api_token' => (string) env('LOG_VIEWER_API_TOKEN', env('LOG_MCP_TOKEN', '')),

    /*
    |--------------------------------------------------------------------------
    | Alerts
    |--------------------------------------------------------------------------
    |
    | Fallbacks for the alert settings — the `settings` table is the runtime
    | source of truth, exactly as with the money defaults above. `recipients`
    | is the one that is only ever config: it is the address list a machine
    | with an empty database still knows to shout at.
    |
    | The `page` list names the signals docs/11 §7 says must page. PLAYNDGAIN has
    | no paging channel — it has a mailer — so a paging signal is delivered by
    | email with its severity stated in the subject rather than pretended away.
    | When a real channel lands, AdminAlertNotification::via() grows a second
    | entry for this list and no call site changes.
    |
    */

    'alerts' => [
        'recipients' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('PLAYNDGAIN_ALERT_EMAILS', ''))
        ))),

        // One email per key per window. Drift over 400 publishers is one bug,
        // not 400 emails, and an inbox that is full of an alert is an inbox
        // that stops being read.
        'cooldown_minutes' => (int) env('PLAYNDGAIN_ALERT_COOLDOWN_MINUTES', 60),

        'page' => [
            'ledger.drift',
            'postback.failure_rate',
            'queue.depth',
            'conversions.silent',
        ],

        // Thresholds from docs/04 §6 and docs/11 §7. The window is fifteen
        // minutes because that is the interval the check itself runs on.
        'postback_failure_rate' => 5.0,
        'postback_window_minutes' => 15,
        'queue_depth' => 1000,
        'queue_depth_queue' => 'postbacks',
        'conversion_silence_hours' => 2,

        // "Normal hours", local to config('app.timezone'). Nobody wants a page
        // at 04:00 because European traffic went quiet for two hours; a real
        // outage is still there at 08:00.
        'active_hours' => [8, 23],
    ],

    /*
    |--------------------------------------------------------------------------
    | Social links
    |--------------------------------------------------------------------------
    |
    | Shown in the site footer. Each link renders only when its URL is set —
    | an empty value hides the link rather than shipping a dead one.
    |
    */

    'social' => [
        'x' => env('PLAYNDGAIN_SOCIAL_X'),
        'linkedin' => env('PLAYNDGAIN_SOCIAL_LINKEDIN'),
        'discord' => env('PLAYNDGAIN_SOCIAL_DISCORD'),
        'instagram' => env('PLAYNDGAIN_SOCIAL_INSTAGRAM'),
    ],

];
