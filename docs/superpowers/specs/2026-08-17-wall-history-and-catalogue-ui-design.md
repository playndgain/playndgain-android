# Wall history, popularity and catalogue UI

**Date:** 2026-08-17
**Status:** Implemented. `EXPLAIN` on the popularity aggregate (§3, §13) confirmed `type: range` on `offer_clicks_offer_id_foreign` — no covering index was needed. Two deviations from §10: the glyphs went into `partials/icon-sprite.blade.php` and the wall layout now `@include`s it (the wall did not use the sprite before), and the CTA gained an `.offer__cta` wrapper so the sticky block covers the button and its terms line together.
**Touches:** `app/Http/Controllers/Wall/`, `app/Services/Wall/OfferFeed.php`, `routes/web.php`, `resources/views/wall/`, `resources/css/wall.css`, `lang/{en,ar}/wall.php`, `docs/03-offerwall.md`

Follows [2026-08-17-wall-tabs-embedded-partners-design.md](2026-08-17-wall-tabs-embedded-partners-design.md), which built the tab strip this design adds a third tab to.

## 1. What this adds

| | Surface | State |
|---|---|---|
| **History tab** | The end user's own clicks and what each one earned | New route, new view |
| **Popularity count** | How many people started this offer — the figure beside the device icons | New field on the cached payload |
| **Featured rail** | The pinned offers as a scroll-snap strip above the grid | New markup, CSS only |
| **Sort** | Featured · Top reward · Trending | New query parameter |
| **Card facts** | Device icons and the popularity count on every card | New markup |
| **Detail screen** | Sticky start button, device icons, popularity count, an important-notes box | Changes to `wall/partials/offer` |

The reference for the shape is a competitor's hosted wall. What is being copied is **information architecture** — which facts a card carries, that history is a tab, that the detail view is a scrollable sheet over a pinned CTA. What is not being copied is anything visual: the wall's palette belongs to the publisher (`apps.theme`, docs/03 §5), and every surface in `wall.css` is a `color-mix` of five properties we do not choose.

## 2. What the reference has and we are not building

| Their control | Why not |
|---|---|
| **Payout Type** dropdown | No backing column. `offers` carries `conversion_type` for our own accounting, not a taxonomy a user chooses by, and the category chips already are the "what kind of offer" filter. |
| **Privacy tab** | `/privacy/data` is a signature-gated JSON endpoint for the publisher's integration, not a screen. A tab is a separate design and was cut here deliberately. |
| **A carousel** | The featured rail scrolls; it does not advance on a timer and has no arrows. See §5. |

## 3. Popularity — "23 people"

The figure on a card next to the device icons. It answers the one question a card cannot otherwise answer: is anybody actually doing this.

```sql
SELECT offer_id, COUNT(DISTINCT end_user_id)
FROM offer_clicks
WHERE offer_id IN (…payload ids…)
  AND blocked = 0 AND is_test = 0
  AND created_at >= NOW() - INTERVAL 30 DAY
GROUP BY offer_id
```

Four decisions in that query:

- **Distinct end users, not rows.** The glyph is a person. One user who opened an offer eleven times is one person who tried it, and raw rows are the number a bored user can inflate by themselves.
- **`blocked = 0`.** A fraud refusal is not social proof. Counting blocked clicks makes the offers that attract fraud look like the popular ones.
- **`is_test = 0`.** The publisher's own integration testing is not a user.
- **30 days.** Lifetime counts freeze — an offer that ran hot last year outranks the one converting today, permanently, and no new offer can ever catch up.

It is computed **inside `OfferFeed::build()`**, so it rides the existing `app:country:device` payload and its 300-second TTL: one extra query per cache build, none per render. Staleness of five minutes on a popularity figure is invisible.

Cross-app on purpose. It is a property of the offer, not of the publisher's traffic, and a per-app count would read "0" on every card of a new app's wall — the exact moment the number is least helpful.

`offer_clicks.offer_id` carries a foreign key, so MySQL already indexes it; the aggregate needs no new index. **This must be confirmed against `EXPLAIN` before the branch lands**, not assumed.

The detail view does not read the cached payload (docs/03 §5), so it runs the same count for one offer id.

Rendered as a glyph plus a `.num` figure, and **omitted entirely at zero**. "0 people" is worse than silence on a card the wall wants clicked.

## 4. Sorting

A `<select>` in the header, submitted as `?sort=`:

| Value | Order | |
|---|---|---|
| *(absent)* | featured → payout → network priority | Today's order, unchanged, and the default |
| `reward` | payout desc | |
| `trending` | popularity desc, payout desc as the tiebreak | |

Applied in PHP after the cache read, in `OfferFeed::for()`, over the same payload. Not in SQL: that would key the cache by sort order and triple the number of payloads for no gain on a list already capped at `wall_offers_limit`.

