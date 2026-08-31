# Adscend Media (`adscendmedia`)

Working notes for Adscend's catalogue, tracking links, hosted offer wall, and S2S postback. Driver: `app/Networks/Drivers/AdscendMediaDriver.php`. Fixture: `tests/Fixtures/Networks/adscendmedia-offers.json`. Seeder row: `adscendmedia` / [adscendmedia.com](https://adscendmedia.com).

Fetched 2026-08-18 from the public developer site. Every API sidebar page under [developer.adscendmedia.com/api/common/get-started.php](https://developer.adscendmedia.com/api/common/get-started.php) was opened, plus the Web / Android / iOS / Unity wall and postback pages. The only page they point at for the **full** postback macro list — **Variables and Info** — is login-gated (`/publishers/dashboard/postbacks/docs#p=variables`). Macros below that are not on a public page are marked as such.

The catalogue call and field mapping are already in the driver ("ported from a working Adscend integration"). The postback half follows their published macro *style* and **still needs a live fire**. They do not POST a fixed payload. You write the URL. Dropping a macro fails silently: the fire still arrives, we still answer 200, and the field is null forever. Two fields do not: without `sub1` or a numeric `payout`, `parsePostback()` returns null and the controller answers **422**.

---

## Sources

| Page | URL | What it is |
|---|---|---|
| Getting Started | [api/common/get-started.php](https://developer.adscendmedia.com/api/common/get-started.php) | Account approval, where credentials live |
| Choosing an API | [api/common/choosing-api.php](https://developer.adscendmedia.com/api/common/choosing-api.php) | Offers API vs Offer Wall API vs Market Research |
| Offers API — Basic Auth | [api/offers/v1/auth.php](https://developer.adscendmedia.com/api/offers/v1/auth.php) | HTTP Basic, `pubID` + `api_key` |
| Offers API — Get Offers | [api/offers/v1/respDetails.php](https://developer.adscendmedia.com/api/offers/v1/respDetails.php) | Endpoint, sample JSON, field legend |
| Product Type Filter | [api/offers/v1/productFilter.php](https://developer.adscendmedia.com/api/offers/v1/productFilter.php) | `product_id[]` |
| Target Platform Filter | [api/offers/v1/platformFilter.php](https://developer.adscendmedia.com/api/offers/v1/platformFilter.php) | `target_system[]` |
| Offer Type Filter | [api/offers/v1/offerTypeFilter.php](https://developer.adscendmedia.com/api/offers/v1/offerTypeFilter.php) | `allowed_traffic` |
| Category Filter | [api/offers/v1/categoryFilter.php](https://developer.adscendmedia.com/api/offers/v1/categoryFilter.php) | `category[]` / `category_id[]` |
| Country Filter | [api/offers/v1/countryFilter.php](https://developer.adscendmedia.com/api/offers/v1/countryFilter.php) | `countries[]` ISO-2 |
| Sorting | [api/offers/v1/sorting.php](https://developer.adscendmedia.com/api/offers/v1/sorting.php) | `order_by` + `order_direction` |
| Pagination | [api/offers/v1/limiting.php](https://developer.adscendmedia.com/api/offers/v1/limiting.php) | `page` + `per_page` |
| Minimum payout | [api/offers/v1/minPayout.php](https://developer.adscendmedia.com/api/offers/v1/minPayout.php) | `payout_min` |
| Offer Wall API v1.1 | [api/wall/v1.1/](https://developer.adscendmedia.com/api/wall/v1.1/get-offers.php) | Per-user catalogue. We do not call it. |
| Market Research API | [api/market-research/v1/setup.php](https://developer.adscendmedia.com/api/market-research/v1/setup.php) | Surveys. We do not call it. |
| Survey Q&A ids | [api/market-research/v1/profile-questions.php](https://developer.adscendmedia.com/api/market-research/v1/profile-questions.php) | Qualifier question / answer ids |
| Publisher API | [api/publisher/v1/get-blocked-list.php](https://developer.adscendmedia.com/api/publisher/v1/get-blocked-list.php) | Block / unblock wall users |
| Web wall iframe | [web/integrate-webView-adwall.php](https://developer.adscendmedia.com/web/integrate-webView-adwall.php) | Hosted iframe + `subid1`–`subid4` |
| Web postbacks | [web/postbacks.php](https://developer.adscendmedia.com/web/postbacks.php) | How to paste a URL; points at Variables |
| Rewarded-video macros | [unity/setup-rewarded-video.php](https://developer.adscendmedia.com/unity/setup-rewarded-video.php) | Public examples of `[SB1]`, `[IP]`, `[UTS]`/`[UTX]`, `[DVC]`, `[PRF]` |
| Publisher apply | [adscendmedia.com/publishers/apply](https://adscendmedia.com/publishers/apply) | 2–3 business day review |
| Offers API keys | [dashboard/offers/api](https://adscendmedia.com/publishers/dashboard/offers/api#p=php) | `pubID` + `api_key` |
| Offer Wall profiles | [dashboard/offer-wall](https://adscendmedia.com/publishers/dashboard/offer-wall) | `adwallID` + wall API key |
| Postback editor | [dashboard/postbacks](https://adscendmedia.com/publishers/dashboard/postbacks) | Global or offer-specific URL |
| Variables and Info | [dashboard/postbacks/docs#p=variables](https://adscendmedia.com/publishers/dashboard/postbacks/docs#p=variables) | **Login-gated.** Full macro list. |

Sidebar headings still print **Offers API v2 / v1.5 / v1** and **Market Research v2 / v1.5 / v1**. On the live site those version dropdowns are **disabled** and default to `v1`. Hitting `/api/offers/v2/auth.php` and `/api/offers/v1.5/auth.php` returns the same page as v1, and every code sample still calls `https://api.adscendmedia.com/v1/…`. Offer Wall defaults to **v1.1** (v1 pages still exist; v1.1 Get Offers is the richer one). Treat v1 / v1.1 as the contract.

---

## Which API we use

They sell three APIs. Only one is a catalogue.

| API | Needs a user id? | What it is | We |
|---|---|---|---|
| **Offers API** | no | Unfiltered JSON list of every offer they will pay us for | **`fetchOffers()`** |
| **Offer Wall API** | yes (`subid1`) | Per-user wall: hide completed, points balance, tickets | Do not call |
| **Market Research API** | yes | Surveys after a qualifier profile | Do not call |

Choosing-an-API is explicit: Offers API "isn't concerned with a specific user… there is no `subid1` URL parameter." That is why the driver hits Offers API and stamps `sub1={click_id}` onto `click_url` itself.

The hosted iframe is a **fourth** product (same postback). It is not the Offers API.

---

## Credentials

Stored on `networks.credentials` (encrypted). Labels come from `credentialSchema()`.

| Our key | Their name | Required | Where they show it |
|---|---|---|---|
| `pub_id` | Publisher ID / `pubID` / `pubId` | yes | Offers API page of the dashboard |
| `api_key` | API Key | yes | Same page. Basic-auth **password**. |
| `postback_key` | *(ours)* | no | Not an Adscend field. Shared token we put on the postback URL as `key=`. |

A wall profile has its **own** API key (`adwallID` + key under "API & SDK Details"). That key is for Offer Wall API / Market Research, not for `api.adscendmedia.com/v1/publisher/{pubId}/offers.json`. Do not paste it into `api_key`.

`verifyPostback()` is open when `postback_key` is blank. The only remaining guard is `networks.postback_ips`, which **rejects when empty**. Their firing addresses are **not on any public page**. Ask the AM and paste them before the network is switched on. The seeder leaves this row empty.

Approval of a new publisher account takes **2–3 business days**.

---

## Offers API (what we fetch)

Single GET. HTTP Basic: username = publisher id, password = API key. Browser-prompt testing works the same way.

```
GET https://api.adscendmedia.com/v1/publisher/{pub_id}/offers.json
Authorization: Basic base64({pub_id}:{api_key})
```

`fetchOffers()` uses a 180s timeout and does **not** send filters or pagination. We pull the unfiltered catalogue and filter after storing.

No documented rate limit. User-Agent we send: `AdVault/1.0`. Not documented as required.

### Status codes

| HTTP | Meaning |
|---|---|
| 200 | Body is `{ "offers": [ … ] }` |
| 204 | No offers for the given parameters (empty catalogue, not an auth failure) |
| 400 | Required parameter missing |
| 403 | API key does not match the `pubId` in the path |

`testConnection()` treats any non-2xx as a hard fail and counts `offers` on 200. A 204 would currently look like a failed connection (`failed()` is true for 204). Worth knowing if someone tests with a filter that matches nothing; the driver itself does not send filters.

### Optional query filters (we do not send)

All of these go on the same `/offers.json` URL.

| Query | Shape | Values |
|---|---|---|
| `product_id[]` | array | `4` = Virtual Rewards. Legend also names `8` = Rewarded Video on the offer row. |
| `target_system[]` | array | See [target systems](#target-systems) |
| `allowed_traffic` | string | `incent` or `nonincent` |
| `category[]` | array | Numeric ids. Example on the same page uses `category_id[]=17&category_id[]=20` — both spellings appear. |
| `countries[]` | array | ISO-3166-1 alpha-2 |
| `order_by` | string | `offer_id`, `name`, `payout`, `epc_network`, `conversion_rate_network`, `target_system`, `conversion_point`, `category`, `start_date` |
| `order_direction` | string | `asc` / `desc` (docs type this as Number; it is not) |
| `page` | number | Required if `per_page` is set |
| `per_page` | number | Required if `page` is set |
| `payout_min` | float | Inclusive minimum USD payout |

If a live catalogue ever exceeds what one unpaginated response can carry, follow `page` + `per_page`. Do not invent a cursor. There is no `next_page_url`.

### Envelope

```json
{
  "offers": [
    {
      "offer_id": 225,
      "name": "Proflowers US",
      "click_url": "https://asmclk.com/click.php?aff=32836&camp=225&prod=9",
      "payout": 5.65
    }
  ]
}
```

`rowsFrom` in the driver is `json('offers')`. A missing or non-array `offers` is an empty catalogue.

The Category Filter page sample is **wrong** — it reprints an Offer Wall `profile` object. Ignore it.

### Offer row (official legend + sample)

| Their field | Type | What they say | What we do |
|---|---|---|---|
| `offer_id` | int | Offer id | `OfferDTO.externalId`. Skipped if missing. |
| `name` | string | Offer name | Name. Fallback `"Untitled offer"`. |
| `adwall_name` | string | Name shown on their wall | **Not read.** |
| `description` | string | Description | Description. |
| `allowed_traffic` | string or int | See below. Sample is `"incent"`; legend is `0`/`1`/`2`/`3` | **Not read.** |
| `preview_url` | string | Landing-page preview | **Not read.** |
| `added` | datetime | When they added it | **Not read.** Sample only; missing on some filter-page samples. |
| `end_date` | datetime | Inactive after. `0000-00-00 00:00:00` = none | **Not read.** |
| `target_system` | int | Device / OS id | Mapped through `TARGET_SYSTEMS`. Unknown → `[]` (all devices). |
| `conversion_notes` | string | What the user must do | **Not read.** |
| `conversion_point` | int | Action type id | **Not read.** |
| `cost_to_user` | number | User cost. `-1` = varies | **Not read.** |
| `video_url` / `video_streaming_url` / `video_streaming_ios_url` | string\|null | Rewarded-video assets | **Not read.** |
| `leads_remaining` | int | Remaining before they kill it | **Not read.** |
| `lifetime_lead_cap` | int\|null | Null = none. In the legend, not always in the sample | **Not read.** |
| `daily_lead_cap` | int\|null | Null = none | **Not read.** |
| `payout` | number | USD paid to us | Offer payout. Authority even when events exist. |
| `payout_custom` | number | Publisher override; `0` if none | **Not read** — we use `payout`. |
| `is_support_center_disabled` | 0\|1 | `1` = users cannot file tickets | **Not read.** |
| `stats_pending_*` / `epc_*` / `conversion_rate_*` | mixed | Network / AdLock / AdWall EPC+CR. `1` on pending = not enough stats; `null` EPC = same | **Not read.** |
| `auto_start` / `auto_end` | datetime | Auto go-live / expire | **Not read.** Filter samples also show `start_date`. |
| `product_id` | int[] | Products this offer is on. `8` = Rewarded Video | **Not read.** |
| `category_id` | int[] or strings | Legend: "array of category IDs". Fixture uses names (`"Games"`) | Passed to `categoryNames()` as-is. Numeric ids become `"21"` on the wall unless we map them. |
| `countries` | string[] | ISO-2 | Uppercased. `[]` = worldwide. |
| `featured` | 0\|1 | Featured | **Not read.** |
| `credit_delay` | mixed | `0` instant; `2` days; `2-4` range | **Not read.** |
| `events` | object[] | Multi-event steps | `isMultiReward` when more than one. Goals from `event_name` + `event_revenue`. |
| `pro_tips` | object[] | Engagement blurbs (`display_order`, `name`) | **Not read.** |
| `creatives` | array or object | Images | First entry's `url` is the icon. |
| `click_url` | string | Tracking URL. **Ships without `sub1`.** | We append `sub1={click_id}`. |

### Events (multi-reward)

```json
{
  "event_id": 1,
  "event_name": "Install",
  "event_description": "Install And Launch",
  "event_revenue": 0.15,
  "estimated_time_to_complete": 3600,
  "time_to_complete": 86400
}
```

| Field | Meaning |
|---|---|
| `event_id` | Their event id. **Not stored.** Needed later if a postback names the goal. |
| `event_name` | Goal label |
| `event_description` | Longer text. **Not read.** |
| `event_revenue` | USD for that step |
| `estimated_time_to_complete` | Seconds an average user needs. `0` = not set |
| `time_to_complete` | Max seconds after click. `null` = not set |

They price the offer as a whole **and** itemize steps. The driver keeps `payout` as the authority and still lists the steps.

### Creatives

Official sample is a **list**:

```json
"creatives": [{
  "id": 772,
  "camp_id": 225,
  "image_filename": "225-772.gif",
  "width": 125,
  "height": 125,
  "type": 1,
  "active": 1,
  "code": "",
  "tracking_url": "",
  "url": "http://asmclk.com/creat/225-772.gif"
}]
```

| Field | Meaning |
|---|---|
| `id` | Creative id |
| `camp_id` | Offer id |
| `image_filename` | File name |
| `width` / `height` | Pixels |
| `type` | `1` image, `2` email preview, `3` html preview, `4` text |
| `active` | `1` live, `0` not |
| `code` | HTML for an email creative |
| `tracking_url` | Per-creative click URL; overrides the offer's if set |
| `url` | Image URL |

Our fixture keys creatives by size (`"300x250": { "url": "…" }`). `creativeUrl()` takes `array_key_first` and reads `url` — both shapes work. A live row that only has `tracking_url` on the creative is not used; we still click `click_url`.

### `allowed_traffic`

Two vocabularies on the same site. Do not assume one.

Legend on Get Offers:

| Value | Meaning |
|---|---|
| `0` | No incentives |
| `1` | May incentivize except points or cash |
| `2` | May incentivize with points, no cash |
| `3` | May incentivize with points or cash |

Filter page: query string is `allowed_traffic=incent` or `nonincent`. Sample rows print `"incent"`. Country-filter sample prints `2`. Read the live type before filtering on it.

### Conversion points

| Id | Action |
|---|---|
| 1 | Email submit |
| 2 | First page |
| 3 | Second page |
| 4 | Credit card submit |
| 5 | SMS/PIN confirm |
| 6 | Download |
| 7 | Install |
| 8 | Double opt-in |
| 9 | Third page |
| 10 | Fourth page |
| 11 | After co-registration path |
| 12 | Click |
| 13 | Other |
| 14 | Install and launch |

### Target systems

| Id | Meaning | Our `devices` |
|---|---|---|
| 0 | Any / all | `[]` (unmapped → all) |
| 10 | Windows: all | `[]` |
| 11 | Windows: IE | `[]` |
| 12 | Windows: Firefox | `[]` |
| 13 | Windows: Chrome | `[]` |
| 18 | Windows: FF or Chrome | `[]` |
| 19 | Windows: all but Chrome | `[]` |
| 20 | Mac: all | `[]` |
| 31 | Any desktop: all | `['desktop']` |
| 30 | Any mobile: all | `['android', 'ios']` |
| 40 | Android: all | `['android']` |
| 56 | Android 5.0+ | `['android']` |
| 50 | iOS: all | `['ios']` |
| 51 | iOS: iPhone | `['ios']` |
| 52 | iOS: iPad | `['ios']` |

`0` and the Windows/Mac ids falling through to "all devices" is deliberate: an unrecognised system means we do not know, and empty is our word for all. A desktop-only Windows offer (`10`) will currently show on mobile. Map `10`/`11`/`12`/`13`/`18`/`19`/`20` → `['desktop']` if that starts to matter.

### Category ids

Offers API filter page:

| Id | Label |
|---|---|
| 17 | Free |
| 18 | Mobile Apps |
| 19 | Videos |
| 20 | Surveys |
| 21 | Shopping |
| 22 | Free Trials |
| 23 | Downloads |
| 24 | Sign-Ups |
| 25 | Mobile Subscriptions |
| 26 | Co-Registrations |
| 29 | Casino |
| 30 | Gaming |
| 31 | Finance |

Offer Wall API / iframe add **28 = Shop & Earn Cash Back** and drop 25/26. If live `category_id` is `[21]`, the wall will show `"21"` until we translate the table.

### `credit_delay`

Offers API legend: `0` instant; `2` = that many days; `2-4` = a range.

Offer Wall API also documents `"7"` = weekly and `"14"` = bi-weekly, as **strings**.

---

## Clicks (our Offers tab)

They are explicit: `click_url` has **no** `sub1`. Add it. Optional extras that echo on the postback: `sub2`, `sub3`, `sub4`, `device`.

Stored tracking URL:

```
{their click_url}&sub1={click_id}
```

or `?sub1={click_id}` when the link has no query yet. `buildTrackingUrl()` only substitutes `{click_id}`.

Their docs call `sub1` "user identification". We put the **click UUID** there so `parsePostback()` can find `offer_clicks`. That matches catalogue traffic. It does **not** match their hosted wall (see below).

Max length they document for `subid1`–`subid4` / `device` on the wall API is **60**. Our UUID fits.

Click host in the official sample is `asmclk.com`. Older / fixture rows use `adscendmedia.com/adwall/click/{id}`. Accept both.

---

## Postback

```
https://{host}/postback/adscendmedia
```

**Do not paste that bare URL.** They replace bracket macros you put on the query string. The driver does **not** implement `postbackQuery()`, so Filament currently says "this network sends a fixed payload to the bare URL". That helper text is wrong for Adscend. Until `postbackQuery()` is filled in, paste this by hand into Dashboard → Postbacks (global, or per-offer):

```
https://{host}/postback/adscendmedia?sub1=[SB1]&payout=[PAY]&transaction_id=[TID]&offer_id=[OID]&offer_name=[ONM]&ip=[IP]&key=YOUR_POSTBACK_KEY
```

`[OID]` and `[ONM]` are **not** on a public page. They are the usual Adscend spellings and match what `parsePostback()` already reads. Confirm them on [Variables and Info](https://adscendmedia.com/publishers/dashboard/postbacks/docs#p=variables) before go-live. If the dashboard uses different tokens, change the URL, not the driver — the driver reads **our** query names.

They GET it. We answer **200** on accept. 422 if `sub1` or `payout` is missing. Duplicates still get 200.

Global **or** offer-specific URLs. Same postback is reused for Offer Wall leads, Market Research survey completes, and (if you pick the same URL) rewarded video. Video docs recommend a **separate** URL for video completes.

### Macros seen on public pages

Adscend tokens are `[BRACKETS]`. You choose the query key on the left.

| Their token | Public source | Their meaning | Our query key | Driver |
|---|---|---|---|---|
| `[SB1]` | Rewarded-video docs | "userid, sometimes referred to as subid1". AdsBridge calls it click id. Echo of `sub1` / `subid1` | `sub1` | `clickId`. Required. |
| `[SB2]` `[SB3]` `[SB4]` | Offers API: `sub2`–`sub4` "values you need to receive back" | Echo of those click params | — | **Not read.** |
| `[PAY]` | AdsBridge token list | Payout | `payout` | USD. Required. Negative → `reversed`. **Assumption.** |
| `[TID]` | AdsBridge: "CPS offers… multiple conversions with a single Click ID" | Transaction id | `transaction_id` | `networkTxId`. Fallback `{clickId}:{offerId}`. |
| `[STS]` | AdsBridge | Status | — | **Not read.** Reversal is inferred from a negative `payout`. |
| `[IP]` | Rewarded-video docs | User IP | `ip` | Conversion IP. |
| `[UTS]` / `[UTX]` | Same page lists `[UTS]`, example uses `[UTX]` | Timestamp | — | **Not read.** Confirm the live token. |
| `[DVC]` | Rewarded-video docs | Device id (IDFA / GAID / …) | — | **Not read.** |
| `[PRF]` | Rewarded-video docs | Profile / wall id. Example also writes `[prf]` | — | **Not read.** |
| `[OID]` | Not on a public page | Offer id (confirm) | `offer_id` | `offerExternalId` |
| `[ONM]` | Not on a public page | Offer name (confirm) | `offer_name` | Needed on embed (no offer of ours). |

`user_id` / `member_id` are **our** names. There is no public token that maps to them. If Variables and Info has a member / user token, add it. Until then `endUserId` on the conversion is whatever we already stored on the click.

`key` is **ours**. Put the same string in `postback_key`. They do not sign the request. `hash_equals` against `key`. Blank `postback_key` skips the check.

AdsBridge's canned URL (third party, for their tracker, not us):

```
…&tid=[SB1]&amt=[PAY]&txid=[TID]&status=[STS]
```

### What `parsePostback()` requires

| Incoming query | Required | Maps to |
|---|---|---|
| `sub1` | yes, or 422 | `clickId` → `offer_clicks.click_id` |
| `payout` | yes, numeric, or 422 | USD. `< 0` → `status = reversed` |
| `transaction_id` | no | `networkTxId`. Else `{clickId}:{offerId}` |
| `offer_id` | no | `offerExternalId` |
| `offer_name` | no | Embed rows |
| `user_id` or `member_id` | no | `endUserId` |
| `ip` | no | Conversion IP |
| `key` | only if `postback_key` is set | Auth |

**ASSUMPTION (driver comment):** they reverse by re-firing with a negative payout. `[STS]` is documented by AdsBridge and unused. A live chargeback that keeps a positive payout and sets status will credit twice until we read `[STS]`.

Missing `sub1` is `UnknownClick` after a successful parse… except we never get that far: empty `sub1` makes `parsePostback()` return null (422). That is different from Notik, which accepts the fire and flags the click.

### Auth

1. `networks.postback_ips` — empty rejects every fire.
2. Optional `postback_key` vs `?key=`.
3. No HMAC on any public page.

Firing IPs: ask the AM. Not published.

---

## Hosted wall (Offerwall tab)

Separate product. They host the tiles. Completions still hit the same postback.

Web iframe (no `/preview`):

```
https://adscendmedia.com/adwall/publisher/{pub_id}/profile/{adwall_id}?subid1={user_id}&subid2={sub_id}
```

Android / iOS WebView sample **adds `/preview`**:

```
https://www.adscendmedia.com/adwall/publisher/{pub_id}/profile/{adwall_id}/preview?subid1={user_id}
```

Paste the https iframe URL into **Networks → Hosts its own wall → Partner wall URL**. Never put `api_key` or `postback_key` on it — the iframe `src` is visible to every player.

| Their key | Required | Our macro | Why |
|---|---|---|---|
| `subid1` | yes | `{user_id}` | "Unique identifier of your user". History, hide completed, earnings. Max 60. |
| `subid2` | no for them, **yes for us** | `{sub_id}` | Embed click UUID. Must be the value that lands in `PostbackDTO.clickId`. |
| `subid3` / `subid4` | no | — | Extra trackers. Max 60. |
| `gender` | no | — | `m` / `f` |
| `age_range_id` | no | — | `1` 18–35, `2` 35–45, `3` 45–55, `4` 55–65, `5` 65+ |
| `device` | no | `{device}` if we ever pass IDFA/GAID | Max 60 |
| `category_id` | no | — | Default tab. Same ids as the wall API (17–24, 28–31). |

Opening (`?open=1`) writes one embed `offer_clicks` row (`offer_id` null). Its `click_id` is `{sub_id}`.

**Conflict with the current parser.** Catalogue clicks put the UUID in `sub1` / `[SB1]`. The hosted wall **must** put the stable player in `subid1` or they cannot hide completed offers. That means the embed UUID has to ride `subid2` / `[SB2]`, and `parsePostback()` today only looks at `sub1`. A wall fire with this iframe will 422 or `UnknownClick` until the driver accepts `sub2` (or the postback URL maps `[SB2]` → `sub1`, which then **breaks** catalogue clicks on the same global URL). Fix the parser before enabling `hosts_wall`. Do not put `{sub_id}` in `subid1` on the iframe.

### Profile settings that affect the iframe (not the Offers API)

Created under Dashboard → Offer Wall. Fields they document:

| Setting | Meaning |
|---|---|
| Offer Wall Name | Internal only |
| Currency Name | Points label on their buttons |
| Exchange Rate | Their points per **$1 we earn**. Ledger on our side is USD (`payout`). |
| Minimum Payout | Drops low-paying offers from **their** wall |
| Embed Size | "Leave blank" — they say you will not iframe-size it here |
| Included Offers | Category checkboxes. An offer in a checked **and** unchecked category is **excluded**. "Free" + "Mobile Apps" both on one campaign: unchecking Free kills it. |
| Offer Update Emails | Users opt in from their support pane |
| Round Currency | Round their points |
| Hide Currency Count | Hide their on-wall balance |

Empty wall: they say check min payout, category exclusions, and the viewer's country (they geo-filter by request IP).

---

## Offer Wall API v1.1 (we do not call)

Per-user catalogue. Geo / language / device / OS from `User-Agent` + IP. Override with a custom `User-Agent` and `ip=`.

Credentials: `pubID`, `adwallID`, `api_key` from the profile's "API & SDK Details".

Docs print `http://`. Use `https://`.

### Get Profile Details

```
GET https://adscendmedia.com/adwall/api/v1.1/publisher/{pubId}/profile/{adwallId}/details.json?deviceType=0
```

| Query | Required | Notes |
|---|---|---|
| `deviceType` | no | `0` web (default), `1` Android, `2` iOS. Changes the `customization` colour block. |

404 if the profile is not that publisher's.

Returns `profile.settings` (name, header, `payout_min`, iframe size, currency name/ratio/round/visible) plus a large `customization` map (header colours, card colours, background image/position, support-pane colours). Web keys are hex **without** `#`; Android/iOS keys include `#`. Two keys are misspelled in their sample: `suport_header_*`.

### Get Offers

```
GET https://adscendmedia.com/adwall/api/publisher/{pubId}/profile/{adwallId}/offers.json?subid1={subid1}
```

Path has **no** `v1.1` segment (unlike Get Profile Details). v1 page is the same URL with a slimmer event object.

| Query | Required | Notes |
|---|---|---|
| `subid1` | yes | User id. Max 60. |
| `subid2`–`subid4` | no | Trackers. Max 60. |
| `limit` / `offset` | no | Pagination. Default = all / `0`. |
| `sort_order` | no | `asc` / `desc`. Default `desc`. |
| `sort_by` | no | `popularity` (default), `trending`, `payout`, `credit_delay`. |
| `category_id[]` | no | 17–24, 28–31. |
| `featured_only` | no | `1` / `0`. Default 0. |
| `gender` | no | `m` / `f` |
| `age_range_id` | no | 1–5 |
| `device` | no | IDFA / GAID. Max 60. |
| `device_lat` | no | Limit-ad-tracking boolean. |
| `ip` | no | Fake the user's IP for geo + "already completed". |
| `target_system[]` | no | **v1.1 only.** Mobile Apps (18) only. Same ids as Offers API. |

204 empty. 400 missing param. 404 bad profile.

Row shape differs from Offers API: `requirements`, `image_url`, `currency_count`, `featured_global`, `featured_profile`, `epc` (`99` = still calculating), `conversion_rate` (string or null), `creative_id` / `creative_filename`, `mobile_app.store_id` + `platform` (`1` Android, `2` iOS), `matches_target_system_detected` (they say ignore). Events include `event_revenue` + timing on v1.1.

### Get User Transactions

```
GET https://adscendmedia.com/adwall/api/publisher/{pubId}/profile/{adwallId}/user/{subid1}/transactions.json
```

204 if none. 404 bad profile.

```json
{
  "transactions": [
    {
      "transaction_id": 7,
      "currency_adjustment": 17,
      "offer_id": 31159,
      "offer_name": "Play Castle Clash on your Android!",
      "description": null,
      "timestamp": "2014-09-04T15:51:46-0500"
    }
  ],
  "currency_count": 349.5
}
```

`timestamp` is ISO 8601. Manual adjustments from Create User Transactions appear here too.

### Create User Transactions

HTTPS only.

```
POST https://adscendmedia.com/adwall/api/publisher/{pubId}/profile/{adwallId}/user/{subid1}/transactions.json?api_key={api_key}&currency_adjustment={15.5}&description={Verified email address.}
```

| Param | Required |
|---|---|
| `api_key` | yes (docs type it as Number once; it is a string) |
| `currency_adjustment` | yes. Negative deducts. |
| `description` | no |

400 / 401 / 404. **201** `{ "transaction_id": 123456777 }`. Completions are added automatically; this is only for redeem / goodwill.

### Create Support Tickets

```
POST https://adscendmedia.com/adwall/api/publisher/{pubId}/profile/{adwallId}/user/{subid1}/tickets.json
```

| Param | Required | Notes |
|---|---|---|
| `name` | yes | Max 80 |
| `email` | yes | Max 80 |
| `subject_id` | yes | `1` not credited, `2` misdirect, `3` other |
| `message` | if subject 2 or 3 | Max 200 |
| `offer_name` | if subject 1 | Max 90 |
| `offer_id` | if subject 1 | |
| `selected_event_ids[]` | multi-event + subject 1 | Event ids they completed |
| `completed_at` | if subject 1 | `YYYY-MM-DD` |
| `proof_image_url` | no | jpg/jpeg/png/svg. Not a page scrape. |
| `proof_video_url` | no | avi/mp4/mov/qt/quicktime. **No YouTube / Streamable.** |

400 validation. 404 bad profile. **201** `{ "ticket_id": 73812 }`.

---

## Market Research API (we do not call)

Surveys. A user must have a survey profile before Get Surveys returns anything. Completions reuse the **same** lead postback if one already exists.

Credentials: `pubID`, `profileID`, profile `api_key` on the Integration tab.

Exchange rate on the profile: their points per dollar (example: 1,500 points per $1 so a $2 survey pays 3,000).

### Get Profile Details

```
GET https://adscendmedia.com/market-research/api/publisher/{pubId}/profile/{profileId}/details.json
```

`deviceType`: default `0`; `3` MR-Android; `4` MR-iOS. 404 if the profile is not yours.

Returns `settings.name`, `settings.exchange_rate`, plus MR customization (profiler buttons, calendar colour, survey cards).

### Create Survey User Profile

HTTPS. `Content-Type: application/json`. Body is JSON, not query.

```
POST https://adscendmedia.com/market-research/api/publisher/{pubId}/profile/{profileId}/user/{subid1}.json
```

| Body | Required | Notes |
|---|---|---|
| `api_key` | yes | Profile key |
| `email` | yes | Max 80, unique per profile |
| `first_name` / `last_name` | yes | Max 40 |
| `gender` | yes | `m` / `f` |
| `dob` | yes | `YYYY-MM-DD` |
| `address1` | yes | Max 70 |
| `address2` | no | Max 70 |
| `city` | yes | Max 50 |
| `state` | yes | Max 50 |
| `country` | yes | ISO-2 |
| `zip` | yes | Alphanumeric + `-` `_`. Max 10 |
| `target_system_selected` | yes | Array. `51` iPhone, `52` iPad, `40` Android, `58` computer, `57` game console, `59` portable gaming, `60` Kindle, `61` voice assistant, `[]` none |
| `ip` | no | End-user IP |
| `answers` | yes | `{ "{questionId}": [answerId, …] }`. Question 3 (income) is **country-specific**. |

400 validation. **409** if that `pubId`+`profileId`+`subid1` already exists, or the email is taken. **201** `{ "token": "<JWT>" }`.

Question ids (full answer tables: [profile-questions.php](https://developer.adscendmedia.com/api/market-research/v1/profile-questions.php) — hundreds of country income rows):

| Id | Question | Type | Notes |
|---|---|---|---|
| 1 | Relationship status | Radio | 1 Single … 129 Prefer not to answer |
| 2 | Education | Dropdown | 6–16, plus 1223 Other degree |
| 3 | Household income | Dropdown | US 17–29; GB/DE/CA/IT/NL/… each have their own answer ids |
| 4 | Own or rent | Radio | 30 Own, 31 Rent |
| 5 | Employment | Radio | 32–39, 130–132 |
| 7 | Hispanic / Latino | Radio | 87 Yes, 88 No. There is no question 6. |
| 8 | Pets | Checkbox | 89–97. Multi-select. |
| 9 | Children 18 or under | Dropdown | 98–109 |
| 11 | Ethnicity | Dropdown | 111–126. No question 10. |
| 12 | Daily purchase decisions | Radio | 133–135 |
| 13 | Industry | Dropdown | 1224–1290 |
| 14 | Role in organization | Dropdown | 1291+ |

Their sample `answers` skips 6, 10, and 12.

### Get Surveys

```
GET https://adscendmedia.com/market-research/api/publisher/{pubId}/profile/{profileId}/user/{subid1}/surveys.json
```

Optional `subid2`–`subid4`, `limit`, `offset`. 204 empty. 400 / 404.

```json
{
  "surveys": [
    {
      "name": "High Paying Survey",
      "click_url": "click_url_here",
      "payout": 11.2,
      "currency_count": 5600
    }
  ]
}
```

Default name is `"High Paying Survey"`. `currency_count` uses the profile exchange rate.

### Get / Create User Transactions

Same idea as the wall API, under `/market-research/api/…`. Create is HTTPS + `api_key` + `currency_adjustment`. Get returns `transactions[]` + `currency_count` (sum). Manual deductions show `offer_id` / `offer_name` null and a `description`.

---

## Publisher API — user blocks (we do not call)

Same Basic auth as Offers API (`pub_id` / `api_key`). 404 if `pubID` does not exist.

```
GET    https://api.adscendmedia.com/v1/publisher/{pubId}/users?page=1&per_page=50
POST   https://api.adscendmedia.com/v1/publisher/{pubId}/users
DELETE https://api.adscendmedia.com/v1/publisher/{pubId}/users
```

Body (GET optional filter; POST/DELETE required):

```json
{ "ids": ["698155", "690241"] }
```

| Method | Success | Body |
|---|---|---|
| GET | 200 | `{ data: { page, per_page, users: [{ user_id, created_at }] }, details: { status: "OK" }, ok: true, code: 200 }`. Default page 1, 50 per page. |
| POST | 201 | `{ data: { created_count, existed_ids, errors_ids }, details: { status: "Created" }, ok: true, code: 201 }` |
| DELETE | 200 | `{ data: { deleted_count }, details: { status: "OK" }, ok: true, code: 200 }` |

These `user_id`s are **their** wall users (`subid1`), not our publisher users.

---

## What we do not handle

- Offer Wall API (per-user catalogue, points, tickets).
- Market Research / surveys.
- Publisher block list.
- Rewarded video SDK / locker.
- `allowed_traffic` / incent vs non-incent filtering.
- Caps (`leads_remaining`, daily / lifetime).
- `payout_custom`, EPC, conversion rate, featured, credit delay, pro tips.
- Event ids on the postback.
- `[STS]`, `[SB2]`–`[SB4]`, `[DVC]`, `[PRF]`, `[UTS]`/`[UTX]`.
- Their "hide if this user already completed it" rule on Offers API traffic. We hide on **our** approved `(end_user_id, offer_id)`.
- `currency_count` / their exchange rate — ledger is USD.
- `postbackQuery()` — Filament copies a bare URL. The dashboard needs macros.

---

## Driver gaps to fix before a live fire

1. **`postbackQuery()` is empty.** Fill it with the confirmed Variables-and-Info tokens mapped onto the names `parsePostback()` already reads, so the admin copy-box is the contract.
2. **Seed `postback_ips`.** Empty rejects. Get the list from the AM.
3. **Confirm `[OID]` / `[ONM]` / `[PAY]` / `[TID]` / `[STS]`** on the dashboard page. Especially whether a chargeback is a negative `[PAY]`, a `[STS]` value, or both.
4. **Hosted wall vs `sub1`.** Parser must accept the embed UUID from `[SB2]` without breaking catalogue `[SB1]`.
5. **`category_id` type.** Fixture uses names; official legend uses ints. Map the table if live rows are `[21]`.
6. **Pagination.** Add `page` / `per_page` if a real catalogue is truncated.
7. **204 on Test connection.** Empty-but-valid should not look like a failed login.

---

## Live-fire checklist

1. Network form: Test connection counts `offers`. 403 = wrong `pub_id` / `api_key` pair.
2. Sync: USD rows appear; `click_url` stored with `sub1={click_id}`; icon from first creative `url`.
3. Click an offer: outbound URL contains `sub1=<uuid>`.
4. Dashboard → Postbacks: paste the **macro** URL (not the bare path). Include `key=` if `postback_key` is set.
5. Their postback test: same uuid in `[SB1]` → `sub1`, numeric `payout`, source IP on `postback_ips`. Expect `accepted`.
6. `422` means `sub1` or `payout` never arrived — the dashboard URL is missing a macro, not a `ConversionProcessor` bug.
7. `unknown_click` means `sub1` was not our UUID (hosted wall put the player in `subid1`).
8. Chargeback test: confirm the live shape (negative `payout` and/or `[STS]`) before trusting `reversed`.
9. Embed: do not enable `hosts_wall` until step 4's parser reads `[SB2]`.
