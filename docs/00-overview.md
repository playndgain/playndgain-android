# PLAYNDGAIN — Master Specification

> **Status:** Approved design, pre-implementation
> **Date:** 2026-08-15 (stack versions revised)
> **Scope:** v1
> **Stack:** Laravel 13 · Filament v5 · Livewire 4 · PHP 8.5 · Pest 5 · Tailwind v4 · Vite 8. Canonical version table in [11-infrastructure.md](11-infrastructure.md) §1.

---

## 1. What PLAYNDGAIN Is

PLAYNDGAIN is an **offerwall aggregator**. It does not create its own advertising demand. It:

1. Pulls offer catalogues from partner ad networks (Notik, Monlix, AdscendMedia, OGAds, AdGate Media, Lootably, Admantum, Adswedmedia) through their APIs.
2. Normalizes those offers into a single internal shape.
3. Serves them on a **hosted offerwall** that publishers embed in their app or site via an iframe.
4. Receives conversion postbacks from the partner networks.
5. Pays publishers a revenue share, and fires a signed S2S postback to the publisher so they can credit their end user.

Two dashboards: **admin** (us) and **publisher** (our customers). No advertiser dashboard in v1.

### The money path

```
Advertiser → PLAYNDGAIN → Publisher → End user
(confirms    (credits     (credits
 action)      payout)      coins)
```

Everything in this system exists to make that path **accurate, auditable, and hard to defraud**.

---

## 2. Decisions Already Made

These were settled during design. They are not open questions.

| Decision | Choice |
|---|---|
| Market sides | Aggregator only. No advertiser signup, no direct campaigns. |
| Offerwall delivery | Hosted iframe, plus native Android and iOS SDKs that read a JSON API on the wall host (`/sdk/v1`, docs/03 §9). No public catalogue REST API, no JS SDK. |
| Payout model | Rev-share % per publisher, calendar-month hold (clears on the 1st), $20 minimum, manual admin approval. |
| Partner integration | One generic adapter interface + concrete drivers. Accounts are created from **Networks → New network**, start `disabled`, and are enabled after credentials pass Test connection. A `direct` row is the house channel: offers we sell ourselves, added by hand. |
| Fraud | Admin-tunable rules engine. Every block logged with a reason. |
| Infrastructure | Single VPS: nginx + PHP-FPM + MySQL + Redis + Horizon. |
| Chargebacks | Partner reversal fires a `status=reversed` postback to the publisher with a negative amount and writes a negative ledger entry. |
| Publisher onboarding | Open signup, manual admin approval before any app can earn. |
| Publisher extras | Blacklists (offer/country/device) and end-user bans. **Not** sub-accounts, **not** CSV exports. |
| Compliance | EU consent gate + data controls. **Not** a registered IAB TCF vendor. |
| Landing + docs | **Blade + Alpine.js + Tailwind v4 + GSAP.** Server-rendered HTML. Node at build time only — nothing but PHP in production. |
| Brand | "PLAYNDGAIN". Obsidian/steel/amber. Deliberately not the violet-SaaS default. |
| Rev-share default | **70%** to publisher (assumption — one config value, `settings.default_rev_share`). |

### Explicitly out of scope for v1

Sub-accounts · CSV/Excel exports · offer catalogue REST API · advertiser panel · IAB TCF vendor registration · surveys · custom in-house offers · multi-currency payouts (USD only).

The schema leaves room for the catalogue API and the advertiser side. Nothing else is pre-built for them. See [12-roadmap.md](12-roadmap.md).

---

## 3. System Shape

One Laravel 13 application. Four surfaces.

| Surface | Path | Stack | Notes |
|---|---|---|---|
| Admin panel | `/admin` | Filament v5 panel (Livewire 4) | Full control. Networks, offers, publishers, money, fraud, logs. |
| Publisher panel | `/dashboard` | Filament v5 panel (Livewire 4) | Scoped to the authenticated publisher. Never sees another publisher's data. |
| Offerwall | `/wall`, `/click/{id}`, `/postback/{driver}` | Blade + Alpine | No Filament, no session. Cache-heavy, must survive traffic spikes. |
| Landing + docs | `/`, `/docs/*` | Blade + Alpine + GSAP | Server-rendered HTML. Same stack as the offerwall. |

### Two frontend stacks, not three

Livewire 4 (Filament panels) and Alpine (public site + offerwall). Livewire 4 still bundles Alpine 3 and its plugin set, so the public site adds no new runtime dependency to the application — only GSAP, and only on the marketing pages.

Three Vite entry points, three bundles. **The offerwall never loads GSAP** — it is a list of cards on a mid-range Android over 3G, and it stays at ~17 KB of JavaScript.