**Server-side, as a form submit — not a client-side reorder.** The wall's rank/reveal state is a filter, and a filter may hide DOM nodes; a *sort* implemented with CSS `order` or a shuffled Alpine index makes visual order diverge from DOM and focus order, which is a keyboard bug the a11y rules in design.md §7 do not permit. The `<select>` sits in a `<form method="get">` carrying the wall's own parameters as hidden inputs, with a submit button that JS hides once it can submit on `change`. With JS off it is a select and a button, and it works.

The test offer keeps its pin: `testOffer()` unshifts after sorting, exactly as it does today.

## 5. The featured rail

`is_featured` offers, above the grid, in a horizontal strip: `overflow-x: auto`, `scroll-snap-type: x mandatory`, one card per snap point, `scrollbar-width: thin`.

- **No JS.** No arrows, no autoplay, no timer. The rail is a scrolling region the browser already knows how to operate — with a wheel, a swipe, a trackpad and the arrow keys. design.md §9's "no carousels" is about the marketing site's rotating-banner pattern; a scroll region with no state is not that.
- The rail cards are the **same partial** as the grid cards, at a fixed inline size. One card component, two containers — a second card template drifts.
- The rail is **not** filtered by the search, the chips, or the device control. It hides entirely while any of them is active, on one `x-show` bound to state that already exists: a strip of "featured" cards sitting above a filtered grid, ignoring the query, reads as a bug. With JS off there is no filter, so the rail is simply always there.
- `dir="rtl"` is live (`ar` ships), so the rail is verified in RTL. Logical properties only — `padding-inline`, `scroll-padding-inline-start`.
- Absent when fewer than two offers are featured. A rail of one is a card with a scrollbar.

## 6. History — `GET /wall/history`

```
1–8.  The gate, unchanged           (app, status, sig, userid, geo, device, consent, ban)
9.    This user's click rows, newest 50, joined to their conversion
10.   Render
```

Steps 1–8 run **in full**, for the reason the partner routes do (docs/03 §2): the consent gate is one of them, and a user who declined must not reach their own click history by deep link. `Cache-Control: private, no-store` comes from `RendersWallPages` and is not optional on a page that is per end user by definition.

```php
OfferClick::query()
    ->withoutGlobalScopes()
    ->where('app_id', $app->id)
    ->where('end_user_id', $context->endUserId)
    ->whereNotNull('offer_id')      // §6.1
    ->where('is_test', false)
    ->with('offer:id,name,icon_url')
    ->latest('created_at')
    ->limit(50)
```

Then one grouped query for the conversions of those fifty click ids, keyed in PHP. Not the model's `conversion()` `hasOne`: a multi-reward offer pays **per goal**, so one click legitimately carries several conversion rows, and `hasOne` would silently show the first one it happened to load and hide the rest of the money.

### 6.1 Embed rows are not history

The partner-wall work on this branch writes an `offer_clicks` row with `offer_id = null`, **one per (app, partner, end user), reused on every later open**. Its `created_at` is the first open and never moves. Rendered in a history list it is a row with no offer name and a timestamp that lies. `whereNotNull('offer_id')` excludes it. A partner conversion that lands against that row is still real money, and it is visible where money is — the publisher's reports — not here.

### 6.2 What a row says

Icon, offer name, when, status, and the reward when there is one. Status comes from the joined conversion, `conversions.offer_click_id` → `offer_clicks.id`:

| Conversion | Label | |
|---|---|---|
| none | **Started** | Clicked, nothing back from the network yet |
| `pending` | **Pending** | Credited to the publisher, not yet cleared |
| `approved` | **Credited** | With `currency_amount` as a `.num` figure |
| `rejected` | **Rejected** | With `rejected_reason` when one is stored |
| `reversed` | **Reversed** | |

**Statuses are text, not colour.** Green and red mean money direction and nothing else on any AdVault surface (design.md §2), and a wall of green "Credited" pills would spend the one signal the product reserves. The credited *amount* is the green thing, because it is money.

One click, several conversions: the row shows the **sum** of what cleared and the status of the newest. Two separate rows can also name the same offer — a user may start a repeatable offer twice, and that is what its history should look like.

Empty state: named for the cause, like the wall's own two. Nothing tried yet is not an error.

## 7. The tab strip

`wall/partials/tabs.blade.php` always renders three tabs. A missing partner used to hide Offerwall, which made the strip jump. The page now names the empty the way History does:

```
[ Offers ]  [ History ]  [ Offerwall ]
```

`GET /wall/partners` with no qualifying partner is an empty page, not a 404. A named `/wall/partners/{network}` that does not qualify is still a 404.

## 8. The detail screen

`wall/partials/offer.blade.php`, which is both the standalone page and the fragment injected into the dialog. Four changes:

