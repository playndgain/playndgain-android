# 12 — Roadmap

Build order, milestones, and what "done" means for each.

**Sequencing principle:** the money path is built and proven before anything that makes it look good. A beautiful dashboard over a conversion pipeline that double-pays is worth less than nothing.

---

## Phase 0 — Foundation (week 1)

Laravel 13 · Filament v5 · Livewire 4 · PHP 8.5 · MySQL 9.7 LTS · Redis 8 · Horizon 5 · Pest 5. Full table in [11-infrastructure.md](11-infrastructure.md) §1.

- [ ] Project scaffold, both Filament panels registered and reachable
- [ ] All migrations from [01-domain-model.md](01-domain-model.md), in dependency order
- [ ] Models, relationships, casts, enums
- [ ] `PublisherScope` global scope + policies + the cross-tenant leak test
- [ ] Seeders: settings, the launch networks (disabled), fraud rules, admin user
- [ ] CI: Pest, Pint, PHPStan level 5

**Done when:** `php artisan migrate:fresh --seed` produces a working database, both panels log in, and the cross-publisher isolation test passes.

---

## Phase 1 — Money Path (weeks 2–3) ⭐

The core. Nothing else matters until this is right.

- [ ] `NetworkDriver` interface, `AbstractDriver`, `NetworkDriverManager`
- [ ] `FakeNetworkDriver` + fixtures
- [ ] `SyncNetworkOffersJob` including the catalogue-collapse guard
- [ ] Postback ingress: raw logging, IP allowlist, signature verification, parse
- [ ] Conversion creation with the **unique-index idempotency** path
- [ ] `LedgerEntry` with immutability enforcement and locked balance updates
- [ ] Outgoing postback job: macros, signature, retry schedule, per-attempt logging
- [ ] Chargeback path: reversal, negative entry, reversal postback
- [ ] Nightly reconciliation with drift alerting

**Done when the full money-path test suite is green**, including the concurrent-duplicate test against a real MySQL instance. Not SQLite — the constraint under test is the thing SQLite would fake.

> This phase has no UI. Resist adding one. Every hour spent on a dashboard here is an hour not spent on the code that decides whether you pay the same conversion twice.

---

## Phase 2 — Offerwall (week 4)

- [ ] Wall route, app resolution, HMAC verification
- [ ] GeoIP + device detection with caching
- [ ] Eligibility filter: targeting, blacklists, bans, completed offers
- [ ] Redis offer cache with correct tag invalidation
- [ ] Click handler, UUID click IDs, 302 redirect
- [ ] Blade + Alpine UI, mobile-first, theming from `apps.theme`
- [ ] **RTL + Arabic wall** — `dir="rtl"`, logical CSS properties throughout, `ar` translation file. Egypt and MENA are the top geo in the reference data; `03-offerwall.md` ships `ar` and this is the phase that has to build it.
- [ ] Consent gate + `/privacy/*` data endpoints
- [ ] Rate limits
- [ ] Test mode + test offer
- [ ] Offer icon proxy and cache

**Done when:** a real click through a real network driver produces a real conversion and a real publisher postback, end to end, on a staging box. Lighthouse on the wall ≥ 90 mobile.

---

## Phase 3 — Fraud Engine (week 5)

- [ ] `FraudPipeline`, `FraudRule` contract, `FraudEvent` recording
- [ ] IP intelligence: GeoLite2 country + ASN, IP2Proxy LITE, cached
- [ ] All pre-click rules
- [ ] All post-conversion rules
- [ ] Auto-ban with 7-day expiry
- [ ] Admin: rules table with trigger counts, events log, review queue

**Done when:** every rule has a passing and a failing test, and a flagged conversion appears in the review queue and can be approved into a real ledger entry.

---

## Phase 4 — Admin Panel (week 6)

Everything in [07-admin-panel.md](07-admin-panel.md).

- [x] Statistics dashboard reading `daily_stats`
- [x] Admin Analytics (`/admin/analytics`) — filtered money, supply, chargebacks, fraud sources
- [ ] Networks (credentials form, test connection, sync now)
- [ ] Offers, publishers, apps, signup queue
- [ ] Conversions, withdrawals queue, ledger, profit history
- [ ] All log resources
- [ ] Settings, health page, audit log

**Done when:** a publisher can be taken from signup to approved to paid entirely through the UI, and every step of that appears in the audit log.

---

## Phase 5 — Publisher Panel (week 7)

Everything in [08-publisher-panel.md](08-publisher-panel.md).

- [ ] Registration → pending state → dashboard with banner
- [ ] Dashboard widgets, three balances with correct definitions
- [ ] Apps CRUD with the tabbed integration form, live preview, currency example
- [ ] **Offers inventory page** — per-app eligible catalogue, publisher payout and user reward only (never network payout or margin), block-from-row
- [ ] Test postback button
- [ ] Statistics, transactions, clicks, postback reports
- [ ] Withdrawals, payment methods
- [ ] Blacklists with impact preview, banned users
- [ ] Profile, 2FA, sessions, notifications

