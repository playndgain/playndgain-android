# 08 — Publisher Panel

Filament v5 panel at `/dashboard`. The product our customers actually use.

**Access:** `role = publisher`. Every query is scoped to the authenticated publisher by a global scope **and** a policy. Two mechanisms, because the cost of a leak here is one publisher seeing another's revenue.

---

## 1. Panel Configuration

```php
$panel
    ->id('publisher')
    ->path('dashboard')
    ->login()
    ->registration(RegisterPublisher::class)
    ->passwordReset()
    ->emailVerification()
    ->profile()
    ->colors(['primary' => Color::Amber])
    ->authMiddleware([Authenticate::class, EnsureIsPublisher::class]);
```

### Scoping

```php
abstract class PublisherScopedResource extends Resource
{
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('publisher_id', auth()->user()->publisher->id);
    }
}
```

Every resource extends this. Every model in the publisher's world also carries a `PublisherScope` global scope. A test asserts publisher A cannot read publisher B's rows through any resource — testing the policy, not the UI.

### Account states

| State | What the panel does |
|---|---|
| `pending` | Full navigation visible, persistent banner: *"Your account is under review. You can create apps now — they'll go live once approved."* App creation allowed, wall serves nothing, Offers catalogue and blacklist offer picker are empty. |
| `approved` | Normal. |
| `suspended` | Panel access blocked. Login blocked with a message explaining the suspension. |
| `banned` | Login blocked with a message. |

Letting pending publishers explore and configure is deliberate — the alternative is an empty dashboard and a support ticket asking what to do next.

---

## 2. Navigation

```
Dashboard
Apps
Offers
Statistics
Transactions
Offerwall Clicks
Postback Reports
Payments
Blacklists
Banned Users
Documentation      → external link to /docs
My Profile
```

Flat. Matches the reference layout, which publishers in this vertical already know.

---

## 3. Dashboard

**Header:** welcome line with name, app selector (*All Apps* / individual), live clock, and a *Live* indicator.

**Earnings row:** Today · This Month · Last Month · This Year — each with a vs-previous-period delta.

**Balance row:**
- **Available** — `withdrawableBalance()`, i.e. cleared minus any open request, linking to Payments. Never `clearedBalance()` — see [06-ledger-payouts.md](06-ledger-payouts.md) §2.
- **Pending** — in hold, with a tooltip: *"Earnings clear at the start of next month"*
- **Lifetime earnings**

**Stats row:** Clicks (7d) · Conversions (7d) · Conversion rate · Processing withdrawals · **Chargebacks (30d)**

**Bottom row:** Top countries by clicks with share and trend · Latest 10 transactions

Every card links to the filtered list behind it. A number with no way to see what produced it generates support tickets.

### Chargebacks are shown prominently on purpose

Hiding reversals until a publisher notices their balance dropped is how trust dies. The card is on the dashboard, and clicking it lists exactly which conversions reversed and when.

---

## 4. Apps

### List

Cards or table: icon · name · app ID · platform · status badge · postback configured (✓/✗) · clicks 7d · conversions 7d · earnings 7d.

### Create / Edit — tabbed form

**1. Details** — name, URL, platform, icon upload.

**2. Integration** *(the page that decides whether this publisher ever launches)*

- App ID and Secret key, copyable. Secret shown in full **once** on creation, masked afterwards, with a **Regenerate** action that warns it invalidates existing signatures.
- **Iframe snippet**, pre-filled with the real app ID, one-click copy:
  ```html
  <iframe src="{APP_URL}/wall?appid=10042&userid=USER_ID"
          width="100%" height="800" frameborder="0"></iframe>
  ```
- **Live preview** of the wall in a panel beside the form, reflecting the current theme and country selector.
- Signed-URL toggle, with the signature formula and a snippet when enabled.

**3. Currency** — currency name, rate (units per $1), and a **live example**: *"A $0.50 offer at 70% pays your user 350 Coins."* The example updates as they type. Currency rate misconfiguration is a top-three integration error and this one line prevents most of it.

**4. Postback** — callback URL with the full macro reference inline, GET/POST, and a **Send test postback** button that fires a real request and shows the response status, body, and duration.

