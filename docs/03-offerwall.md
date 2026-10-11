# 03 — Offerwall

The hosted wall. The only surface an end user ever sees, and the only one under real traffic load.

**Non-negotiable properties:** stateless · no Filament · no session · cache-first · fast on a mid-range Android over 3G.

---

## 1. Integration Contract

The publisher embeds one iframe:

```html
<iframe
  src="{APP_URL}/wall?appid=10042&userid=USER_123"
  width="100%"
  height="800"
  frameborder="0"
  allow="clipboard-write"
></iframe>
```

| Parameter | Required | Notes |
|---|---|---|
| `appid` | yes | Public app ID from the dashboard |
| `userid` | yes | Publisher's own opaque user identifier. Echoed back in every postback. Max 191 chars. |
| `sig` | conditional | Required when the app has `require_signed_wall` enabled. `sha256(appid + userid + secret_key)`. |
| `country` | no | ISO-2 override for testing. Ignored unless the app is in test mode. |
| `theme` | no | `light` \| `dark`. Defaults to the app's configured theme. |
| `lang` | no | ISO-639-1. v1 ships `en` and `ar`. |
| `balance` | no | The end user's balance in the app's own currency, shown in the topbar. A string, passed through as sent; integer strings are grouped (`21000` → `21,000`). Carried across tab and app-nav switches like `lang`. |

Embeds point at `playndgain.com/wall`. The site's nginx block serves `/wall` from its own location that clears `X-Frame-Options` and sends `frame-ancestors *`; every other path on the host stays `SAMEORIGIN`. The wall has no session cookie, so an embed can reach nothing of the panels. `wall.playndgain.com` still serves the same routes for embeds handed out before the move. `PLAYNDGAIN_WALL_URL` puts embeds back on a separate host if the wall ever needs its own box.

---

## 2. Render Pipeline

`GET /` on the wall subdomain → `WallController@show`.

```
1.  Resolve app by public app_id            → 404 if missing
2.  Assert app.status = active
    and publisher.status = approved         → "Unavailable" page if not
3.  Verify sig when require_signed_wall     → 403 if invalid
4.  Validate userid (length, charset)       → 400 if malformed
5.  Resolve country from IP (GeoIP2)
6.  Resolve device/os/browser from UA
7.  Consent gate if country ∈ consent list
    and no stored consent                   → render consent screen, stop
8.  Check banned_end_users                  → render "unavailable" wall, stop
9.  IP protection gate (docs/05 §3)         → 403 with an actionable notice, stop
10. Fetch eligible offers (cached)
11. Subtract offers this user already completed
12. Sort, limit to settings.wall_offers_limit
13. Render
```

Steps 1–9 are cheap and must stay cheap. Step 10 is the only one allowed to touch the offers table, and normally it does not — it hits Redis.

**Step 9 sits after the consent gate and the banned-user check, not before them.** The rules it runs read the same `IpVerdict` that step 5 already resolved, but a granted consent is what allows that verdict to be escalated to a paid provider in a consent-required country (docs/05 §4), and a block written ahead of step 7 would record that IP, and the end user ID, in a `fraud_event` for a user whose consent decision has not been resolved yet — exactly the processing the consent gate exists to prevent. A user with no consent decision belongs on the consent screen; protection resumes the moment they answer it. A block writes one `fraud_event` per app + end user + IP + rule per hour, deduplicated so a blocked user reloading the page does not bury the audit row under copies of itself.

### The History tab

`GET /wall/history` — the end user's own clicks and what each one earned.

Steps 1–8 run **in full**, and step 7 is the reason: the history of a user is exactly the data a declined consent refuses, so it must not be reachable by deep link either. `Cache-Control: private, no-store` is not optional on a page that is per end user by definition.

```
  9.  This user's click rows, newest 50, paired with their conversions
  10. Render
```

The list is `offer_clicks` for `(app_id, end_user_id)`, `is_test = 0`, **`offer_id IS NOT NULL`**. That last filter excludes the partner-wall embed row: it is one row per `(app, partner, end user)` reused on every later open, so its `created_at` is the first open and never moves — rendered here it would be a row with no offer name and a timestamp that lies. A partner conversion landing against it is still real money, and it is visible where money is: the publisher's reports.

