# 07 — Admin Panel

Filament v5 panel at `/admin`. Full platform control.

**Access:** `role = admin` **and** confirmed 2FA. The 2FA requirement is enforced in panel middleware, not left to the user's discretion — this panel can move money and ban accounts.

---

## 1. Panel Configuration

```php
// app/Providers/Filament/AdminPanelProvider.php
$panel
    ->id('admin')
    ->path('admin')
    ->login()
    ->colors(['primary' => Color::Amber])
    ->authMiddleware([Authenticate::class, EnsureIsAdmin::class, RequireTwoFactor::class])
    ->navigationGroups([
        'Overview', 'Supply', 'Publishers', 'Money', 'Risk', 'Logs', 'System',
    ]);
```

---

## 2. Navigation

```
Overview
  ├── Statistics                 (dashboard — today and work waiting)
  └── Analytics                  (filtered money, supply, chargebacks, fraud)
Supply
  ├── Networks
  ├── Offers
  └── Sync Log
Publishers
  ├── Publishers
  ├── Apps
  └── Signup Queue               (badge: pending count)
Money
  ├── Conversions
  ├── Withdrawals                (badge: open requests)
  ├── Ledger
  └── Profit History
Risk
  ├── Review Queue               (badge: pending conversions)
  ├── Protection
  ├── Fraud Rules
  ├── Fraud Events
  └── Banned Users
Logs
  ├── Offerwall Clicks
  ├── Incoming Postbacks
  ├── Outgoing Postbacks
  └── Audit Log
System
  ├── Settings
  ├── Admin Users
  └── Health
```

---

## 3. Statistics (Dashboard)

The home dashboard is the morning check. Investigation (a date range, a publisher, a leaking offer) lives on **Analytics** (`/admin/analytics`). See `docs/superpowers/specs/2026-09-09-admin-analytics-design.md`.

Widgets, top to bottom.

**Row 1 — today, each with a sparkline and a vs-yesterday delta:**
Revenue (what networks owe us) · Publisher payout · **Profit** (revenue − payout) · Conversions · Clicks · Conversion rate

**Row 2 — health, red when breached:**
Pending withdrawals ($ and count) · Failed postbacks (24h) · Networks in error · Conversions in review · Publisher balance liability (total owed)

**Row 3 — charts:**
Revenue vs payout, 30 days, dual-line · Conversions by network, stacked area, 7 days · Top 10 countries by revenue · Top 10 publishers by revenue

**Row 4:** Latest 10 conversions · Latest 10 signups

All money figures on this page read `daily_stats` and `ledger_entries`. Never an ad-hoc `SUM` over `conversions` in a widget — see [06-ledger-payouts.md](06-ledger-payouts.md) §7. Analytics may count reversed conversions and sum their payout only under the Chargebacks label.

---

## 4. Supply

### Networks

**Table:** logo · name · driver · status badge · offers count · last sync (relative) · sync status icon · priority

**Actions per row:** Edit · **Test connection** · **Sync now** · Enable/Disable · View offers

**Form:**
- Name, logo upload, website, priority, sync interval
- **Credentials** — fields rendered dynamically from `driver::credentialSchema()`. Secret fields are password inputs, masked after save, with a *Reveal* action that writes to the audit log.
- Postback IP allowlist (repeater), with the network's postback URL displayed and copyable: `https://api.playndgain.com/postback/{driver}`. Empty list rejects. **Allow any source IP** is the explicit opt-out when a partner will not publish addresses.
- Last sync error shown read-only when present

**Test connection** calls `driver->testConnection()` and shows the returned offer count. Saving credentials without testing them is the most common way a network silently never syncs.

### Offers

**Table:** icon · name · network · payout · conversion type · countries (first 3 + count) · devices · status · clicks 7d · conversions 7d · last seen

**Filters:** network · status · conversion type · country · device · payout range · has conversions