**5. Appearance** — theme colours, radius, dark toggle, live preview. Contrast is validated as they pick, with a warning when their palette fails 4.5:1. Publishers pick unreadable colour pairs constantly; warning them is cheaper than the support thread.

**6. Restrictions** — blocked countries, daily conversion cap, IP anonymization toggle, test mode toggle.

Which suppliers fill the wall is admin-only. The publisher sees offers and the iframe, never a network name.

---

## 5. Offers (Inventory)

The browsable offer catalogue. A publisher's answer to *"what can my users actually do, and what is it worth?"* — and the page they blacklist from.

Read-only. Scoped to what is genuinely eligible for their apps right now: active offers on active networks, minus their own blacklists, minus offers blocked globally.

**App selector at the top.** Eligibility is per-app — blacklists and country blocks differ between placements — so the list is meaningless without knowing which app it describes. Defaults to their first active app, never to "all".

A pending, paused, or rejected app is never the default. Until the account is Approved and the selected app is Active the table is empty: heading **Waiting for approval**, body **Offers appear after your account and this app are approved.** (or **…and an app are approved.** when no app is selected). The page stays in the nav.

**Columns:** icon · name · **your payout** (their rev-share applied, USD) · **user reward** (payout × currency rate, in their currency) · countries · devices · conversion type · clicks 7d · conversions 7d · CR 7d

**Filters:** country · device · conversion type · payout range · has conversions

**Sort:** payout · user reward · conversions 7d · CR 7d

**Row actions:**
- **View events** — slide-over of each goal, what it pays *them* (rev-share applied), and the user reward in the selected app's currency. A single-step offer still opens: the offer is the event.
- **Block this offer** — writes a blacklist entry for the selected app, flushes that app's cache, offer disappears from the wall within seconds.
- **Preview** — opens the offer's landing page with a test click ID, so they can see what their users see before deciding.
- **View performance** — their own clicks and conversions for that offer.

### What is deliberately not shown

**Network payout, and therefore our margin.** The publisher sees *their* payout — the post-rev-share figure. Exposing what the network pays us turns every renewal conversation into a margin negotiation, and the rev-share percentage they agreed to already tells them everything they are entitled to know.

**Which network supplied the offer.** A publisher who can read the name can go direct. They block bad offers, not suppliers. Admin turns a network off for a placement from the app form.

### Why this page exists at all

Without it, a publisher's only view of their inventory is the wall itself, rendered for one country on one device. They cannot answer "do I have good US Android inventory" without spoofing a device, and they cannot blacklist a bad offer without first finding it by hand. Both are common enough that its absence generates support tickets.

## 6. Statistics

Date range picker (today / 7d / 30d / this month / last month / custom), app filter, country filter.

**Chart:** earnings over time, with clicks and conversions on a secondary axis.
**Breakdowns:** by app · by country · by offer · by device. Each a sortable table with clicks, conversions, CR, and earnings.

Reads `daily_stats`, never raw click rows.

---

## 7. Transactions

The conversion history. Matches the reference screenshot's shape.

**Columns:** # · TX ID · app · offer name · type (+/−) · amount (USD) · currency amount · status · country · date

**Filters:** app · status · date range · type · country. **Search:** TX ID, offer name, end user ID.

**Row detail:** end user ID, click time, conversion time, IP country, clears-at date, and the postback delivery status with a link to the attempt log.

Reversals appear as negative rows with a distinct treatment — not a subtle grey minus sign that a publisher scrolls past.

---

## 8. Offerwall Clicks

**Columns:** app · click ID · offer name · IP + country flag · device · time · converted (✓/✗)

**Filters:** app · country · device · converted · date range.

Default range: last 24 hours. IPs are shown in full unless the app has `anonymize_ip` enabled, in which case they are masked here too — the publisher's own privacy setting applies to their own view.

---

## 9. Postback Reports

Delivery log for their own postbacks. This page is the answer to "your postbacks aren't working".

**Columns:** ID · status badge · app · click ID · URL (redacted) · HTTP status · attempt · duration · created

**Filters:** app · status · date range.

**Row detail:** full resolved URL with the signature masked, the response body (2 KB), timing, and the retry schedule if it is still retrying.