Conversions are paired with one grouped query keyed in PHP, deliberately **not** `OfferClick::conversion()`'s `hasOne`: a multi-reward offer pays per goal, so one click legitimately carries several conversion rows and `hasOne` would show the first one loaded and hide the rest of the money. A row shows the **sum** of what cleared under the status of the **newest** goal.

| Conversion | Label |
|---|---|
| none | **Started** |
| `pending` | **Pending** |
| `approved` | **Credited**, with `currency_amount` as a `.num` figure |
| `rejected` | **Rejected**, with `rejected_reason` when one is stored |
| `reversed` | **Reversed** |

**Statuses are text, not colour.** Green and red mean money direction and nothing else on any PLAYNDGAIN surface (design.md §2); a list of green "Credited" pills would spend the one signal the product reserves. The credited *amount* is the green thing, because it is money.

### The Offerwall tab

The wall's header carries a tab strip: **Offers**, which is everything above, **History**, and **Offerwall**, which frames a partner's own hosted wall. Under 640 px the strip hands over to the bottom app-nav — same three links, one navigation per breakpoint, never both. All three tabs always render. With no qualifying partner, Offerwall names the empty the way History does when the user has not started anything.

A partner qualifies when `networks.status` is active and `networks.wall_url` holds an `https://` template. `GET /wall/partners` is a catalogue of partner cards, or names the empty when none qualify. `?open=1` frames the highest-priority one; `GET /wall/partners/{network}?open=1` frames a named one and 404s if that network does not qualify. Visiting the catalogue writes no embed click — opening does. Both run steps 1–8 above unchanged, and step 7 is the reason they do rather than taking a shortened gate: an embedded third-party wall sets third-party cookies, which is exactly the processing the consent gate exists to prevent. A European user who declined does not reach a partner frame, including by deep link.

Then:

```
  9.  Resolve the partner — active, https wall URL     → empty (index) / 404 (named)
  10. Catalogue of cards, until `?open=1`
  11. Pre-click fraud, and the embed click row
  12. Blocked                                          → notice
  13. Substitute macros, render the frame
```

**The embed click row** is an `offer_clicks` row with a null `offer_id`, one per `(app, partner, end user)`, reused on every later open. Its `click_id` is the sub-id we hand the partner, and it is what their postback resolves back to — `ConversionProcessor` attributes a conversion entirely off a click, so without it there is nothing to credit. The row is written even when the open is blocked, for the same reason a blocked offer click is: a refusal with no row is a support question nobody can answer.

Macros in `wall_url`: `{sub_id}`, `{user_id}`, `{app_id}`, `{country}`, `{device}`, `{lang}`. Every substituted value is URL-encoded — `user_id` is publisher input landing in a URL — and a macro we do not know is left literal rather than blanked, because a silently emptied `{clickid}` is an integration that fails at payout time instead of at setup time.

### Eligibility filter

An offer reaches the wall only if **all** hold:

| Check | Rule |
|---|---|
| Placed at all | The country resolved from the IP **and** the device resolved from the UA. Neither one → **empty wall**, not the worldwide inventory |
| Status | `offers.status = active` and its network is `active` |
| Country | `countries` is empty (worldwide) **or** contains the resolved country |
| Device | `devices` is empty **or** contains the resolved device type |
| OS version | `os_min_version` is null **or** the detected version is ≥ it |
| Publisher blacklist | Not blocked by offer, country, device, or network for this app |
| App country block | Resolved country ∉ `apps.blocked_countries` |
| Payout floor | `payout ≥ 0.01` — sub-cent offers produce rounding noise and support tickets |
| Already completed | No approved conversion for `(end_user_id, offer_id)` unless `is_multi_reward` |

**The first check is fail-closed on purpose and it is the strict one.** An address GeoLite2 cannot place — a VPN exit, a carrier range it does not know, a box with no database on disk — gets the "we could not confirm your device" screen and no offers, rather than the worldwide inventory it used to get. Serving an offer to a user whose eligibility was never established is what a network charges back against; an empty wall costs one session, a wall of impossible offers costs the conversions *and* the relationship with the network. A worldwide offer is still served to a user we *did* place — "open to every country" includes theirs.