1. **Device icons and the popularity count** in the head, beside the reward pill — the same two facts the card carries, so the screen the user landed on confirms the one they tapped.
2. **Important notes**, a bordered box before the CTA, four static localised lines: no VPN, same device you started on, first-time completions only, 24–48 hours to credit. Static because all four are true of every offer on the wall and each one is a real support ticket. Anything network-specific already renders above it, under "Before you start", from `offers.instructions`.
3. **The start button sticks** to the bottom edge of the dialog, body scrolling under it. On a long multi-step offer the CTA is currently below eleven steps and a notes box; a user reading the terms has to scroll back down past everything to act.
4. Steps keep their numbered markers and right-aligned rewards — already the shape, unchanged.

**The fragment still carries no Alpine directives.** Alpine does not walk a subtree that arrives after boot, so a directive here does nothing on the path most users take. Stickiness is CSS. The notes box is markup. Nothing added here is behaviour.

## 9. Rules none of this may break

Carried from docs/03 §5 and design.md, and each one is a test:

- **Every page renders completely with JavaScript disabled.** The rail scrolls, the sort submits, history is server-rendered, the detail page is a page.
- **< 50 KB CSS+JS gzipped**, Lighthouse in CI. This design adds **no new JavaScript module and no dependency**: two directives bind to state `Alpine.data('wall')` already holds (the rail's `x-show`, the sort form's submit-on-change), and everything else is markup and CSS.
- **No `$` in the rendered HTML** — `WallTest` asserts it, because a `$` is indistinguishable from a USD payout leaking onto a currency-only surface.
- **`.num` on every figure**: popularity counts, rewards, dates.
- **Green and red are money direction only.**
- Every new string lands in **both** `lang/en/wall.php` and `lang/ar/wall.php`.

## 10. Files

| File | Change |
|---|---|
| `app/Services/Wall/OfferFeed.php` | popularity aggregate into the payload; sort applied after the cache read |
| `app/Http/Controllers/Wall/HistoryController.php` | **new** |
| `app/Http/Controllers/Wall/WallController.php` | pass `sort` through |
| `app/Http/Controllers/Wall/OfferDetailController.php` | popularity for one offer |
| `routes/web.php` | `wall.history`, inside the `wall` group and `throttle:wall` |
| `resources/views/wall/partials/card.blade.php` | **new** — the card, shared by grid and rail |
| `resources/views/wall/partials/facts.blade.php` | **new** — device icons + popularity, shared by card and detail |
| `resources/views/wall/history.blade.php` | **new** |
| `resources/views/wall/index.blade.php` | rail, sort control, card extracted |
| `resources/views/wall/partials/{tabs,offer}.blade.php` | §7, §8 |
| `resources/views/partials/icon-sprite.blade.php` | device and person glyphs |
| `resources/css/wall.css` | rail, facts row, sort, history list, sticky CTA |
| `lang/{en,ar}/wall.php` | history, sort, notes, device labels |
| `docs/03-offerwall.md` | §2 pipeline, §5 layout |

## 11. Tests

| | |
|---|---|
| History runs the gate | Unconsented, declined, banned and unservable all refuse, exactly as `/wall` does |
| History excludes embed rows | A null-`offer_id` row for the same user is absent from the page |
| History pairs conversions | A click with an approved conversion shows the reward; one without shows "Started"; a click with two approved conversions shows their sum |
| History is scoped | Another end user's clicks, and another app's, are absent |
| Popularity counts people | Two clicks by one user count once; blocked and test rows count zero |
| Popularity is cached | A second render issues no extra query |
| Sort | `?sort=reward` and `?sort=trending` reorder; an unknown value falls back to the default rather than erroring |
| Rail | Absent under two featured offers |
| Strip | Offers, History and Offerwall always render; `/wall/partners` names the empty when no partner qualifies |
| No regression | `WallTest`'s no-`$` assertion and `BundleTest`'s chunk graph stay green |

## 12. Documentation

`docs/03-offerwall.md` §2 gains the history route in the pipeline list and §5 gains the rail, the facts row, the sort control and the history tab. The spec file is the design record; docs/03 stays the description of what shipped.

## 13. Risks

- **The popularity aggregate is the only new query in a cached path.** If `EXPLAIN` shows it scanning rather than using the FK index on `offer_id`, it gets a covering index in the same branch, or it does not ship. A slow query inside a cache build stalls every wall render that misses.
- **Cross-app counts are a small disclosure**: a publisher can infer platform-wide interest in an offer from their own wall. Accepted — it is a rounded popularity figure on inventory every publisher can already see, not traffic or revenue.
- **Sort multiplies nothing but must not multiply the cache.** If a future change moves sorting into SQL, the cache key has to grow with it, and that is the bug to watch for.