**Actions:** **Re-send** (rate-limited to 10/min per app).

A **Postback health** summary sits at the top: success rate over 24h, failures, average response time. Amber below 95%, red below 80%.

---

## 10. Payments

One page for everything money — the shape publishers know from the networks they already work with.

**Header cards:** This Month · Pending Payment · Total Paid · Minimum Payout ($20) · Payment Terms (NET 30 — "paid by the last day of the following month", with the next due date once a statement is open).

Every figure is the ledger's. "This month" is computed exactly the way next month's statement will be — [06-ledger-payouts.md](06-ledger-payouts.md) §9 — so the card and the row it turns into never disagree. "Pending payment" is the cleared balance plus anything an approved-but-unpaid withdrawal has already debited. "Total paid" is the sum of paid withdrawals.

**Payment method:** crypto only — asset + network + address, with the chain spelled out under the select — edited in place with a **Save** and an **Activate** button and an *Active* pill. PayPal, bank and Wise survive in `PaymentMethodType` for the history of payouts already made but are no longer offered; a legacy destination that still holds the Active flag is named on the card so the publisher knows why nothing reaches the wallet they can see, and the first wallet they save takes the flag over it. One destination per type per publisher is a unique key. Details are the publisher's own and are shown in full; there is no mask-and-reveal. Beside the card, a short "How you get paid" note states the cycle.

*"Changing a destination does not move a payout that is already requested"* — because the destination is snapshotted at request time and publishers assume otherwise.

A suspended account sees the cards and cannot change them (§1); the policy refuses the write, not just the button.

**Payment history:** one row per calendar month — Period · Due date · Payment amount (gross) · Chargeback (red) · Actual payment (net, green) · Status (Pending / Carried forward / Paid) · Method · **View**. Green and red here are money direction, never state — design.md §2.

**Payment details modal:** Status · Period · Due date tiles; the breakdown (gross, chargebacks, adjustments when non-zero, "carried from earlier periods" when the payout covers more than this month, net); and — once a payout exists — where it went (currency, network and wallet for crypto; email for PayPal/Wise; account for bank), the transaction ID the admin recorded, the paid date, and the other months the same payout settled. A pending month has no destination yet, so that section is absent.

There is no *Request withdrawal* button. Payouts open themselves on the 1st; a publisher who fails a rule sees the reason on the statement and in a mail.

---

## 11. (merged into §10)

Payment details are edited on the Payments page — §10.

---

## 12. Blacklists

Their own blocks. Tabs: **Offers** · **Countries** · **Devices**.

- Offers — searchable picker over the offers currently eligible for their apps, with payout shown. The picker is empty until the account is Approved and the chosen app (or at least one app, for “all apps”) is Active. Country and device blocks still save.
- Countries — multi-select with flags.
- Devices — checkboxes.

Networks are not a publisher control. Naming a supplier is how they go around us; admin disables a network per app from the admin app form.

Each entry scoped to a specific app or *all apps*, with an optional reason. Saving flushes that app's offer cache immediately, so the change is visible on the wall in seconds, not five minutes.

**Impact preview:** *"This will remove 47 offers from your wall (est. −12% of eligible inventory)."* Publishers blanket-block and then ask why earnings dropped.

---

## 13. Banned Users

Their end users. **Columns:** end user ID · app · reason · banned by (`you` / `system`) · expires · created.

**Actions:** Ban (form: end user ID, app or all, reason, optional expiry) · Unban · bulk ban by pasting a list of IDs.

System-issued bans are shown but not removable by the publisher — an auto-ban the publisher can lift is not a control.

---

## 14. My Profile

Matches the reference screenshot: Profile information (name, locked email, avatar) · Update password · **Two-factor authentication** (optional here, mandatory for admins) · Browser sessions with a *log out other sessions* action.

The email is the login and cannot be changed here. An admin changes it if the publisher writes in.

Plus: notification preferences (postback failures, withdrawal status, account status, monthly summary) and timezone, which drives every date shown in the panel.

---

## 15. Registration — a four-step wizard

> Prototype: `prototype/register.html`. Also `prototype/login.html` and `prototype/forgot-password.html`.