**Done when:** a new publisher integrates from signup to first test conversion **without reading anything but the panel**. Watch someone do it. Fix whatever made them pause.

---

## Phase 6 — Marketing & Docs (week 8)

- [ ] Design tokens in `resources/css/tokens.css`, imported by the site, the offerwall, and the Filament theme
- [ ] Tailwind v4 + Alpine + GSAP wired through Vite; three entries, three bundles
- [ ] **Bundle assertion** — the offerwall build contains no GSAP. Write this before the animation work, not after.
- [ ] **The Trace** — hero timeline, sticky rail, ScrollTrigger playhead, reduced-motion path
- [ ] Landing page, all sections, live stat counters
- [ ] Legal pages — real content, not placeholders
- [ ] Docs: Markdown pipeline, server-side highlighting, nav, ToC scroll-spy, `docs:index` + Fuse search
- [ ] Docs credential pre-fill — **assert the app secret never appears in any docs response**
- [ ] Snippets in 5 languages, each complete and runnable
- [ ] Contact form → controller, validation errors back to the Blade view
- [ ] Lighthouse CI gate on the landing route

**Done when:** the page renders completely with JavaScript disabled, Lighthouse Performance ≥ 95 and Accessibility = **100**, and a developer who has never seen the product integrates from the docs alone.

> The JS-disabled check is the one that matters. It is the difference between animation that enhances content and animation that *is* the content — and only the first one survives a script failing to load.

---

## Phase 7 — Launch Readiness (week 9)

- [ ] Two real network integrations live with real credentials
- [ ] Production server built to [11-infrastructure.md](11-infrastructure.md)
- [ ] Monitoring, alerting, backups configured
- [ ] **Backup restore drill passed**
- [ ] Security checklist complete, top to bottom
- [ ] Load test: 1000 wall renders/min, 100 postbacks/min sustained
- [ ] Beta with 3–5 publishers
- [ ] Support process and inbox

**Done when:** a beta publisher earns and is paid a real withdrawal, and the ledger reconciles to the cent.

---

## v1 Definition of Done

- A publisher signs up, is approved, creates an app, embeds the wall, and earns.
- Their users see geo- and device-targeted offers from at least two networks.
- Conversions credit correctly, exactly once, ever.
- Chargebacks reverse correctly and the publisher is told.
- Withdrawals request, review, and pay, with a ledger that reconciles.
- Fraud rules block and the blocks are explainable.
- Admin can run the entire business from `/admin`.
- Docs let a developer integrate unaided.
- Marketing site does not look like a bought template.

---

## Post-v1

Ordered by expected value, not by how interesting they are to build.

### Next

1. **REST offer catalogue API** — publishers building their own UI. Highest-demand missing feature, and the schema is already shaped for it.
2. **CSV/Excel exports** on publisher reports. Cut from v1; asked for constantly.
3. **Sub-accounts** — team members with roles.
4. **Automated payouts** — PayPal Payouts API, crypto. **Only after** the fraud rules have real data behind them. Automating payouts on an untuned fraud model is how a platform gets drained overnight.
5. **Publisher offer sorting** — let them optimize their own wall order.

### Later

6. Mobile SDKs (Android/iOS).
7. Surveys and in-house offers — requires direct advertiser relationships.
8. Advertiser panel — the third side of the market. Real product expansion, not a feature.
9. Fraud scoring model trained on `fraud_events` and reversal outcomes.
10. Real-time WebSocket dashboard updates.
11. Multi-language wall beyond en/ar.
12. Referral program — the `referral` ledger type already exists.
13. IAB TCF v2.2 vendor registration, if tier-1 EU demand justifies the paperwork.

### Deliberately never

- **Auto-approving publishers.** The manual gate is the cheapest fraud control we have.
- **Hiding chargebacks from publishers.** Short-term retention, long-term reputational loss.
- **Editing ledger entries.** Corrections are adjustments. Always.

---

## Risks

| Risk | Impact | Mitigation |
|---|---|---|
| Network denies our publisher application | No supply, no product | Apply to 5+ before writing drivers. This gates Phase 1. |
| Network API changes silently | Empty walls | Catalogue-collapse guard + sync failure alerts |
| Publisher fraud farm | Direct financial loss | Manual approval + calendar-month hold + fraud engine + manual withdrawals |
| Double-payment bug | Direct loss, hard to detect | DB-level idempotency + nightly reconciliation + drift paging |
| Publisher ignores reversals | Their loss, our support burden | Bold in quick start, in the snippets, on its own docs page |
| GSAP fails to load; page built on `opacity: 0` | Blank marketing site for that visitor | Reveals start visible in CSS and are hidden by JS on mount; JS-disabled test in CI |
| Single VPS failure | Full outage | Off-site backups, tested restore, documented rebuild |
| Chargeback wave after payout | Negative balances | Hold window, per-publisher hold override, withdrawal review |

**The first row gates everything.** Get approved by at least two networks before Phase 1 starts. A perfect aggregator with no supply is a database schema.
