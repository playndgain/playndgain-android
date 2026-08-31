# Wall tabs and embedded partner walls

**Date:** 2026-08-17
**Status:** Approved design, not yet implemented
**Touches:** `app/Http/Controllers/Wall/`, `app/Services/Wall/`, `app/Services/Money/ConversionProcessor.php`, `app/Models/Network.php`, `resources/views/wall/`, `app/Filament/Admin/Resources/Networks/`, two migrations

## 1. What this adds

The hosted wall gains a tab strip in its header:

| Tab | Content | State |
|---|---|---|
| **Offers** | Today's wall, unchanged | Default |
| **Offerwall** | A partner's own hosted wall, embedded in a nested iframe | New |

Future tabs (video, surveys) slot into the same strip. **Surveys is explicitly out of scope** and was cut from this design — see §11.

The Offerwall tab is a second integration mode for a partner: instead of syncing their catalogue into `offers` and redirecting clicks to their tracking URL, we frame their wall and let them run it. They still postback to us, we still credit through our own ledger.

This is not roadmap phase 7 ("Surveys and in-house offers — requires direct advertiser relationships", `docs/12-roadmap.md`). That is our own inventory. This is someone else's wall inside ours.

## 2. Why a click row and not a token

`ConversionProcessor::record()` attributes a conversion entirely off an `OfferClick`: app, publisher, end user, country, IP, `is_test`. An embedded wall produces conversions with no click of ours, so something has to stand in.

Three candidates were considered:

| | Approach | Verdict |
|---|---|---|
| **A** | Write an `offer_clicks` row with `offer_id` null on tab open; its `click_id` is the sub-id we hand the partner | **Chosen** |
| B | Stateless `hmac(app_id + user_id)` sub-id, resolved at postback time | Rejected |
| C | New `embed_sessions` table | Rejected |

A costs one nullable-column migration and zero new code in the money path. B needs a whole second attribution branch inside `ConversionProcessor`, and loses IP, country, device and `is_test` at conversion time because nothing was recorded when the user opened the wall. C is `offer_clicks` under a different name plus the same parallel branch as B.

The money path is where a bug costs real dollars. The design that adds no new code there wins.

`conversions.offer_click_id` and `conversions.offer_id` are **already nullable** — an offer-less conversion is schema-legal today. Only `offer_clicks.offer_id` has to change.

## 3. Schema

```php
// Migration 1 — networks
$table->text('wall_url')->nullable();              // macro template, https only
$table->boolean('wall_new_tab')->default(false);   // partner refuses framing

// Migration 2 — offer_clicks
$table->unsignedBigInteger('offer_id')->nullable()->change();
```

The second migration is written against the raw column type rather than `foreignId()->change()`: the column carries a foreign key, and MySQL keeps it across a `MODIFY COLUMN` but not across a drop-and-recreate. The FK itself is untouched.

No new table, no pivot. A partner appears in the strip when `status = active` **and** `wall_url` is filled, ordered by `networks.priority` **descending** — the same direction `OfferFeed` and the admin table already read it.

`networks.driver` stays required and unique. An embed-only partner still needs a driver class — it is what verifies and parses their postback — but returns `false` from `syncsCatalogue()` and an empty iterable from `fetchOffers()`, exactly as `DirectDriver` already does. `buildTrackingUrl()` is never called on this path.

### Reading embed rows apart from clicks

`offer_id IS NULL` is the marker. The publisher click list and any admin view that joins offers needs a null-safe cell — an embed row shows the network name where an offer name would be.

## 4. The macro contract

`wall_url` is a template. Substitution is whitelist-driven:

| Macro | Value |
|---|---|
| `{sub_id}` | The embed click's UUID. This is the value the partner must echo in their postback. |
| `{user_id}` | `WallContext::$endUserId` — the publisher's own identifier |
| `{app_id}` | `apps.app_id`, the public one |
| `{country}` | Resolved two-letter country, or empty |
| `{device}` | `DeviceType` value |
| `{lang}` | `en` \| `ar` |

