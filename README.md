# AdVault

Offerwall aggregator. Pulls offer catalogues from partner ad networks, serves them
on a hosted offerwall that publishers embed, receives conversion postbacks, pays a
revenue share, and fires a signed S2S postback back to the publisher.

**The specification is the source of truth — start at [docs/00-overview.md](docs/00-overview.md).**

---

## Stack

Laravel 13 · Filament v5 · Livewire 4 · PHP 8.5 · MySQL 9.7 LTS · Redis 8 · Horizon 5 · Pest 5.

The canonical version table is [docs/11-infrastructure.md](docs/11-infrastructure.md) §1.

## Surfaces

| Surface | Path | Stack |
|---|---|---|
| Admin panel | `/admin` | Filament v5 panel |
| Publisher panel | `/dashboard` | Filament v5 panel |
| Offerwall | `/wall`, `/click/{id}`, `/postback/{driver}` | Blade + Alpine *(phase 2)* |
| Landing + docs | `/`, `/docs/*` | Blade + Alpine + GSAP *(phase 6)* |

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate:fresh --seed
```

The admin seeder prints a generated password once. There is no default password.

**Admin 2FA is required by default** (`docs/00-overview.md` §8 — that panel moves
money and bans accounts). `ADVAULT_REQUIRE_ADMIN_2FA=false` turns the *requirement*
off for local development; two-factor auth stays available on both panels either
way. The variable defaults to `true`, so forgetting it in production is safe, and
the test suite pins it to `true` regardless of your `.env`.

**Set `ADVAULT_DEV_COUNTRY` or your local offerwall is empty.** Every request on
your machine arrives from `127.0.0.1`, no GeoIP database will ever place it, and
the wall fails closed on an address it cannot place (`docs/03-offerwall.md` §2) —
so `/wall` renders "we could not confirm your device" and no offers. Set it to a
country your seeded inventory targets (`ADVAULT_DEV_COUNTRY=EG` matches the demo
seeder) and private addresses resolve there, including from a phone on the same
wifi. Public addresses still go through the real databases, the variable is read
only while `APP_ENV=local`, and the test suite pins it blank regardless of your
`.env`.

`.env` expects MySQL on 3306 and Redis on 6379. Local dev on **MariaDB is fine** —
the schema uses nothing MySQL-only — but CI and production run MySQL 9.7, and the
concurrent-duplicate tests in phase 1 are meant to run against the production
engine, not a lookalike.

## Checks

```bash
composer lint      # Pint
composer analyse   # PHPStan level 5 (larastan)
composer test      # Pest
composer ci        # all three, as CI runs them
```

Tests run against `advalut_test` on MySQL/MariaDB, **not SQLite** — see the comment
in `phpunit.xml`.

### Working on Windows

`composer.json` declares `ext-pcntl` and `ext-posix` under `config.platform` so
Horizon installs on Windows, where neither extension exists. Only the Horizon
*daemon* needs them and that only ever runs on the Linux box; the config and
dashboard are fine locally.

## Deployment

The production server configuration lives in [`deploy/`](deploy/) — three nginx
server blocks (main, `wall.`, `api.`), the supervisor unit for Horizon, and
`deploy/deploy.sh`, which is [docs/11-infrastructure.md](docs/11-infrastructure.md)
§9's sequence in §9's order. Read [`deploy/README.md`](deploy/README.md) for what
has to be filled in per host and for the operator-owned half of the security
checklist.

```bash
sudo -u www-data deploy/deploy.sh
```

**`deploy/nginx/wall.advault.io.conf` clears `X-Frame-Options` and does it on
purpose.** The wall is an iframe on publisher domains; framing headers applied
across all three server blocks break every integration at once, and the failure
is invisible from our side. `tests/Feature/Security/SecurityChecklistTest.php`
asserts that asymmetry, along with the rest of the security checklist rows that
a machine can check.

Load test (Phase 7's 1000 wall renders/min and 100 postbacks/min) is
`scripts/load-test.js`, a [k6](https://k6.io) script. **Staging only** — read its
header before running it; it needs the wall rate limiters raised on the target
box or it measures 429s.

## Build order

[docs/12-roadmap.md](docs/12-roadmap.md). Money path before anything that makes it
look good.

- [x] **Phase 0 — Foundation.** Scaffold, both panels, full schema, models, tenant
      scoping, policies, seeders, CI.
- [x] **Phase 1 — Money path.** Nine drivers, sync with the catalogue-collapse
      guard, postback ingress, DB-level idempotency, append-only ledger, outgoing
      postbacks with the retry ladder, chargebacks, nightly reconciliation.
- [x] **Phase 2 — Offerwall.** Wall, HMAC, geo and device, eligibility, Redis
      cache, UUID clicks, RTL/Arabic, consent gate, rate limits, test mode and a
      test offer that fires a real conversion, icon proxy.
- [x] **Phase 3 — Fraud engine.** Pipeline, IP intelligence, every pre-click and
      post-conversion rule with a passing and a failing test, auto-ban, rules
      table, events log, review queue.
- [x] **Phase 4 — Admin panel.** Everything in `docs/07`, including the sync log
      and exports.
- [x] **Phase 5 — Publisher panel.** Everything in `docs/08`, including the
      offers inventory, one-time secret handover, sessions and preferences.
- [x] **Phase 6 — Marketing & docs.** Tokens, three bundles with the no-GSAP
      assertion, the Trace, landing, legal and company pages, contact form, docs
      with search, five snippets, Lighthouse budget in CI.
- [ ] **Phase 7 — Launch readiness.** Code-shaped parts done: security checklist
      as a test, load-test script, deploy artifacts, retention, alerting, health.
      The rest is operator work — real network credentials, the production box,
      the backup restore drill, the beta. See `deploy/README.md`.