The same rule is re-checked on `/wall/offer/{offer}`, which takes an id out of a URL the end user can read off their own iframe. It is deliberately **not** re-checked at click time: a click that arrives for the wrong geo is a fraud signal with a score and an event (`geo_mismatch`), not a flat refusal.

The one way to be shown another country's inventory is `?country=XX`, and it is honoured only while `apps.test_mode` is on — off by default, and the reason the demo seeder works at all.

The last check is the only per-user query in the path:

```php
$completed = Conversion::query()
    ->where('app_id', $app->id)
    ->where('end_user_id', $userId)
    ->whereIn('status', ['pending', 'approved'])
    ->pluck('offer_id')
    ->all();
```

Indexed by `(end_user_id, offer_id)`. Cached per user for 60 seconds — short, because a user who just converted expects the offer to disappear when they come back.

### Popularity

The figure beside the device icons on a card — how many people started this offer. It answers the one question a card cannot otherwise answer: is anybody actually doing this.

```sql
SELECT offer_id, COUNT(DISTINCT end_user_id)
FROM offer_clicks
WHERE offer_id IN (…payload ids…)
  AND blocked = 0 AND is_test = 0
  AND created_at >= NOW() - INTERVAL 30 DAY
GROUP BY offer_id
```

**Distinct end users, not rows** — the glyph is a person, and raw rows are a number a bored user can inflate by themselves. **`blocked = 0`** — a fraud refusal is not social proof, and counting blocked clicks makes the offers that attract fraud look like the popular ones. **`is_test = 0`** — the publisher's own integration testing is not a user. **30 days** — a lifetime count freezes, so an offer that ran hot last year outranks the one converting today, permanently, and no new offer can ever catch up.

Computed **inside the cache build**, so it rides the `app:country:device` payload and its 300-second TTL: one extra query per build, none per render. It uses the FK index on `offer_id` (`EXPLAIN`: `type: range`, `key: offer_clicks_offer_id_foreign`), so no covering index was added.

Cross-app on purpose: it is a property of the offer, not of one publisher's traffic, and a per-app count would read "0" on every card of a new app's wall — the exact moment the number is least helpful. Rendered as a glyph plus a `.num` figure and **omitted entirely at zero**; "0 people" is worse than silence on a card the wall wants clicked.

The detail view does not read the cached payload, so it runs the same count for its one offer id.

### Sorting

Default order, applied at cache-build time:

1. Featured offers (admin-pinned) first
2. Then by `payout DESC`
3. Ties broken by `network.priority DESC`

The end user reorders it with a `<select>` in the header, submitted as `?sort=`:

| Value | Order |
|---|---|
| *(absent)* | the default above |
| `reward` | `payout DESC` |
| `trending` | popularity `DESC`, payout as the tiebreak |

Applied in PHP over the cached payload, before the per-user filter and the `wall_offers_limit` cap. **Not in SQL** — that would key the cache by sort order and triple the number of payloads for no gain on a list already capped. An unknown value falls back to the default rather than erroring; the parameter is in a URL the end user can edit.

**Server-side, as a form submit — not a client-side reorder.** The wall's rank/reveal state is a filter, and a filter may hide DOM nodes; a *sort* implemented with CSS `order` or a shuffled Alpine index makes visual order diverge from DOM and focus order, which is a keyboard bug design.md §7 does not permit. The `<select>` sits in a `<form method="get">` carrying the wall's own parameters as hidden inputs, with a submit button JS hides once it can submit on `change`. With JS off it is a select and a button, and it works.

The test offer keeps its pin: it is unshifted after sorting.

Publishers still cannot reorder the wall they serve. Noted as a v2 request, not built.

### Display payout

The user sees currency, never USD:

```php
$currencyAmount = round($offer->payout * ($publisher->rev_share / 100) * $app->currency_rate, 2);
```

So a `$0.50` offer, 70% rev-share, `1000 coins = $1` → **350 coins**. The USD figure never appears on the wall.

The printed label degrades with magnitude — exact under 1,000, whole numbers above it, millions abbreviated (`1.49M`) — because a real catalogue carries $2,000 payouts, and a seven-digit reward printed in full flattens the column the user is scanning. The exact figure stays on `reward` for anything that needs it.

