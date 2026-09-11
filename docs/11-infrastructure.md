# 11 — Infrastructure

Single VPS. nginx + PHP-FPM + MySQL + Redis + Horizon. Everything static is served by the same nginx.

---

## 1. Server

**Baseline spec:** 4 vCPU · 8 GB RAM · 160 GB NVMe · Ubuntu 26.04 LTS.

Comfortable to roughly 5M clicks and 100k conversions per month. The first thing to hurt is MySQL on `offer_clicks`; §8 covers what to do then.

**This table is canonical.** Every other document defers to it.

| Component | Version | Role |
|---|---|---|
| nginx | 1.30+ (stable branch) | Web server, static assets, TLS |
| PHP-FPM | 8.5 | Laravel. Packaged as `php8.5-fpm` in 26.04, no third-party PPA needed. |
| MySQL | 9.7 LTS | Primary datastore |
| Redis | 8 | Cache, queues, rate limits, sessions, response cache |
| Node | 24 LTS | **Build only.** `npm run build` emits hashed assets. Nothing from Node runs in production. |
| Supervisor | — | Keeps Horizon alive |
| Certbot | — | TLS |

### Application versions

| Package | Version | Note |
|---|---|---|
| `laravel/framework` | ^13.0 | Requires PHP ^8.3; we run 8.5 |
| `filament/filament` | ^5.0 | Requires `illuminate/contracts` ^13.0 and `livewire/livewire` ^4.1 |
| `livewire/livewire` | ^4.0 | Pulled in by Filament. Still bundles Alpine 3.16 + plugins. |
| `laravel/horizon` | ^5.48 | `illuminate/*` ^13.0 |
| `pestphp/pest` | ^5.0 | Requires PHP **^8.4** — this, not Laravel, is what sets the PHP floor. `pest-plugin-laravel` ^5.0 requires `laravel/framework` ^13.23. |
| `tailwindcss` | ^4.3 | CSS-first config |
| `vite` | ^8.0 | |
| `laravel-vite-plugin` | ^3.0 | `vite` peer range is `^8.0.0` |
| `alpinejs` | ^3.16 | |
| `gsap` | ^3.15 | Marketing pages only |

MySQL 8.4 is also still an LTS and is the conservative fallback if the host's packaging lags 9.x. Nothing in the schema uses a 9.x-only feature — the choice is support lifetime, not capability.

**PHP extensions:** `bcmath` (money comparison), `redis`, `pdo_mysql`, `mbstring`, `intl`, `gd`, `zip`, `opcache`, `curl`.

`bcmath` is not optional — the ledger reconciliation compares money with `bccomp`, and float comparison there produces false drift alerts.

---

## 2. Domains

| Domain | Serves |
|---|---|
| `playndgain.com` | Static marketing site + `/docs` + Laravel panels |
| `wall.playndgain.com` | Offerwall only |
| `api.playndgain.com` | Postback endpoints only |

Three server blocks, one machine, one certificate covering all three.

**The wall and the API are split off deliberately.** They carry the traffic, they need different rate limits and logging, and when the wall eventually needs its own box the DNS change is the whole migration.

### Main site — everything through Laravel

```nginx
server {
    server_name playndgain.com;
    root /var/www/playndgain/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }

    # Vite build output — hashed filenames, safe to cache forever
    location ^~ /build/ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    location ~* \.(woff2|avif|webp|png|svg|ico)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }
}
```

Every route — landing, docs, panels — is served by PHP. **nginx never proxies to a JavaScript runtime.** There is no Node process to proxy to.

### Response cache

`spatie/laravel-responsecache`, Redis-backed, 1 hour, **on the landing route only**. Keyed on path + auth state.

Blade rendering is cheap enough that the docs and legal pages do not need it — their Markdown is already cached as parsed HTML. The landing page is cached because it runs three aggregate count queries, and those are the only expensive thing on it.

Cleared on every deploy, because cached HTML references hashed asset filenames that change on rebuild.

### Offerwall

```nginx
server {
    server_name wall.playndgain.com;
    root /var/www/playndgain/public;

    limit_req_zone $binary_remote_addr zone=wall:20m rate=60r/m;
    limit_req zone=wall burst=20 nodelay;

    location / { try_files $uri /index.php?$query_string; }

    add_header X-Frame-Options "" always;              # MUST be embeddable
    add_header Content-Security-Policy "frame-ancestors *;" always;
}
```

The wall **must** be iframe-embeddable from arbitrary publisher domains. A global `X-Frame-Options: DENY` applied across the server is the single most likely way to break every integration at once — the header is cleared here explicitly so a later hardening pass does not silently kill the product.