Rules, all three load-bearing:

- **Every substituted value is `urlencode`d.** `user_id` is publisher-controlled input landing in a URL.
- **Unknown macros are left literal**, not blanked. A silently emptied `{clickid}` is an integration that fails at payout time instead of at setup time.
- **`wall_url` must parse as `https://`.** Validated in the admin form and again before substitution.

## 5. Routes

```
GET /wall                       Offers  (default, unchanged)
GET /wall/partners              Strip + highest-priority partner
GET /wall/partners/{network}    That partner
```

Both new routes join the existing `wall` middleware group and `throttle:wall`. Server-rendered with their own URLs rather than an Alpine pane swap, matching how `/wall/offer/{offer}` already works: the back button behaves, the page survives JS being off, and a publisher can deep-link a partner.

Every tab link carries `$context->params()`. Without it an app with `require_signed_wall` 403s the moment a user switches tab.

`{network}` binds on `id`; a network that is not active or has no `wall_url` 404s rather than rendering an empty frame. `/wall/partners` 404s by the same rule when no partner qualifies at all — the tab that leads there is hidden, so arriving is a hand-typed URL.

## 6. Render pipeline for a partner page

Extends `docs/03-offerwall.md` §2. Steps 1–8 are the existing `WallGate` sequence, unchanged and in the same order:

1. Resolve app by public `app_id` → 404
2. App active **and** publisher approved → unavailable page
3. Verify `sig` when `require_signed_wall` → 403
4. Validate `userid` → 400
5. Country from IP · 6. Device from UA
7. **Consent gate** · 8. Banned end user
9. Resolve partner: active, `wall_url` filled → 404
10. Pre-click fraud pipeline, then `firstOrCreate` the embed click row carrying its verdict
11. Blocked → notice, no frame
12. Substitute macros, render the frame

The row is written before the block is acted on, not after, and it is written **even when the open is blocked** — `ClickController` is explicit about why: "a blocked click with no row is a support question nobody can answer."

Step 7 is not optional here and is the reason the partner pages run the full gate rather than a shortened one: an embedded third-party wall sets third-party cookies, which is precisely the processing the consent gate exists to prevent. A European user who declined must not reach a partner frame, including by deep link.

### The embed click row

Mirrors `ClickController::record()` minus the offer:

- `offer_id` null, `network_id` the partner
- `FraudStage::PreClick` through `FraudPipeline`, with `FraudContext::$offer` null (already nullable)
- IP masked when `apps.anonymize_ip`
- `is_test` = `testMode && countryOverridden`. There is no test offer on this path, but the country-override reasoning from `ClickController` applies unchanged: an app left in test mode would otherwise let anyone earn real money from a country the targeting never allowed.

**One row per `(app_id, network_id, end_user_id, is_test)`, created once and never mutated.** A reopened tab in the same mode reuses it. Three reasons: `offer_clicks` stays append-only, the partner gets a stable sub-id across sessions, and the hot table does not gain a row every time a user taps between tabs. The block decision is re-evaluated live on each open and gates the render without rewriting the row — so the stored `risk_score` and `blocked` flag are the ones from first contact, which is what an append-only log means.

`is_test` is in the uniqueness tuple and not merely in the values written on create, and it is the one first-contact value that could not be. `risk_score` and `blocked` are safe to freeze because `ConversionProcessor` never reads them; `is_test` it copies straight onto the conversion, and that is what decides whether `LedgerEntry::createEarning()` runs. A row created live and reused after the publisher flips `test_mode` would keep writing real ledger entries for an overridden country — the exact chargeback the override rule above exists to prevent. A row created during a publisher's test under an `end_user_id` a real user later receives would silently stop that user earning, forever, with no error anywhere. Matching on the flag costs at most two rows per tuple and keeps the sub-id stable within each mode.

The one-row rule is best effort, not a constraint: no unique index can cover that tuple while real clicks share the table, so two simultaneous first opens race `firstOrCreate` into two rows. Harmless — attribution keys on whichever `click_id` the partner echoes back, and both point at the same app and end user.