A React or Inertia layer was considered and rejected: it would have added a third frontend stack and a long-lived Node SSR process that, if it died, would silently drop the marketing site out of search results. Blade is HTML by default and has no such failure mode.

### Why one Laravel app instead of services

Because a single VPS was chosen and the surfaces share the same domain model. Splitting the offerwall into its own service buys nothing until postback volume actually threatens the panels — and the split point is already clean (the wall touches only `apps`, `offers`, `offer_clicks`), so it can be lifted out later without a rewrite.

### Production runtime

nginx · PHP-FPM · MySQL · Redis. That is the complete list.

Node is required to run `npm run build`, which emits hashed assets to `public/build`. Nothing from Node runs in production — no SSR process, no renderer, no bundler, no reverse proxy to a JavaScript server.

Every public page is HTML the moment PHP finishes. JavaScript is an enhancement layered on content that already exists, which is why the landing page still reads correctly if GSAP fails to load.

---

## 4. Domain Model at a Glance

Full definitions in [01-domain-model.md](01-domain-model.md).

```
User ──1:1── Publisher ──1:N── App ──1:N── OfferClick ──0:1── Conversion
                  │                                               │
                  ├──1:N── PaymentMethod                          ├──1:N── OutgoingPostback
                  ├──1:N── Withdrawal                             └──1:1── LedgerEntry
                  ├──1:N── LedgerEntry
                  ├──1:N── Blacklist
                  └──1:N── BannedEndUser

Network ──1:N── Offer ──1:N── OfferClick
   └──1:N── IncomingPostback
```

### Two rules that are not negotiable

1. **Money is `decimal(14,6)`.** Never float, never double, never a JS `number` that round-trips through JSON without care. Payouts of `$0.0037` are normal in this business and they accumulate across millions of rows.
2. **`ledger_entries` is append-only and is the only truth about money.** `publishers.balance` is a cached column, written inside the same DB transaction as the entry that changed it. Nothing else writes to it. A nightly job re-derives every balance from the ledger and alerts on drift.

---

## 5. The Five Flows

Each is specified in detail in its own file. Summary here so the whole system fits on one page.

### 5.1 Offer sync → [02-network-adapters.md](02-network-adapters.md)

Scheduled job per enabled network. `driver->fetchOffers()` returns `OfferDTO[]`. Upsert by `(network_id, external_id)`. Offers not seen in this run are marked `inactive` — never hard-deleted, because historical clicks and conversions reference them.

Eligible-offer lists are cached in Redis keyed by `app:{id}:country:{cc}:device:{type}`, short TTL.

### 5.2 Wall render → [03-offerwall.md](03-offerwall.md)

`GET /wall?appid=&userid=&sig=` → validate app is approved → verify HMAC → resolve geo + device from IP/UA → EU consent gate if required → filter offers by targeting, publisher blacklists, banned end users, and offers this user already completed → render.

### 5.3 Click → [03-offerwall.md](03-offerwall.md)

`GET /click/{offer}` → run pre-click fraud rules → create `offer_click` with a UUID `click_id` → substitute that `click_id` into the network's subid macro → `302` to the network's tracking URL.

The `click_id` is the join key for the entire conversion path. It is a UUIDv4, never sequential, and never guessable.

### 5.4 Conversion in → [04-conversions-postbacks.md](04-conversions-postbacks.md)

Network calls `POST|GET /postback/{driver}` → `driver->parsePostback()` → verify signature and source IP → resolve the click → **idempotency check on `network_txid`** → post-conversion fraud rules → write `conversion` + `ledger_entry` in one transaction → queue the outgoing postback.

> The idempotency check is the single most important line of code in this system. Networks retry. Networks send duplicates. Networks send the same conversion under two transaction IDs during an outage. `network_txid` carries a unique index and the insert is inside a transaction — a duplicate is a caught constraint violation, logged and dropped, not a double payment.

### 5.5 Postback out → [04-conversions-postbacks.md](04-conversions-postbacks.md)

Queued job builds the publisher's callback URL from their macro template, signs it, sends it, logs every attempt. Retries at 1m → 5m → 30m → 2h → 6h, then `failed` with an admin re-fire button.

**Chargeback:** partner sends a reversal → conversion goes `reversed` → negative ledger entry → a second postback fires with `status=reversed` and a negative `currency_amount`.

---

## 6. Money Timing