The panels get the opposite treatment: `X-Frame-Options: SAMEORIGIN`, strict CSP, HSTS.

---

## 3. Queues

Redis + Horizon.

```php
// config/horizon.php
'production' => [
    'postbacks' => [
        'connection' => 'redis', 'queue' => ['postbacks'],
        'balance' => 'auto', 'minProcesses' => 2, 'maxProcesses' => 8,
        'tries' => 6, 'timeout' => 30,
    ],
    'default' => [
        'connection' => 'redis', 'queue' => ['default'],
        'minProcesses' => 1, 'maxProcesses' => 4, 'tries' => 3,
    ],
    'sync' => [
        'connection' => 'redis', 'queue' => ['sync'],
        'minProcesses' => 1, 'maxProcesses' => 2,
        'tries' => 2, 'timeout' => 600,
    ],
],
```

Separate supervisors, not one pool. A ten-minute catalogue sync must never sit in front of a conversion postback — that is the entire reason for the split.

```ini
; /etc/supervisor/conf.d/playndgain-horizon.conf
[program:playndgain-horizon]
command=php /var/www/playndgain/artisan horizon
autostart=true
autorestart=true
stopwaitsecs=3600
user=www-data
```

`stopwaitsecs=3600` lets in-flight postbacks finish on deploy instead of being killed mid-request.

---

## 4. Scheduler

```
* * * * * cd /var/www/playndgain && php artisan schedule:run >> /dev/null 2>&1
```

| Task | Frequency |
|---|---|
| `networks:sync` | Every 15 min |
| `stats:aggregate --today` | Hourly |
| `stats:aggregate --yesterday` | Daily 00:30 |
| `ledger:reconcile` | Daily 03:00 |
| `alerts:check` | Every 15 min |
| `geoip:update` | Weekly |
| `logs:prune` | Daily 04:00 |
| `horizon:snapshot` | Every 5 min |
| `backup:run` | Daily 02:00 |
| `withdrawals:request-monthly` | 1st of the month 00:10 |
| `payouts:reconcile-statements` | Daily 03:05 |

`withdrawals:request-monthly` writes last month's payout statements and opens the withdrawals in one run — [06-ledger-payouts.md](06-ledger-payouts.md) §9. After deploying the statements migration, run `php artisan payouts:backfill-statements --dry-run`, then without the flag, once: it writes a statement for every historical month with ledger activity and links the withdrawals that already paid them. Rerunning is a no-op; `--publisher=10042` limits either command to one account. Withdrawals requested *inside* the month they were paying for (from before payouts were monthly) cannot reach that month by the normal rule; `--link-through=2026-08` lets them settle statements up to that month on the real run — the last such request in a month settles it, earlier ones appear as the carried line. One-time use. Follow it with `php artisan payouts:reconcile-statements` — a failure names the publisher and the row; inspect the ledger and the withdrawal, then re-run the backfill for that publisher.

Before the migration, check how many duplicate destinations the dedupe will remove: `SELECT publisher_id, type, COUNT(*) FROM payment_methods GROUP BY 1,2 HAVING COUNT(*) > 1;` — the Active row (else the newest) is kept, the rest are deleted with an `audit_logs` row each (`payment_method.deduplicated`).

`postbacks:replay-chargebacks` is not in this table either. It is a repair for chargebacks refused by the old not-active gate, it moves money and fires publisher callbacks, and there is nothing recurring for it to do — run it by hand, `--dry-run` first.

`conversions:clear` was in this table and is deliberately not scheduled. Clearing is a comparison, not a state change: `ledger_entries.available_at` carries the hold deadline and `Publisher::clearedBalance()` reads it against `now()`. An hourly job flipping a status column would be a second source of truth for whether money is spendable, and the two would eventually disagree.

`alerts:check` runs on the same fifteen minutes the postback failure rate is measured over, so each run looks at roughly the period since the last one — §7.

---

## 5. MySQL

```ini
innodb_buffer_pool_size      = 4G      # ~50% of RAM
innodb_flush_log_at_trx_commit = 2      # money is in InnoDB txns; 2 is the right trade here
innodb_file_per_table        = 1
max_connections              = 200
slow_query_log               = 1
long_query_time              = 1
```

### Table growth and retention

| Table | Growth | Retention |
|---|---|---|
| `offer_clicks` | Highest | 6 months, then aggregated into `daily_stats` and pruned |
| `incoming_postbacks` | High | 90 days |
| `outgoing_postbacks` | Medium | 180 days |
| `fraud_events` | Medium | 180 days |
| `identity_sessions` | Medium | 180 days |
| `network_syncs` | Low | 90 days |
| `conversions` | Low | **Forever** |
| `ledger_entries` | Low | **Forever** |
| `daily_stats` | Trivial | **Forever** |