## 7. The frame

```html
<iframe src="{substituted}"
        sandbox="allow-scripts allow-same-origin allow-popups allow-forms allow-top-navigation-by-user-activation"
        allow="clipboard-write"
        referrerpolicy="no-referrer"></iframe>
```

The same attribute set `resources/docs/offerwall-iframe.md` demands of our own publishers, for the same reason: partner offers end in store links, and a frame that cannot top-navigate converts at zero.

`wall_new_tab` covers a partner whose `frame-ancestors` refuses us — the page renders a button (`target="_blank" rel="noopener"`) instead of a grey box. It is set per partner at onboarding, when a human looks at the frame once.

Our own page keeps `Cache-Control: private, no-store` via `RendersWallPages`.

## 8. Crediting

```
open tab → gate → embed click (uuid)
  → iframe src = template, {sub_id} = uuid
  → user completes at partner
  → partner GET/POST /postback/{driver}     ← existing endpoint
  → IP allowlist + verifyPostback()          ← existing, unchanged
  → parsePostback() → clickId = echoed sub_id
  → ConversionProcessor::record()            ← unchanged
  → conversion (offer_id null) → LedgerEntry → publisher postback
```

Dedupe is the existing `(network_id, network_txid)` unique index. Reversals resolve by `network_txid` and need nothing new. Fraud rules that read the offer see null; `duplicate_conversion` and the rest key on the click and end user and are unaffected.

**One line changes in the money path.** `conversions.offer_name` is NOT NULL and a partner may send none:

```php
'offer_name' => $dto->offerName ?? (string) $offer?->name,     // now
'offer_name' => $dto->offerName ?? $offer?->name ?? $network->name,   // after
```

## 9. Presentation

Tab strip in `wall-header`, above the search field, present on every wall page. Partner sub-strip is a horizontally scrollable chip row reusing the `wall-chips` geometry — it has to survive more than five partners without wrapping into the content.

The strip is **hidden entirely** when no partner qualifies. A tab that leads to an empty page is worse than no tab.

New translation keys in `lang/en/wall.php` and `lang/ar/wall.php`:

```
wall.tabs.offers            Offers
wall.tabs.partners          Offerwall
wall.tabs.label             (the nav's accessible name — not "Offers", which is
                             also one of the tabs inside it)
wall.notice.partner_blocked (the blocked-open notice; partner-shaped, because
                             wall.notice.blocked's copy is about an offer)
```

The notice key is `wall.notice.partner_blocked` and not `wall.partner.blocked`: `RendersWallPages::notice()` builds its lookup as `wall.notice.{$key}`.

`wall.title` currently reads "Offers" and serves as both `<title>` and `<h1>`. With a tab strip the two diverge — the document title stays the wall's name, the `<h1>` becomes the active tab. The skeleton loader in `wall/layout.blade.php` mimics the offers grid; the partner page renders it without the card skeleton, since what loads there is one frame, not a grid.

## 10. Admin

A new "Embedded wall" section on the existing `NetworkResource` form: `wall_url` with the macro table as helper text, `wall_new_tab` toggle. A table column marks which networks are embeddable. No new resource, no publisher-facing screen — partners are global and admin-controlled, identical for every app.

## 11. Out of scope

- **Surveys tab.** Cut during design. When it returns it is either a `ConversionType::Survey` filter over our own offers or a second `wall_url` group — the tab strip is built to take a third entry either way.
- **Video tab.** Same.
- **Per-app partner selection.** Partners are global. A pivot table is the change if that stops being true.
- **Publisher-supplied partner URLs.** Money would bypass our ledger.
- **Height messaging from the partner frame.** We do not post height to our parent either; a fixed viewport-relative height is the documented answer.

## 12. Tests

Pest feature tests, all under `tests/Feature/Wall/`:

- Tab strip absent when no partner has a `wall_url`
- `/wall/partners` renders the highest-priority partner
- Tab links carry `appid`, `userid`, `sig`, `lang` — an app with `require_signed_wall` switches tabs without a 403
- Consent-required country hits the consent screen before any partner frame, including on a direct `/wall/partners/{network}` hit
- Banned end user gets the notice, no frame
- Inactive partner, one with no `wall_url`, and `/wall/partners` with nothing qualifying — all 404
- The publisher postback fires for an embed conversion and every macro resolves to a sane value. `MacroResolver` and `config/postback-macros.php` are unread at design time; if any publisher-facing macro reaches through `$conversion->offer`, an embed conversion is the first one where that is null. Check it before writing the plan.
- Macro substitution: values URL-encoded, unknown macros left literal, non-https template refused
- Two opens by the same end user produce one `offer_clicks` row
- A postback carrying an embed `click_id` creates a conversion with null `offer_id` and writes the ledger entry
- The same postback twice writes one conversion
- Fraud-blocked open shows the notice and renders no frame

## 13. Documentation

- `docs/03-offerwall.md` §2 — the render pipeline gains steps 9–12. That document is canonical and cannot lag the code.
- `docs/01-domain-model.md` — `offer_clicks.offer_id` is now nullable and what a null means.
- `resources/docs/` — a publisher-facing page only if the tabs change what a publisher must do. They do not: same iframe, same two parameters.

## 14. Risks

| Risk | Handling |
|---|---|
| Partner blocks framing (`frame-ancestors`) | `wall_new_tab` per partner, verified at onboarding |
| Partitioned third-party cookies break the partner's session inside our frame | Same flag. Discovered per partner, not designed around. |
| Partner truncates our sub-id | Same failure mode as an existing network truncating a subid: the postback lands as `UnknownClick` and an admin attaches it by hand via `PostbackDTO::withClickId()` |
| Embed rows are counted as clicks by reporting and by `publisher_spike` | **Nothing excludes them, and nothing in this branch does.** `AggregateDailyStatsCommand` groups `offer_clicks` by `(publisher_id, app_id, network_id, country)` with no `offer_id` filter, so every embed open lands in `daily_stats.clicks` — the number the publisher dashboard renders. `PublisherSpikeRule` counts raw `offer_clicks` for its denominator, and the embed denominator is one row per user forever while the numerator is per conversion, so an embed-heavy publisher's computed rate can exceed 1.0 and drift upward, flagging real conversions into the review queue. Latent today: no seeded network has a `wall_url`, so no embed row exists until an admin enables a partner. **Deferred on purpose** — filtering the denominator without the numerator makes the rule strictly worse, and rebalancing a rule that holds real money needs its own spec. Decide before embed traffic runs at volume: exclude embed rows from both halves of `publisher_spike`, or accept the distortion and document the floor it puts under the flag rate. |
| The never-mutated row carries first-contact country forever | `record()` snapshots `country` off the click, and `conversion_geo_mismatch` compares it against the conversion IP — so a user who travels flags on every later embed conversion. Accepted: a stable sub-id is worth more than a per-session geo, and the rule scores rather than blocks. Revisit if embed conversions dominate the flag queue. Moving `country` or `ip` into the uniqueness tuple is the wrong fix — it fragments the row per address and destroys the stable sub-id this design exists for. |
| The row also freezes `publisher_id` | `record()` resolves the publisher from the click, and that decides who owns the ledger entry and whose `rev_share` applies. An app transferred to another publisher would keep paying the original one on every reused embed row. Unreachable today — nothing writes `apps.publisher_id` after creation — so this is a note against the day apps become transferable, not a defect to fix now. `is_test` was the same shape of bug and was real; this one is the same shape and is not, and the difference is only that no code path sets the field twice. |
| `impossible_speed` is inert on embed conversions | The rule measures `conversion.created_at − click.created_at`, and on a row reused across sessions that delta is days or months. It never fires after the first session. Recorded rather than fixed for the same reason as the `publisher_spike` row above: the fix is a rule change, not a wall change. See `docs/05-fraud-engine.md`. |