---

## 3. Click Handling

`GET /click/{offerId}?appid=&userid=&sig=`

```php
public function click(Request $request, Offer $offer): RedirectResponse
{
    $app  = $this->resolveApp($request);
    $ctx  = ClickContext::fromRequest($request, $app, $offer);

    $clickId = (string) Str::uuid();

    $decision = app(FraudPipeline::class)->evaluate(Stage::PreClick, $ctx);

    $click = OfferClick::create([
        'click_id'       => $clickId,
        'app_id'         => $app->id,
        'publisher_id'   => $app->publisher_id,
        'offer_id'       => $offer->id,
        'network_id'     => $offer->network_id,
        'end_user_id'    => $ctx->endUserId,
        'ip'             => $app->anonymize_ip ? IpAnonymizer::mask($ctx->ip) : $ctx->ip,
        'country'        => $ctx->country,
        'user_agent'     => $ctx->userAgent,
        'device_type'    => $ctx->device,
        'os'             => $ctx->os,
        'browser'        => $ctx->browser,
        'referrer'       => $ctx->referrer,
        'risk_score'     => $decision->score,
        'blocked'        => $decision->blocked,
        'blocked_reason' => $decision->reason,
    ]);

    if ($decision->blocked) {
        return redirect()->route('wall.blocked', ['reason' => $decision->publicReason()]);
    }

    $driver = app(NetworkDriverManager::class)->make($offer->network);

    return redirect()->away($driver->buildTrackingUrl($offer, $clickId), 302);
}
```

**The click row is written even when blocked.** A blocked click that produced no row is a support ticket nobody can answer.

### Why 302 and not 301

301 is cached by the browser permanently. The tracking URL contains a single-use `click_id`. A cached 301 would send the user's second click to the first click's tracking URL and the conversion would attach to the wrong row.

---

## 4. Consent Gate

Triggered when the resolved country is in `settings.consent_required_countries` (EU-27 + UK + EEA) and no `consents` row exists for `(app_id, end_user_id)`.