Money rows are never pruned. Log rows are pruned in batches of 10 000 with a sleep between, so the prune job does not lock the table during traffic.

---

## 6. Backups

| What | How | Where | Retention |
|---|---|---|---|
| MySQL | `mysqldump`, gzipped, nightly | Off-site object storage (S3/B2) | 30 days |
| MySQL binlogs | Streamed | Off-site | 7 days |
| `.env` + storage | Weekly | Off-site, encrypted | 90 days |

`spatie/laravel-backup`. **Restore is tested monthly** into a scratch database and the test is a checklist item, not an intention. An untested backup is a hypothesis.

A restore drill that has never been run is how you discover the dump excluded a table.

---

## 7. Monitoring & Alerts

| Signal | Threshold | Channel |
|---|---|---|
| Site down | 2 failed checks | Page |
| Postback failure rate (global) | > 5% / 15 min | Page |
| Queue depth `postbacks` | > 1000 | Page |
| Zero conversions | 2 h in normal hours | Page |
| **Ledger drift** | Any | **Page** |
| Network sync failing | 3 consecutive | Email |
| Catalogue collapse guard fired | Any | Email |
| Two publisher accounts share IP / device / session | New pair | Email |
| Disk usage | > 80% | Email |
| MySQL slow queries | > 100/h | Email |
| Failed jobs | > 50/h | Email |
| Cert expiry | < 14 days | Email |

**Tools:** Laravel Pulse (in-app), Sentry (exceptions), UptimeRobot or Better Stack (external HTTP checks). External checks matter — an in-app monitor cannot report that the app is down.

Ledger drift pages rather than emails because it means money code wrote a balance without its entry, and every hour it stays unfixed adds rows built on a wrong number.

---

## 8. Scaling Path

In order, as each becomes the bottleneck. Nothing here is built in v1.

1. **Read replica** for panel reporting queries. Writes stay on the primary.
2. **Move the wall to its own box.** DNS already points at `wall.` — this is a deploy, not a refactor.
3. **Partition `offer_clicks` by month.** Prunes become `DROP PARTITION`.
4. **Redis to its own instance** when memory pressure shows up.
5. **ClickHouse for analytics** if reporting outgrows `daily_stats`.
6. **Managed MySQL** when backup and failover matter more than the bill.

---

## 9. Deploy

Zero-downtime via Deployer, or a plain script. Order matters.

```bash
php artisan down --render=errors::503 --retry=60

git pull --ff-only
composer install --no-dev --optimize-autoloader

npm ci
npm run build                      # vite build → public/build
php artisan docs:index             # → public/docs-index.json

php artisan migrate --force
php artisan config:cache route:cache view:cache
php artisan filament:optimize
php artisan responsecache:clear    # stale HTML would reference dead asset hashes
php artisan horizon:terminate      # graceful; supervisor restarts it

php artisan up
```

`horizon:terminate` (not `kill`) lets in-flight jobs finish. Migrations run before caches are rebuilt so a cached config never points at a schema that does not exist yet.

**`responsecache:clear` must run after `npm run build`.** Cached HTML references hashed asset filenames; serving it after a rebuild points browsers at files that no longer exist.

Node is needed on this machine only to run the build. Move it to CI and ship `public/build/` as an artifact if you prefer a server without Node on it at all — nothing at runtime depends on it.

**Rollback:** previous release symlink + `migrate:rollback` only when the migration is reversible. Destructive migrations are split into two deploys — add the new column, ship code that writes both, drop the old column a deploy later. Never in one step on a table holding money.

---

## 10. Security Checklist

- [ ] TLS on all three domains, HSTS on the panels (**not** on the wall)
- [ ] `X-Frame-Options` cleared on `wall.` **only**; `SAMEORIGIN` everywhere else
- [ ] `APP_DEBUG=false`, `APP_ENV=production`
- [ ] Root SSH login disabled, key-only auth
- [ ] UFW: 22, 80, 443 only
- [ ] fail2ban on SSH and nginx auth
- [ ] MySQL and Redis bound to `127.0.0.1`
- [ ] Redis password set
- [ ] `.env` mode `600`, owned by the deploy user
- [ ] 2FA enforced on every admin account
- [ ] Rate limits live on wall, click, postback, and login
- [ ] Encrypted casts verified on `apps.secret_key`, `networks.credentials`, `payment_methods.details`, `withdrawals.payment_details`
- [ ] Sentry scrubs secrets, signatures, and payment details from breadcrumbs
- [ ] Backup restore drill passed this month
- [ ] `storage/` and `bootstrap/cache/` not web-reachable