- Every conversion gets `clears_at` = start of the next calendar month. Chargebacks debit immediately and can take the balance negative.
- **Available balance** = cleared, non-reversed, non-withdrawn earnings.
- **Pending balance** = everything inside its hold window.
- Withdrawal requires available ≥ **$20**, goes `requested` → admin review → `processing` → `paid`.
- A reversal that lands *after* clearing produces a negative entry and is recovered from future earnings. If the publisher's balance goes negative and stays negative, the account is flagged for admin review — we do not chase debt automatically.

Detail in [06-ledger-payouts.md](06-ledger-payouts.md).

---

## 7. Fraud Engine

Rules are database rows with admin-editable thresholds, evaluated in a pipeline at two points: pre-click and post-conversion.

Day-1 rule set: duplicate conversion · one conversion per user per offer · VPN/datacenter IP · geo mismatch vs offer targeting · click→conversion velocity · impossible conversion speed · per-app conversion cap · per-user daily cap · auto-ban threshold.

Every evaluation that blocks something writes a `fraud_event` with the rule key, the inputs, and the decision. **Nothing blocks silently** — a publisher asking "why did my user not get credited" must always be answerable from the admin panel.

Detail in [05-fraud-engine.md](05-fraud-engine.md).

---

## 8. Security Requirements

These are requirements, not suggestions. They are called out here because several of them are easy to skip and expensive to retrofit.

| Area | Requirement |
|---|---|
| App secrets | `apps.secret_key` stored encrypted (`encrypted` cast). Shown in full once on creation, masked afterwards with a regenerate action. |
| Network credentials | `networks.credentials` is an `encrypted:array` cast. Never logged, never in an exception trace. |
| Incoming postbacks | Signature verification **and** source IP allowlist per network. Both, not either — unless an admin sets `allow_any_postback_ip` because the partner will not publish firing addresses. Empty `postback_ips` still rejects. |
| Outgoing postbacks | HMAC signature over `trans_id + payout + app_secret`. Documented so publishers can verify. |
| Click IDs | UUIDv4. Never sequential. |
| Wall access | HMAC on the wall URL when the app has `require_signed_wall` enabled. |
| Rate limiting | Per-IP and per-app limits on `/wall`, `/click`, and all postback endpoints. Redis-backed. |
| Panel auth | 2FA available on both panels, **required** for admin accounts. |
| PII | End-user IDs are opaque publisher-supplied strings. We store IP and country; IP anonymization is a per-app toggle. No emails, no names, no device advertising IDs in v1. |
| Admin actions | Every money-affecting or ban action is written to an audit log with actor, target, before/after. |

---

## 9. Testing Strategy

Pest. The test suite exists to protect the money path — coverage elsewhere is a nice-to-have.

**Must have tests:**

- Postback idempotency: same `network_txid` twice → one conversion, one ledger entry.
- Rev-share math across a range of payouts, asserted to 6 decimal places.
- Chargeback: reversal produces a negative entry and the balance returns to its pre-conversion value.
- Hold window: a conversion inside its window is not withdrawable; past it, it is.
- Every fraud rule: one blocking case, one passing case.
- Targeting: an offer that excludes a country never appears in that country's wall payload.
- Publisher scoping: publisher A's panel cannot read publisher B's rows (test the policy, not the UI).
- Outgoing postback retry schedule and the `failed` terminal state.

**Test double:** a `FakeNetworkDriver` implementing the adapter interface, so network tests never touch the internet.

---

## 10. Document Map

| File | Contents |
|---|---|
| [01-domain-model.md](01-domain-model.md) | Every table, column, index, and relationship. |
| [02-network-adapters.md](02-network-adapters.md) | Adapter interface, DTOs, sync job, driver checklist. |
| [03-offerwall.md](03-offerwall.md) | Wall render, targeting, click handling, consent, theming. |
| [04-conversions-postbacks.md](04-conversions-postbacks.md) | Conversion pipeline, idempotency, outgoing postbacks, macros, chargebacks. |
| [05-fraud-engine.md](05-fraud-engine.md) | Rule pipeline, day-1 rules, scoring, bans. |
| [06-ledger-payouts.md](06-ledger-payouts.md) | Ledger design, balance derivation, withdrawals, reconciliation. |
| [07-admin-panel.md](07-admin-panel.md) | Filament admin resources, page by page. |
| [08-publisher-panel.md](08-publisher-panel.md) | Filament publisher resources, page by page. |
| [09-landing-site.md](09-landing-site.md) | Brand system, the Trace signature, Blade/Alpine/GSAP marketing site. |
| [10-developer-docs.md](10-developer-docs.md) | Public developer documentation structure and content. |
| [11-infrastructure.md](11-infrastructure.md) | Server layout, deploy, queues, backup, monitoring. |
| [12-roadmap.md](12-roadmap.md) | Build order, milestones, definition of done. |