**Actions:** **View events** (slide-over: each goal name, the network payout, an example user reward at the default share and 1,000 Coins / $1, plus the offer's instructions) · Block globally (with reason) · Unblock · **Feature** (pins to the top of every wall) · Preview (opens the tracking URL with a test click ID)

Offers are read-only apart from status and featured. Everything else comes from the network and would be overwritten on the next sync.

### Sync Log

Per-run: network · started · duration · offers found · offers deactivated · status · error. Filterable by network and status. This is where you look when a publisher says the wall went empty.

---

## 5. Publishers

### Publishers

**Table:** pub ID · name · email · country · status · apps count · rev share · balance · lifetime earnings · **fraud score** · joined

**Filters:** status · country · has balance · negative balance · fraud score range

**Actions:** Approve · Suspend · Ban (with reason) · **Impersonate** (audit-logged, banner shown in the publisher panel while active) · Adjust balance

**View page tabs:**
1. **Overview** — profile, status, rev share, hold days, min payout, balances (available / pending / lifetime)
2. **Apps** — their placements with per-app stats
3. **Conversions** — filtered list
4. **Ledger** — full entry history
5. **Withdrawals** — history
6. **Risk** — block rate, flag rate, reversal rate, conversion rate vs platform median, top triggered rules
7. **Notes** — admin-only free text

**Adjust balance** opens a form requiring an amount, a type (`adjustment`), and a **mandatory reason**. Writes a ledger entry and an audit log row. No silent balance edits exist anywhere in this panel.

### Apps

**Table:** app ID · icon · name · publisher · platform · status · postback configured (✓/✗) · clicks 7d · conversions 7d · revenue 7d

**Form:** placement, currency, postback, integration, appearance, restrictions, and **Networks** — admin-only. Off writes an app-scoped network blacklist; the wall and partner strip honour it immediately. Publishers never see this list.

**Actions:** Approve · Reject (with reason) · Pause · **Open wall** (renders the live wall in a new tab) · **Send test postback**

**Send test postback** fires a fake conversion through the real outgoing pipeline to the publisher's URL. It resolves 90% of "your postbacks don't work" tickets in one click.

### Signup Queue

Publishers at `status = pending`. Each card shows: email, country, declared traffic source, signup IP, and **whether that IP or email pattern matches an existing banned account**. Approve / Reject with a reason.

That last signal is the whole reason this is a dedicated page and not a filter on the publishers table.

---

## 6. Money

### Conversions

**Table:** ID · trans ID · publisher · app · offer name · network · revenue · payout · **profit** · currency amount · status · country · created · clears at

**Filters:** status · publisher · app · network · date range · country · payout range · is test

**Actions:** View (full detail: the click, the raw incoming postback, every outgoing attempt, fraud events) · **Approve** (pending only) · **Reject** (pending only, reason required) · **Force reverse** (admin-initiated chargeback, reason required, audit-logged) · **Re-send postback**

### Withdrawals

The queue that matters. Sorted by `requested_at` ascending.

**Table:** ID · publisher · amount · method · masked destination · status · **fraud score** · account age · requested

**Row detail panel:** publisher's lifetime earnings, reversal rate, last 30 days of conversions, previous paid withdrawals, and any open fraud flags — everything needed to decide without leaving the page.

**Actions:** Approve → `processing` · Mark paid (requires `tx_reference`) · Reject (reason required)

Bulk approve exists but is disabled by default in settings. Bulk-approving payouts is exactly the action you want to be slightly annoying.

### Ledger

Read-only. Every entry, every publisher. **Filters:** publisher · type · date range · amount range. **Columns:** date · publisher · type · amount · balance after · reference (linked) · description · created by.

No create, no edit, no delete actions exist on this resource — the model would throw anyway, but the UI should not offer what the model forbids.

### Profit History

Per-day and per-month: revenue · payout · profit · margin % · conversions · reversals · reversal rate. Grouped by network and by publisher, exported on demand. This is the P&L view.

---

**Statements.** Each withdrawal settles one or more monthly statements ([06-ledger-payouts.md](06-ledger-payouts.md) §9). *Mark paid* flips them to Paid on the publisher's Payments page in the same transaction; *Reject* hands them back to Pending with the reason. The admin never edits a statement directly.

## 7. Risk

### Review Queue

Conversions at `pending` because a `flag` rule fired. Each card shows the conversion, the fired rules with their context, the click (IP, country, device, UA), the IP verdict (datacenter? proxy? ASN org?), and this end user's history with this app.

**Approve** writes the ledger entry and fires the postback. **Reject** records the reason and moves nothing. Both audit-logged.

### Protection

Everything that decides whether an address may see a wall, in one place (docs/05 §4). Four sections.

**Providers** — ProxyCheck.io and IPQualityScore: enabled, priority, API key, timeout, daily cap, cache lifetime, fail-open, plus live state next to each row — spent today against the cap, breaker open or closed, last error and when.

**Local databases** — GeoLite2-Country, GeoLite2-ASN, IP2Proxy PX11: present, size, age, and an **Update now** action queuing `geoip:update`. Health keeps its own green-or-red line; this is where you act on it.

**Live IP test** — an address in, one row back per enabled provider plus the local databases: country, ASN, org, proxy type, usage type, threat, risk score, response time. Calls providers directly and never writes the merged verdict cache real traffic reads, so testing a suspicious address never installs a verdict that then governs it — though it does spend against the same daily cap a real escalation would.

**IP rules** — the render-gate rules inline, read-only: key, name, action, weight, enabled, gates render, and the same 24h/7d fire counts the Fraud Rules resource shows, filtered to this family, so an operator can see a proxy threshold misfiring without leaving the page. Tuning stays on the Fraud Rules resource — one editable surface over `fraud_rules`, one audit trail.

### Fraud Rules

Table of all rules: key · name · stage · action select (inline) · enabled toggle (inline) · **triggered 24h** · **triggered 7d** · config editor.

The trigger counts are the tuning instrument. A rule that never fires is dead weight; a rule firing on 40% of traffic is miscalibrated.

### Fraud Events

Full log: rule · stage · subject (linked) · publisher · app · end user · action taken · context JSON · created. Filterable by everything.

### Banned Users

End-user bans across all publishers. Columns: publisher · app · end user ID · banned by (`system`/`publisher`) · reason · expires · created. Actions: Unban, Extend, Make permanent.

---

## 8. Logs

### Offerwall Clicks

**Table:** app · click ID · offer name · IP + country flag · device · risk score · blocked (with reason) · converted (✓/✗) · time

**Filters:** app · publisher · offer · country · device · blocked · converted · risk score range · date range

High volume — indexed and paginated, default filtered to the last 24 hours. Loading this table unfiltered across all history is a query that will hurt.

### Incoming Postbacks

Raw log of everything networks sent. **Table:** driver · IP · result badge · reject reason · conversion (linked) · created. **Filters:** driver · result · date range.

**Unmatched tab** — `result = unknown_click`, with a **Match to click** action that attaches it and runs the normal credit path.

### Outgoing Postbacks

**Table:** conversion · app · kind (credit/reversal) · attempt · HTTP status · duration · status · created. **Filters:** app · status · kind · HTTP status. **Actions:** View (full URL redacted, response body) · Re-send.

### Audit Log

Every admin action touching money, status, or bans: actor · action · subject · before/after diff · IP · time. Read-only, no delete, ever.

---

## 9. System

### Settings

Grouped form: platform (name, support email, logo) · defaults (rev share, hold days, min payout) · signups (enabled, auto-approve) · offerwall (offer limit, icon proxy) · compliance (consent countries) · alerts (email recipients, thresholds).

### Admin Users

Create and manage admins. 2FA status per user, shown and enforced. Last login IP and time.

An admin cannot change their own email from Profile or from this table. Another admin changes it here.

### Health

One page answering "is anything broken right now": queue depths per queue · failed jobs (24h) · networks in error · postback failure rate · Redis connectivity · MySQL connectivity · disk usage · last successful backup · GeoIP database ages.

Green or red. No prose.

---

## 10. Cross-Cutting

**Global search** across publishers, apps, offers, conversions (by `trans_id`), and clicks (by `click_id`). Searching a `trans_id` a publisher pasted into a support email must land on the conversion in one step.

**Exports** — CSV on conversions, ledger, withdrawals, and profit history. Queued for large ranges, emailed when ready. Admin-side only; publisher exports were cut from v1.

**Dark mode** — Filament default, respects system preference.

**Authorization** — policies on every resource, not middleware alone. Filament's `canViewAny` hooks into the policy so a missing policy fails closed.