The screen is plain, not a dark pattern: what data is collected (IP, country, device type, the publisher's user ID), why (fraud prevention and reward attribution), who receives it (the partner network behind the offer the user chooses), and two equally-weighted buttons.

- **Accept** → write `consents` with `granted=true`, `purposes`, IP, country, `user_agent_hash`. Render the wall.
- **Decline** → write `consents` with `granted=false`. Render an explanatory screen. **No offers are shown.** No click is possible.

Consent is stored per `(app, end_user)`, not per session, so it survives the iframe reloading.

**Data controls also required:**

- `GET /privacy/data?appid=&userid=&sig=` — returns what we hold on that end user, JSON.
- `POST /privacy/delete` — signed by the app secret. Anonymizes clicks and conversions for that end user (IP nulled, `end_user_id` replaced with a hash), **retains the money rows**. Financial records are not deletable; that is a legitimate-interest retention basis and it is stated in the privacy policy.

We are **not** an IAB TCF vendor. If a publisher sends a TC string we log it and pass it to any network that accepts one, but we do not claim TCF compliance anywhere in the marketing site or docs. Claiming it without registration is worse than not having it.

---

## 5. Frontend

**Stack:** Blade + Alpine.js + Tailwind, with Motion's WAAPI-only `motion/mini` for animation. No Alpine plugins. No React, no build-time hydration, no client-side router. The wall is a list of cards; anything heavier is unjustified weight on a phone. GSAP stays out of this bundle and `tests/Feature/Landing/BundleTest.php` enforces it — 34 KB gzipped of animation engine is the whole budget twice over, against roughly 3 KB for the mini build.

All wall behaviour lives in `resources/js/wall.js` as one `Alpine.data('wall')` component rather than in `x-data` attributes. Two reasons: the markup stays readable, and the rendered HTML carries no Alpine magic — `$el`, `$refs`, `$event` — which the wall's own test forbids, because a `$` in the page is indistinguishable from a USD payout leaking into a currency-only surface.

**Budget:** < 50 KB CSS+JS gzipped, first contentful paint under 1.5 s on a throttled 3G profile. This is a hard budget, checked in CI with a Lighthouse run against a seeded wall.

### Layout

- Mobile-first single column; two columns ≥ 640 px, capped at 60 rem. **Never three.** A card carries an icon, a name, a two-line instruction and a reward figure; at a third of a wide frame the instruction clips after four words and the figure collides with the name.
- One card component, two containers: `wall/partials/card.blade.php` is rendered by both the grid and the featured rail. A second card template for the rail drifts — the first change to the reward column lands in one of them and not the other.
- Card: icon (lazy, fixed aspect box to prevent layout shift), name, instruction clamped to two lines, a facts row, tags, reward. Two lines rather than one because network instructions are sentences — "reach level 15 within 60 days" clipped after four words is a missing instruction, not a shorter one — and a clamp still gives every card the same height.
- The reward is the loudest element on the card and a **ledger column**: a plain figure in fixed tabular figures — no pill, no fill — with the currency name in small caps beside it, flush to the card's inline-end edge, so payouts down a column end on one axis and comparing them is a glance rather than a read. It is what the user is scanning for. A multi-goal offer prefixes it with *Up to*, because the figure is the sum and the breakdown is a tap away. Beside it sits the **go-button**: an ink-filled disc carrying a chevron, the one filled element on the card, so the action reads as the action. It is `aria-hidden` — the whole card is already the link, and a button inside a link is two tab stops for one destination.
- **The facts row** (`wall/partials/facts.blade.php`, shared by the card and the detail screen) carries device glyphs and the popularity count — the two facts a card could not otherwise answer. Both are muted and small: nothing on a card may compete with the reward pill. Devices are whitelisted against the glyphs that exist, for the reason the detail view filters country codes — `offers.devices` is a JSON column fed by nine catalogues and by an admin typing into a tag field, and an unknown value becomes a `<use>` pointing at nothing. Glyphs come from `partials/icon-sprite.blade.php`, included once by the wall layout, so a hundred cards cost a forty-byte `<use>` each rather than a hundred copies of the path.
- **The featured rail** sits above the grid: `is_featured` offers in a horizontal strip, `overflow-x: auto`, `scroll-snap-type: x mandatory`, one card per snap point. **No JS** — no arrows, no autoplay, no timer, no state. It is a scroll region the browser already operates with a wheel, a swipe, a trackpad and the arrow keys; design.md §9's "no carousels" is about the marketing site's rotating banner, and a strip with nothing to advance is not that. Absent below two featured offers, because a rail of one is a card with a scrollbar; hidden while the search, a chip, or the device filter is on, because a strip of "featured" cards above a filtered grid ignoring the query reads as a bug. With JS off there is no filter, so it is simply always there. The test offer is excluded — it is `is_featured` so it sorts first, and it is already pinned above the grid.
- Featured offers carry the tag *and* an ink rail. The rail alone would be one visual channel; the tag is words.
- Sticky header, two rows. The **topbar**: the platform lockup, the user's balance as a neutral pill when the publisher passes `balance`, and the menu trigger. Then the tab strip. Below them: category chips. The All Offers toolbar carries search, a client-side device filter (Android / iOS / Desktop, plus any-device inventory), and the sort control. The sort is a native `<select>` restyled but never replaced — a custom listbox is a focus trap, a keyboard model and a mobile picker to rebuild, and the browser ships all three. The filtered count appears only while a filter is on. Search matches every word against the name, the instructions, the categories, and the device list.
- **The menu sheet** hangs off the topbar trigger: a native `<dialog>`, the same contract as the offer sheet (focus trap, Esc, backdrop — free). It holds only destinations that exist: the appearance toggle, which rides the `?theme=` per-load override and keeps every other wall parameter on the URL, and the operator's privacy and terms pages, opened off the iframe. The trigger is `x-cloak`-gated — with JS off there is no sheet and no dead button — and the wiring is plain JS on the shell (`data-menu` / `data-close`), because the rendered HTML may carry no Alpine magic.
- **The mobile app-nav** replaces the tab strip under 640 px: Offers, History, Offerwall, and the menu trigger, fixed to the bottom edge with the main and footer padded clear of it. Plain anchors, so every destination works with JS off; only the trigger is enhancement-gated. One navigation per breakpoint — the header strip hides where the app-nav shows.
- Hover is depth only — the row's edge and surface lift a step and, on a pointer that can hover, the row rises 2 px, all on one curve. Every transition reads the site's motion tokens (`--motion-fast` 150 ms for interaction, `--motion-base` 260 ms for the sheets, `--motion-exit` 200 ms for anything leaving, `--ease-out`), so the wall and the site move as one system (design.md §5, §10). The accent is spent in six places and no more — the balance, the tile letter, the primary action and its glow, the active tab's tint, the featured rail's marker, the credited history amount — so a card never turns the publisher's colour under the pointer.
- The list reveals in windows of 24, widened by a **Show more button** carrying the count still behind it — not by a scroll trigger. An auto-loading list never ends, so the footer never arrives and a user looking for it scrolls forever. The cards are **all already in the DOM** — the window hides, it does not fetch. That is what keeps client-side search honest (it searches the whole wall, not the loaded part) and what keeps the no-JS render complete; with JS off nothing is hidden and the button that would widen it is the thing that disappears.
- The featured rail arrives first and the grid after it: a 20 px rise and fade over 640 ms, siblings 85 ms apart with the stagger capped at eight so a window of 24 finishes together. The detail sheet rises and scales in over 260 ms and leaves in 200 ms. Every animation goes through one `move()` helper that returns without animating under `prefers-reduced-motion`.
- Two empty states, and they are not the same message. Server-empty names the actual cause — "No offers available in your country right now" beats a shrug illustration. Filter-empty says nothing is missing, only narrowed, and offers the way back.
- The publisher owns the palette, so nothing in the stylesheet may depend on a colour they do not control: every surface, line and tint is a `color-mix` of the theme properties. **The chrome spends none of the accent.** Fills are `--wall-ink` on the background or the reverse — near-black on a light wall, near-white on a dark one, both derived from `text` and `background` — so the wall reads the same whatever the publisher picked, and the palette stays grayscale regardless of theme. The accent pair is still emitted — publishers have saved themes against it, and dropping a key is a contract change, not a redesign — but nothing the end user sees spends it. The design's personality is in structure, type and state, not hue.

### Offer detail — `GET /wall/offer/{offer}`

The card is a headline: a name, one line, one figure. Everything a user actually decides on lived nowhere, and that cost conversions on exactly the inventory worth the most — nobody starts a two-week game offer on the strength of "reach village level 10".

- **It is a page, not a client-side expansion.** The card links straight to it, so it works with JS off, in a new tab, and from a shared link. With JS on, `open()` intercepts a plain left click, fetches the same URL with `?fragment=1`, and injects it into a native `<dialog>`. Modified and middle clicks are left alone; a failed fetch falls through to the navigation.
- Native `<dialog>`: the focus trap, Esc and the backdrop are the three things a hand-rolled modal gets wrong. Bottom sheet under 600 px, centred card above it.
- **The fragment carries no Alpine directives.** Alpine does not walk a subtree that arrives after boot, so a directive there would silently do nothing on the path most users take. Everything interactive is on the shell.
- Contents: the same facts row the card carries — device glyphs and the popularity count, beside the same plain reward figure, so the screen the user landed on confirms the one they tapped — the steps from `offers.goals` with what each one pays, the network's instructions as a list rather than a paragraph, device/country/OS targeting, a static **Important** box, and one filled CTA to `wall.click` in a new tab.
- The steps render as a **milestone rail**: a vertical line with numbered nodes, the step name beside each and its reward at the inline end. A two-week game offer is a journey, and a rail of milestones reads as one in a way a stack of boxed rows never did. The CTA fills with ink, as the card's go-disc does — the two places an end user acts are the two filled elements on the wall.
- **The Important box** is four static localised lines: no VPN, same device you started on, first-time completions only, 24–48 hours to credit. Static because all four are true of every offer on the wall and each one is a real support ticket. Anything network-specific already renders above it, under "Before you start", from `offers.instructions`.
- **The CTA sticks** to the bottom edge, body scrolling under it. On a long multi-step offer it sits below eleven steps and a notes box, and a user who read the terms had to scroll all the way back down to act. Sticky and not fixed, and CSS and nothing else — the fragment arrives after Alpine has booted.
- Countries are **flags, not ISO codes** — `flag-icons`, copied to `public/flags` by `npm run flags`, eight of them and then a `+N` chip. "SE" is a puzzle to most of the people this page is for. The name behind each one is a CSS-only tooltip fed by `data-tip`, and the name itself comes from `intl` (`Locale::getDisplayRegion`) in the wall's own locale, so there is no 250-row table to translate and maintain. Without the extension the code is still shown, which is still true. An offer with no stored goals still gets one step — "Complete the offer" — because it has exactly one thing to do and saying so is information.
- Per-step rewards are converted from USD by the same `OfferFeed::convert()` the headline uses, so the steps add up to the total instead of being a second, differently-rounded answer.
- It refuses everything a click refuses **plus the targeting the list applied in SQL**, for the same reason: the offer id is in a URL the end user can read off the iframe, so a publisher's blacklist — and the country and device rules — have to hold here or they are advisory. A copied link would otherwise render another country's offer, with its terms and a live start button, to someone who can never complete it. It deliberately writes **no** `OfferClick` — reading the terms is not a click, and counting it as one would inflate every CTR on the platform.
- The goals are read from the offer row, never from the cached wall payload. That payload is per `app:country:device` and read on every render; only the goal *count* rides along, for the "4 steps" chip on the card.

### Theming

Per-app, from `apps.theme`:

```json
{
  "primary": "#FFB020",
  "background": "#0A0B14",
  "card": "#11131F",
  "text": "#F4F5FA",
  "radius": "14px",
  "dark": true
}
```

Those are also the defaults (`WallContext::DEFAULT_THEME`, the site's Ink & Amber since 2026-09-02); `?theme=light` paints the paper surfaces (`WallContext::LIGHT_SURFACES`) and `?theme=dark` paints the ink ones, both keeping the app's primary. Injected as CSS custom properties on `:root`. No per-app CSS files, no per-app builds. A live preview sits next to the form in the publisher panel. The radius is the publisher's on cards, tiles and inputs; controls inside a card take six-tenths of it, the sheets nearly twice, and chips, tabs, buttons and the balance are pills regardless.

One derived property ships with them: `--wall-on-primary`, black or white, whichever WCAG puts further from the publisher's own `primary` (`App\Support\Contrast::on`). CSS cannot compute it. The wall's own chrome is monochrome, so nothing the end user sees spends the pair — but the contract holds for any surface that does fill with the accent: a legible foreground, never a coin flip against a colour we do not choose. Worst case is a mid-grey primary at ~4.5:1.

### Accessibility

Real requirements, not decoration: every card is a keyboard-focusable link with a visible focus ring; icons carry `alt`; the reward is plain text, not a colour chip (it carries the currency name as text); the menu and both navigations are real landmarks with `aria-current` on the current page; contrast ≥ 4.5:1 is validated against the publisher's chosen theme colours and the form **warns when their palette fails**.

---

## 6. Caching & Load

| Layer | What | TTL |
|---|---|---|
| Redis | Eligible offer payload per `app:country:device` | 300 s |
| Redis | Per-user completed-offer ID list | 60 s |
| Redis | GeoIP lookup per IP | 3600 s |
| HTTP | `Cache-Control: private, no-store` on the wall HTML | — |
| CDN | Static assets and offer icons | 1 y, hashed filenames |

**Offer icons** are proxied and cached locally (`/img/offer/{id}`), never hotlinked. Networks rotate and delete their CDN assets without warning, and a wall of broken images reads as a dead product.

### Rate limits

| Route | Limit |
|---|---|
| `/` (wall) | 60/min per IP, 600/min per app |
| `/click/*` | 20/min per `(IP + end_user_id)` |
| `/privacy/*` | 10/min per IP |

Redis-backed. Exceeding a limit returns `429` with a plain retry message, and writes a `fraud_event` at `flag` level — sustained rate-limit hits are a fraud signal, not just a load signal.

---

## 7. Test Mode

Each app has a **Test mode** toggle in the publisher panel. While enabled:

- The `country` query parameter is honoured, so the publisher can preview any geo.
- A **Test Offer** appears at the top of the wall, payout `$0.01`.
- Clicking it fires a real end-to-end conversion through the full pipeline — fraud rules, ledger entry, outgoing postback, all of it.
- The resulting conversion is flagged `is_test`, excluded from earnings, excluded from reports.

This is the single feature that turns integration support from a conversation into a self-service action. It is not optional.

---

## 8. Tests

- An offer excluding `DE` never appears in a `DE` wall payload.
- A blacklisted offer/country/device is absent for that app but present for another.
- A banned end user gets the unavailable screen, and `/click` refuses even with a hand-crafted URL.
- A completed non-multi-reward offer disappears for that user and stays visible for a different user.
- Invalid `sig` on a signed app → 403.
- Consent-required country with no consent row → consent screen, and `/click` is refused.
- Blocked click still writes an `offer_clicks` row with `blocked=true` and a reason.
- Currency conversion math: `$0.50` × 70% × 1000 = `350`.
- Rate limit returns 429 and writes a `fraud_event`.
- History runs the same gate as `/wall`: unconsented, declined, banned and unservable all refuse.
- History excludes the null-`offer_id` embed row, and another user's or another app's clicks.
- A click with two approved conversions shows their sum; one with none shows "Started".
- Popularity counts people, not rows: two clicks by one user count once, and blocked, test and over-30-day rows count zero. A second render issues no query against `offer_clicks`.
- `?sort=reward` and `?sort=trending` reorder; an unknown value falls back to the default rather than erroring.
- The rail is absent under two featured offers.
- The tab strip and the app-nav always carry Offers, History and Offerwall. With no qualifying partner, `/wall/partners` is an empty page, not a 404. With partners, the tab is a card catalogue; `?open=1` frames the wall in our page and writes the embed click.
- The topbar shows `?balance=` grouped when it is an integer string and verbatim otherwise; no parameter, no pill.
- The menu's appearance toggle offers the opposite scheme and keeps `appid`, `userid` and `balance` on the URL.
- Both navigations mark the current page.

## 9. The SDK API

`/sdk/v1/wall`, `/sdk/v1/offers/{offer}`, `/sdk/v1/history` (GET) and `/sdk/v1/consent` (POST), inside the stateless `wall` group and under `throttle:wall`. Versioned in the path because a shipped app cannot be redeployed with the server.

- **One gate.** Steps 1–6 are `WallGate::app/isServable/context`; steps 7–9 are `WallGate::admit()`, which `WallController` and `HistoryController` call too. The order cannot drift between an iframe user and an SDK user.
- **No clicks here.** The SDK opens `click_url`, which is `/click/{offer}`. Every pre-click rule, the click row and the postback path are the iframe's.
- **Whitelist.** `SdkPayload` lists every field by hand. No upstream payout, no network identity, no template strings (`:placeholders` and plurals are resolved server-side).
- **User-Agent.** Each SDK sends the platform WebView UA plus `PlayndgainSDK/<v>`, so `DeviceDetector`, `ua_anomaly` and `emulator_device` see what the iframe would.
- **Device hint (`dh`).** `Build.*` on Android, `utsname` on iOS. Read by `emulator_device` *with* the User-Agent, at render and at click, because an SDK click opens in the system browser whose reduced UA has no build string. It can only add a match. Ignored without `sdk`. Best-effort evidence, not a control: a client that drops `dh` (or `sdk`) is exactly as visible as an iframe click with an edited UA, never less.
- **Envelope.** `{status, app, strings, notice?, data?}`. Status is one of `ok`, `consent_required`, `declined`, `banned`, `blocked`, `unavailable`, `unplaced`, `offer_gone`, `invalid`, `rate_limited`. The SDKs branch on `status`, never on the HTTP code.
- **Sub-ids.** `sub1`..`sub5` ride every wall link, land on `offer_clicks`, and resolve as `{sub1}`..`{sub5}` on the publisher postback. Not on the partner-wall embed row: it is reused per (app, partner, end user), so a per-open sub-id would be wrong, and those postbacks resolve the macros empty.