**This supersedes the single-form registration originally specified here.** Signup now creates the publisher **and their first app** in one flow, and the change is deliberate:

- A wizard that splits five fields across four screens is worse than one form. A wizard that collects an account, a traffic declaration, and a working placement is not — there is genuinely enough to warrant pacing.
- It removes the dead end where an approved publisher lands on an empty dashboard and has to guess what to do next.
- It puts the currency-rate example in front of them at the moment they set the rate, which is where the most common misconfiguration happens.

### The steps

| # | Step | Collects | Gate to advance |
|---|---|---|---|
| 1 | **Account** | name, email, password, country | valid email, password strength ≥ *fair*, country set |
| 2 | **Traffic** | property type, monthly actives, free-text audience description | all three, description ≥ 20 chars |
| 3 | **First app** | app name, URL, platform, currency name + rate | all set, rate > 0 |
| 4 | **Review** | summary of everything, two attestations | both checkboxes ticked |

**Step 2 is the one an admin actually reads.** The copy says so plainly — *"A person reads this before approving you. Specific answers get approved faster."* The 20-character minimum on the description exists because "gaming site" is not a reviewable answer.

**Step 4 requires two separate attestations**, not one combined checkbox: the publisher agreement, and a specific confirmation that traffic is incentivised and not bot, proxy, or purchased. Bundling them into one tick makes the traffic promise unenforceable in any dispute.

### The right-hand panel is a live preview, not a testimonial

On steps 1–2 it shows the deal terms — 70%, monthly clear, $20, 17 fraud rules. **From step 3 it becomes the applicant's own offerwall**, re-rendering as they type: their app name in the header, their currency name, and every reward recalculated at their rate (`payout × 70% × rate`). Change the rate from 1000 to 500 and every figure halves in front of them.

That is what justifies the split-screen layout. A stock photo or a customer quote in that space would be decoration; this is the step-3 form field rendered as its own consequence.

### On completion

`user` + `publisher` created at `status = pending`, email verification sent, admins notified. The final screen shows the **App ID and secret key with copy buttons** — the secret is displayed once and the screen says so — plus a plain statement that the account is pending review, that they can configure and fire a test conversion now, and that the wall serves live offers only after a human approves.

No invite codes, no phone verification, no gate before the dashboard. The gate is on earning, not on exploring.

## 15b. Sign-in and password reset

**Sign in** (`login.html`) is one component with two stages: credentials, then the two-factor challenge. 2FA is one state of signing in rather than its own route — a separate page would need a half-authenticated session to protect, which is a worse thing to own than a state variable.

**Password reset** (`forgot-password.html`) never confirms whether an address exists. The success copy is *"If an account exists for this address, a reset link is on its way."* A message that says "no account with that email" is an account-enumeration oracle, and this is a platform where knowing which emails are publishers has direct value to an attacker.

Its panel states the security property that matters to someone who did not request the reset: **a reset link cannot change a payout destination.** Payment details require separate confirmation and every change is written to `audit_logs`.

### Not built in the prototype

`reset-password.html` (the landing page for the emailed token), email verification confirmation, and the 2FA recovery-code flow. All three are standard Fortify views with no design decisions left in them.

---

## 16. Tests

- Publisher A cannot read publisher B's apps, conversions, clicks, postbacks, ledger, or withdrawals — asserted at the policy and query level, not by checking rendered HTML.
- A `pending` publisher can create an app; the wall serves nothing for it.
- A `suspended` publisher cannot request a withdrawal.
- Currency example math renders correctly for several rates and rev-shares.
- Test postback fires a real request against a faked HTTP client and shows the status.
- Blacklist save flushes the correct cache tag and no other publisher's.
- The Offers inventory page shows the publisher's post-rev-share payout, **never** the network payout, and never a margin figure — asserted against the rendered payload, not the view.
- Blocking from the Offers page removes the offer from that app's wall and leaves it on the publisher's other apps.
- Postback re-send is rate-limited.
- Payment detail edit does not mutate an existing withdrawal's snapshot.
- `EligibleOffers` is empty for a pending publisher, a non-active app, and `query(null)` when the publisher has no Active app.
- The Offers page does not default `appId` to a pending app.
