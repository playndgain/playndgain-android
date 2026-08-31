# HangMyAds (`hangmyads`)

Working notes for Hang My Ads / Hang My Tracking: catalogue fetch, tracking links, partner offerwall, and postback. Driver: `app/Networks/Drivers/HangMyAdsDriver.php`. Fixture: `tests/Fixtures/Networks/hangmyads-offers.json`.

Fetched 2026-08-18 from their live pages (every sidebar method opened; hidden panels dumped from the DOM). They still publish **no postback URL and no signature**. The macro list below is Hang My Tracking's "Optional Variables" (publisher dashboard, 2026) — what they replace when an affiliate conversion code fires.

Their pages:

| Page | URL | What it is |
|---|---|---|
| Hang My Tracking API V1 | [api.hangmytracking.com/docs/developers.html](https://api.hangmytracking.com/docs/developers.html) | Catalogue, conversions report, tracking link, access request |
| Offerwall | [hangmyads.com/docs/offerwall.html](https://hangmyads.com/docs/offerwall.html) | Their hosted wall (`offerwall.hangmyads.com/ow`) |
| Ad Tags | [hangmyads.com/docs/adtags.html](https://hangmyads.com/docs/adtags.html) | Banner / video tags. Not our wall. |
| Publisher dashboard | [affiliates.hangmytracking.com](http://affiliates.hangmytracking.com/) | Where the AM says the postback URL is pasted |
| Marketing site | [hangmyads.com](https://hangmyads.com/) | Names the four integrations: Offerwall, Real Time API, S2S Postback, Ad Tags |

---

## Credentials

Stored on `networks.credentials` (encrypted). Admin labels come from `credentialSchema()`.

| Our key | Their name | Required | Where they show it |
|---|---|---|---|
| `api_id` | `apiID` / Network ID | yes | Account Manager. Home sample also calls it `NETWORK_ID`. |
| `api_token` | `apiToken` / API Token / API Key | yes | Same. Home sample calls it `API_KEY`. |

There is no documented postback secret. `verifyPostback()` is a no-op. The only real check is `networks.postback_ips`, which **rejects when empty**. Their firing addresses are not on any public page — ask the AM and paste them before the network is switched on.

**Our egress IP must also be allowlisted** before `getOffers` answers. A worker that syncs from a new address is rejected. The rejection is HTTP 200 with `status: NOK`, not a 401.

Talk to the Account Manager for: API ID, API Token, API IP allowlist, postback firing IPs, and the postback URL they will hit.

---

## Transport

Every method uses the same endpoint. Docs print `http://`. The token travels in the body, so the driver posts `https://`.

```
POST https://api.hangmytracking.com/api.php
Content-Type: application/x-www-form-urlencoded
```

Not JSON. One PHP script reading request parameters. `method`, `apiToken`, and `apiID` on every call. Filters go on as `filters[key][]=value` (PHP `http_build_query` of an array). Field selectors go on as `fields[name]=on`.

### Rules they print on Home

- API ID and Token are required.
- IP addresses making the requests must be submitted to the white-list for API access.
- All API requests must use the POST method.
- Rate limit: **20 API calls every 10 seconds**.

### Envelope

`status` is `OK` or `NOK`. `errors` is a count. `errorMessages` is an array of strings. On success, `data` holds `total_records` plus the payload for that method.

A bad token and a caller that is not allowlisted both arrive as HTTP 200 / `status: NOK`. The driver treats that as a hard failure. Reading it as an empty catalogue would deactivate every HangMyAds offer we have.

```json
{
  "status": "OK",
  "data": {
    "total_records": 1,
    "offers": [ { "id": 25156 } ]
  },
  "errors": 0,
  "errorMessages": []
}
```

```json
{
  "status": "NOK",
  "data": [],
  "errors": 1,
  "errorMessages": ["Invalid API credentials"]
}
```

`format` is accepted on several methods and ignored — "at this moment, all results will be in json".

---

## Methods

Eleven methods on the left menu. We call **`getOffers` only**. The rest are here so a later postback or access-request change does not start from a blank page.

| Method | We call it? | Why it exists |
|---|---|---|
| `getOffers` | yes | Catalogue. |
| `getCategories` | no | Category IDs for `filters[category]`. |
| `getConversionDetails` | no | Conversion dump. Vocabulary for the postback guess. |
| `getOfferStatus` | no | Why an offer is paused (cap, etc.). |
| `getOfferAds` | no | Creatives. |
| `getOfferURLs` | no | Extra landing pages. Default URL is already on the offer. |
| `getOfferTargeting` | no | Geo / device allow-deny. `getOffers` already has countries and OS. |
| `getTrackingLink` | no | Fresh link, optional `urlID=random` A/B. Catalogue already ships `tracking_link`. |
| `requestOfferAccess` | no | Ask for a gated offer. We skip rows with `tracking_link: null` instead. |
| `getFinancialReport` | no | Revenue by day / offer. |
| `getPrintsReport` | no | Ad-tag impressions / eCPM. |

Shared pagination on the list methods: `limit`, `page` (default first page), `sort`, `sortby` (`ASC` or `DESC`).

---

## `getOffers` — fetching

"Find offers and get details on them." This is the catalogue.

```
POST method=getOffers
     apiID={api_id}
     apiToken={api_token}
     page=1
     limit=250
     epc=0
```

The driver's page size is 250. Their published example is 25. Bigger pages mean fewer trips against the 20/10s cap. The loop stops on an **empty** `data.offers`, not on a short page, so a server-side cap lower than 250 costs extra requests instead of truncating the catalogue.

`testConnection()` asks for `page=1&limit=1` and reports `data.total_records` (the whole catalogue, not the page).

`epc=1` makes them compute current campaign EPC per row. We send `0`. We rank on our own click history.

### Parameters

| Name | Type | Required | Meaning |
|---|---|---|---|
| `filters` | array | no | See keys below. |
| `epc` | integer | no | `1` include current EPC, `0` skip. |
| `limit` | integer | no | Rows per page. |
| `page` | integer | no | Defaults to first page. |
| `sort` | string | no | One of the offer fields. Example: `start_date`. |
| `sortby` | string | no | `ASC` or `DESC`. |
| `format` | string | no | Ignored. JSON anyway. |

**`filters` keys**

| Key | Value |
|---|---|
| `idOffer` | Array of offer IDs. Home sample uses this to fetch one offer. |
| `country` | Array of ISO country codes (`GB`, `IN`). |
| `category` | Array of category IDs (`7`, `8` = Android / iOS in their example). |

Example they print (25 most recent iOS or Android offers for GB or IN):

```
method=getOffers
filters[country][]=GB
filters[country][]=IN
filters[category][]=7
filters[category][]=8
sort=start_date
sortby=desc
limit=25
page=1
```

Home sample (one offer, with EPC):

```
method=getOffers
filters[idOffer][]=25156
epc=1
```

Home prose says "offer ID 42"; the code next to it uses `25156`. Trust the code.

We send **no filters**. We pull the whole catalogue and drop rows we cannot sell.

### Response

`data.offers[]` plus `data.total_records`. Sample on Home (PHP `print_r` of `json_decode`). Live rows add a few fields the sample does not show — those are in the field table.

### Offer row

Money is **cents** everywhere: `payout_cents`, `pay_cents`, `daily_cents_cap`. `OfferDTO` is USD. Division by 100 happens in the driver and nowhere else.

| Their field | In Home sample | In live fixture | What we do |
|---|---|---|---|
| `id` | 25156 | 85503 | `OfferDTO.externalId`. Skipped if missing. |
| `name` | `GB 2445 Incent/Android/… - PokerStars` | `US 8129 CPI/iOS/…` | Offer name. Fallback `"Untitled offer"`. |
| `conversion_type` | `serverpostback` | `serverpostback` | **Not read.** Their conversion plumbing, not our `pay_type`. |
| `conv_default_status` | `approved` | `approved` / `pending` | **Not read.** Pending default does not change the tile. |
| `allow_duplicates` | `0` | `0` | **Not read.** Matters on the postback: one click can convert more than once if they flip this. |
| `require_approval` | `1` | `1` / `0` | **Not read.** `tracking_link === null` is the usable signal. |
| `approved` | `1` | `1` / `0` | **Not read.** Same reason. |
| `tracking_link` | click.php template | same, or `null` | Required. `null` = not approved = **dropped**. Placeholders rewritten (see Clicks). |
| `description` | "Install, Register & Deposit…" | "Run the App Two Days…" | Description. |
| `conditions` | "Requires Android 5.0 or higher." | "Requires iOS 15.0 or later" / `null` | Instructions. |
| `daily_cap` | `50` | `"No Cap"` / `"150"` | Daily cap. Numeric only. `"No Cap"` → `null`. |
| `cap_timezone` | `(GMT+00:00)` | `null` | **Not read.** |
| `monthly_cap` | `500` | `"No Total Cap"` | **Not read.** We have no monthly-cap column. |
| `daily_cents_cap` | — | `"No Cap"` | **Not read.** Live-only. Money cap, not conversion count. |
| `blocked_sources` | `["123_abc", "987_zxy"]` | `null` | **Not read.** |
| `categories` | `"Incent, CPA, Direct, Android, Private, DT"` | same shape | **Not read.** We use `categories_obj`. |
| `categoriesID` | `"6, 21, 17, 7, 16, 23"` | same | **Not read.** |
| `countries` | `"GB"` | `"US"` / `"GB, DE"` | Parsed as ISO-2. Leading spaces are theirs. |
| `countries_name` | `"United Kingdom"` | `"United States of America"` | **Not read.** |
| `start_date` | `2020-01-01 08:00:00` | `2026-08-11 13:22:00` | **Not read.** |
| `expiration_date` | `2025-04-14 00:30:00` | `2031-08-11 13:22:00` | **Not read.** |
| `status` | `active` | `active` / `paused` | Only `active` is stored. Paused is dropped so the sync sweep deactivates it. |
| `preview_URL` | Play Store URL | App Store URL / `null` | Preview. |
| `payout_cents` | `5000` (= $50.00) | `70` (= $0.70) | USD payout. Authority over goal totals. |
| `pay_type` | `CPA` | `CPI` / `CPA` | `conversionType`. Offerwall also documents `CPC`. |
| `pay_curr` | `USD` | `USD` / `EUR` | Non-USD is **dropped**. Ledger is USD. |
| `thumbnail` | banners.hangmyads.com PNG | same / `null` | Icon. |
| `epc` | `7.06` | `0.13` | Only present when requested. **Not read.** |
| `package` | `com.pokerstars.uk` | `id6504506653` / package name | **Not read.** |
| `app_name` | — | `Sekai: Create, Remix and Play` | **Not read.** Live-only. `name` already has the campaign title. |
| `os` | `Android` | `iOS` / `Android` / `null` | Device targeting, with `devices`. |
| `devices` | `All` | `All` / `Desktop` | `"All"` maps to nothing (our "every device"). |
| `minOSVersion` | — | `"None"` / `"8.0"` | `osMinVersion`. `"None"` → `null`. Live-only. |
| `categories_obj` | `{6: Incent, 21: CPA, …}` | same | Category names. |
| `countries_obj` | `{GB: United Kingdom}` | `{US: United States of America}` | **Not read** — `countries` is enough. |
| `goals` | array of single-key objects | object keyed by goal name | See Goals. |

**Drop rules** (not stored, so a later sync deactivates them):

- no `id`
- `tracking_link` null or empty (not approved)
- `pay_curr` not `USD`
- `status` not `active` (missing status is treated as active)

### Goals

Three shapes exist. The driver only accepts the live-fixture shape.

**1. Home sample (docs)** — a JSON *array* of one-key objects:

```json
"goals": [
  { "first deposit": { "pay_type": "CPA", "pay_cents": 5000, "pay_currency": "USD" } },
  { "completed_registration": { "pay_type": "CPA", "pay_cents": 0, "pay_currency": "USD" } }
]
```

Iterating that in PHP gives integer keys. `goals()` requires a string name, so **this sample would yield no steps**. If live `getOffers` ever returns this array form, multi-reward tiles lose their steps and `isMultiReward` goes false. Confirm against a live multi-goal row before trusting the sample.

**2. Live fixture** — object keyed by goal name. This is what `goals()` reads:

```json
"goals": {
  "completed_registration": { "pay_type": "CPA", "pay_cents": 250, "pay_currency": "USD" },
  "first deposit": { "pay_type": "CPA", "pay_cents": 1500, "pay_currency": "USD" }
}
```

**3. Offerwall JSON** — either a numbered object or a list of `{goal_name, pay_cents, event_desc, reward}` (user-facing points, not USD). Not used by the catalogue driver.

A step with no label is dropped. `pay_cents` / 100 → USD. `isMultiReward` when more than one step survives.

`payout_cents` on the offer is the offer's own price and stays the authority. Goals itemize it; we do not recompute the total from them.

---

## `getCategories`

"Get all the Category types available on the platform and their respective IDs. It's particulary helpfull to filter offers by category ID."

| Name | Type | Required | Meaning |
|---|---|---|---|
| `filters[idCategory]` | array of integer | no | Empty = all categories. |

Response: "A categories object containing the category names requested."

Example: IDs 6, 7, 8.

IDs seen on Home / fixture / offerwall / ad tags (not a complete list — call `getCategories` for that):

| ID | Name | Seen on |
|---|---|---|
| 5 | CPI | live fixture |
| 6 | Incent / CPA (docs sample says Incent; offerwall JSON uses 6 for Incent *and* CPA rows) | Home, fixture, offerwall |
| 7 | Android | Home |
| 8 | iOS | fixture, offerwall |
| 9 | Android | live fixture (second coding) |
| 16 | Private | Home |
| 17 | Direct | Home, fixture |
| 21 | CPA | Home |
| 22 | (offerwall MultiReward row) | offerwall |
| 23 | DT | Home |
| 26 | (offerwall Search CPI) | offerwall |
| 27 | Shopping | ad tags `cat` |
| 29 | Dating | ad tags |
| 30 | Utilities | ad tags (fixture uses 47 for Utilities) |
| 31 | Casual Games / Finance (fixture uses 31 for Finance) | ad tags vs fixture — **do not hardcode names** |
| 32 | Action Games | ad tags |
| 33 | Card Games | ad tags |
| 34 | Gambling | ad tags |
| 35 | Food or Travel | ad tags |
| 36 | (offerwall DS) | offerwall |
| 44, 45 | Games / MultiReward | offerwall |
| 47 | Utilities | fixture, offerwall |

IDs collide across docs. Always resolve through `categories_obj` or `getCategories`, never a local map.

---

## `getConversionDetails`

"Get the details of the Conversions made between a range of dates."

This is a **pull** of conversions we already have, not the inbound postback. Field names here (`goal_name`, `offer_id`, `id`) do not match the conversion-code macros (`{goalname}`, `{offerid}`, `{convid}`). The postback parser reads both.

| Name | Type | Required | Meaning |
|---|---|---|---|
| `start_date` | DateTime `YYYY-MM-DD hh:mm:ss` | yes | Range start. |
| `end_date` | DateTime `YYYY-MM-DD hh:mm:ss` | yes | Range end. |
| `offset` | integer | no | Timezone offset to GMT in **minutes**. Eastern US = `-300` (`-5 * 60`). Example uses `-8 * 60` for PST. |
| `fields` | array | **yes, at least one** | Keys of wanted fields, value `"on"`. |
| `filters` | array | no | Keys: `offer_id`, `ad_id`. Value = that field's ID. |
| `limit` | integer | no | Page size. |
| `page` | integer | no | Default first page. |
| `sort` | string | no | One of the allowed fields. |
| `sortby` | string | no | `ASC` or `DESC`. |
| `format` | string | no | Ignored. |

**`fields` they list** (they printed `affiliate_subid2` twice; listed once here):

| Field | Likely meaning |
|---|---|
| `id` | Their conversion id. Best candidate for `networkTxId` if a live postback ever sends it. |
| `offer_id` | Offer. |
| `offer` | Offer name. |
| `affiliate_id` | Us. |
| `affiliate` | Affiliate name. |
| `ad_name` | Creative. |
| `url_name` | Landing page name (`getOfferURLs`). |
| `goal_name` | Which goal fired. |
| `conv_time` | When. |
| `payout` | Amount. Unit not stated here. Catalogue money and the `{payout}` conversion-code macro are **cents**. `{pay}` is USD. |
| `currency` | Currency of `payout`. |
| `click_id` | Their click id. Same slot as tracking `subid` under another name. |
| `affiliate_source` | Source / `type` on the click. |
| `affiliate_subid` | What we put in `subid`. |
| `affiliate_subid2` … `affiliate_subid5` | Extra subs. Offerwall says 2–4 come back on the S2S postback. |
| `aff_custom` | Custom. |
| `click_ip` | Click IP. |
| `is_duplicated` | Duplicate flag. |
| `country_name` | Geo. |
| `browser` | Browser. |
| `device_type` | Device. |
| `device_brand` | Brand. |
| `device_model` | Model. |
| `device_os` | OS. |
| `device_os_version` | OS version. |
| `device_id` | Device id. |
| `android_id` | Android id. |
| `mac_address` | MAC. |
| `ios_ifa` | IDFA. |
| `google_aid` | GAID. |

Example they print: conversion time + affiliate subid, 10 rows, page 2, offer 182, 2015-01-08 08:00–21:00, offset PST, sort `conv_time` desc.

Response: "An array of Conversion objects containing the conversion fields requested." **No sample body.**

---

## `getOfferStatus`

"Get details of the reason for status of an offer, like if it's paused due to reaching the cap and it will come back."

| Name | Type | Required |
|---|---|---|
| `offerID` | integer | yes |

Response: "An objects with the details of the status of the offer." **No sample body.** Example: "Why is offer 19517 paused?"

Useful later if we want to keep a capped offer visible with a reason instead of dropping it.

---

## `getOfferAds`

"Get Ad details from one or more offers."

| Name | Type | Required |
|---|---|---|
| `filters` | array | yes — `n_idOffer` is mandatory. "Most fields can be used." |

Example filters: `n_idOffer` = 197,198; `n_idAd_type` = 1 (300×250 banners); `width` = 300; `height` = 250.

Ad type `1` matches ad-tags docs: 1 = Image Banner.

Response: "An array of Ads objects containing the ad details." **No sample body.**

---

## `getOfferURLs`

"Get URL details from one or more offers. URL named default is already specified in the offer details but it's possible to have more than one landing page in the offer."

| Name | Type | Required |
|---|---|---|
| `filters` | array | yes — `n_idOffer` required |

Example: offers 160, 161, 162.

`getTrackingLink.urlID` picks one of these, or `random` for a 50/50 A/B.

Response: "An array of URL objects containing the URL details." **No sample body.**

---

## `getOfferTargeting`

"Get Target details from one or more offers."

| Name | Type | Required |
|---|---|---|
| `filters` | array | yes — `n_idOffer` required. Also filterable by `type` and `action`. |

Example: Geo / Allow on offers 23, 156, 162, 172.

```
filters[n_idOffer][]=23
filters[type][]=Geo
filters[action][]=Allow
```

Known filter values from the example: `type=Geo`, `action=Allow`. Deny / device / OS targeting is implied but not listed.

Response: "An array of Target objects containing the target details." **No sample body.**

`getOffers` already gives `countries`, `os`, `devices`, `minOSVersion`. We do not call this.

---

## `getTrackingLink`

"Get the Tracking Link from available offers. If the offer requires approval, a access request has to be made first."

| Name | Type | Required | Meaning |
|---|---|---|---|
| `offerID` | integer | yes | Offer. |
| `urlID` | integer or `"random"` | no | Landing page from `getOfferURLs`. `random` = 50/50 A/B. |
| `adID` | integer | no | Track a specific ad from `getOfferAds`. |

Response: "The affiliate tracking link for the offer." **No sample body.**

We do not call it. Catalogue `tracking_link` is enough, and an unapproved offer comes back with `null` there.

---

## `requestOfferAccess`

"Request access to an offer."

| Name | Type | Required |
|---|---|---|
| `offerID` | integer | yes |

Response: "Hang My Tracking response to the offer access request." **No sample body.** Example: offer 79.

We do not auto-request. Unapproved rows are skipped (`tracking_link: null`). If we ever want private offers on the wall, this is the method, then a later `getOffers` should start returning a link.

---

## `getFinancialReport`

"Get a detailed Financial report of the offers."

| Name | Type | Required | Meaning |
|---|---|---|---|
| `start_date` | Date `YYYY-MM-DD` | yes | |
| `end_date` | Date `YYYY-MM-DD` | yes | Docs: "The difference between start and end date to be bigger than 3 days." |
| `offset` | integer | no | GMT offset in minutes. Example: `-480` for US Pacific, `60` for Central Europe. |
| `fields` | array | **yes, at least one** | Value `"on"`. |
| `filters` | array | no | Only key: `offer_id`. |
| `limit` | integer | no | |
| `page` | integer | no | |
| `sort` | string | no | Must be one of the **requested** fields. |
| `sortby` | string | no | `ASC` / `DESC`. |
| `format` | string | no | Ignored. |

**`fields`:** `offer_id`, `offer`, `publisher`, `zero_rev`, `rev_type`, `rev_cents`, `year`, `month`, `week`, `day`, `revenue`, `rpa`, `installs`, `currency`, `storeid`.

Example prose says "payout and conversions grouped by day from the top 10 most profitable offers" but the code sorts by `payout` while requesting `offer`, `installs`, `publisher`, `revenue`, `day`, `storeid` — `payout` is **not** in the allowed field list. Their example and their table disagree. `rev_cents` / `revenue` are the money fields they actually document.

Response: "An array of Stats objects containing the stats report requested." **No sample body.**

---

## `getPrintsReport`

"Get an Ad Tag report that includes impressions count, CVR, eCPM, etc."

| Name | Type | Required | Meaning |
|---|---|---|---|
| `start_date` | Date `YYYY-MM-DD` | yes | |
| `end_date` | Date `YYYY-MM-DD` | yes | No 3-day rule here. |
| `fields` | array | **yes, at least one** | Value `"on"`. |
| `filters` | array | no | Keys: `offer_id`, `creative_id`. |
| `limit` | integer | no | |
| `page` | integer | no | |
| `sort` | string | no | Must be a requested field. |
| `sortby` | string | no | `ASC` / `DESC`. |
| `format` | string | no | Ignored. |

**`fields`:** `impressions`, `cpm`, `click_unique`, `ctr`, `installs`, `cr`, `payout`.

Example: last 3 days, sort `payout` desc, all metric fields on.

Response: "An array of Stats objects containing the stats report requested." **No sample body.**

---

## Clicks (our Offers tab)

Catalogue link they ship (Home sample):

```
https://tracking.hangmytracking.com/click.php?oid=25156&aid=aff_id&subid=YOUR_CLICK_ID&type=YOUR_SUB_SOURCE&google_aid=REPLACE_WITH_GAID&ios_ifa=REPLACE_WITH_IDFA
```

Live fixture, same shape, `aid` already filled with the affiliate id:

```
https://tracking.hangmytracking.com/click.php?oid=85503&aid=9912&subid=YOUR_CLICK_ID&type=YOUR_SUB_SOURCE&google_aid=REPLACE_WITH_GAID&ios_ifa=REPLACE_WITH_IDFA
```

Some live rows omit the placeholders entirely (`?oid=85610&aid=9912`). The driver then appends `&subid={click_id}`.

### Click.php query params

| Param | Required on their side | Meaning |
|---|---|---|
| `oid` | yes | Offer id. |
| `aid` | yes | Affiliate / publisher id (`api_id` in the sample). |
| `subid` | **yes for us** | Click / custom id. Echoed on the postback as `subid` / `affiliate_subid` / `{subid}`. |
| `type` | no | Sub source. We blank `YOUR_SUB_SOURCE` rather than send the placeholder word. |
| `google_aid` | no | GAID. We blank `REPLACE_WITH_GAID`. Sending the placeholder to a tracker that scores device ids is a click asking to be flagged. |
| `ios_ifa` | no | IDFA. Same, blanked. |
| `adid` | no | Creative id (ad-tag / `getOfferAds` links). |
| `subid2` … `subid5` | no | Extra. Offerwall says 2–4 come back on S2S. |
| `subid5` | no | Offerwall HTML uses this for the point reward (`subid5=37`). |

### Placeholder rewrite (`LINK_PLACEHOLDERS`)

| They send | We store |
|---|---|
| `YOUR_CLICK_ID` | `{click_id}` |
| `YOUR_SUB_SOURCE` | `` (empty) |
| `REPLACE_WITH_GAID` | `` |
| `REPLACE_WITH_IDFA` | `` |

If `{click_id}` is still missing after that, `&subid={click_id}` is appended. `buildTrackingUrl()` runs the same helper, then substitutes the UUID. A stored link with no macro — hand-edited, or synced before this existed — must not be handed to the user untouched.

---

## Iframe (their Offerwall)

Not the API. Separate product: [offerwall.html](https://hangmyads.com/docs/offerwall.html).

They detect country and device themselves. HTML5 by default, or `format=json` for our own tiles.

```
https://offerwall.hangmyads.com/ow?pubid=1234&subid=UNIQUE_ID&google_aid=81b99d32-ef62-445d-9460-dce74066cb5a
```

The example uses `pubid`. The parameter table uses `pub`. Confirm with the AM which one their account actually reads before pasting a wall URL.

| Parameter | Type | Required | Meaning |
|---|---|---|---|
| `pub` / `pubid` | integer | yes | Publisher ID on HangMyTracking. Not necessarily `apiID`. Ask the AM. |
| `subid` | string | yes | "A unique identifier of the click/conversion. Can be for example, the user ID on your side or a unique click ID generated." For an embed this should be our embed click UUID (`{sub_id}`), not a stable user id, or every conversion of that user collapses onto one click. |
| `curr` | string | no | User-facing currency name (`points`, `coins`). Set in their back office via the AM. |
| `percent` | integer | no | Percent of payout given to the user in `curr`. Same, back office. |
| `subid2`, `subid3`, `subid4` | string | no | "Should be used, especially if it's information needed to be returned on the S2S Postback." |
| `ios_ifa` | string | no | Unlocks IDFA-gated iOS offers. |
| `google_aid` | string | no | Unlocks GAID-gated Android offers. |
| `format` | `html` \| `json` | no | Default HTML. |

Paste into **Networks → Hosts its own wall → Partner wall URL** only after the AM confirms `pub` vs `pubid` and that `{sub_id}` is what they will echo:

```
https://offerwall.hangmyads.com/ow?pubid=YOUR_PUB_ID&subid={sub_id}
```

Do not put `{user_id}` in `subid` if we need per-open attribution. Their own text allows either; our embed row is one UUID per open.

Opening (`?open=1`) writes one embed `offer_clicks` row (`offer_id` null). Its `click_id` is `{sub_id}`.

### Offerwall JSON row

Returned when `format=json`. Fields they list:

| Field | Meaning |
|---|---|
| `id` | Offer id. |
| `categories` | Geos and categories as a title string (`US 2029 Incent/iOS/CPA/Utilities`). |
| `offer_name` | App being promoted. |
| `last_name` | Same as `offer_name` in the samples (not listed in the prose). |
| `description` | Steps to convert. |
| `conditions` | General conditions / OS / end date. |
| `preview_URL` | Store page. |
| `daily_cap` | Daily conversions available. |
| `categoriesID` | Category ids, comma string. |
| `countries` | Country codes. |
| `installed` | `1` if this `subid` already completed it, else `0`. |
| `daily_convs` | How many of *our* users converted today. |
| `payout_cents` | What we receive, cents. |
| `pay_type` | `CPI` / `CPA` / `CPC`. |
| `pay_curr` | `USD`, `EUR`, … |
| `thumbnail` | Icon. |
| `epc` | Current EPC. |
| `tracking_url` | Click URL. Already has `subid=` filled with the request's `subid`. |
| `promo` | `1` if a promotion is on. |
| `prev_reward` | Reward without promo (user points). |
| `reward` | Total user reward. |
| `currency` | Offerwall currency name (`Points`). |
| `goals` | Post-attribution events. |

Offerwall tracking URL they print:

```
https://tracking.hangmytracking.com/click.php?oid=43122&aid=7777&subid=USER_ID&subid2=&subid3=&ios_ifa=
```

HTML wall links add `type=offertest` and `subid5=<points>`.

We do not fetch this JSON. If HangMyAds is used as a hosted wall, the iframe is enough and conversions come back on the postback against the embed click.

---

## Ad Tags (not used)

[adtags.html](https://hangmyads.com/docs/adtags.html). Banner / video / rich media. Endpoint:

```
https://tracking.hangmytracking.com/ads.php?pub=1775&ad_type=1&w=300&h=250&country=us&os=ios&dev_type=phone&lim=1&res=json
```

| Param | Required | Meaning |
|---|---|---|
| `pub` | yes | Publisher id. |
| `plid` | yes unless `h`/`w`/`dev_type` | Placement id. |
| `ad_type` | no | `1` Image Banner, `5` Text Link, `7` Video, `8` Rich Media. |
| `h`, `w` | yes if no `plid` | Ad space size. |
| `os` | no | `android` or `ios`. |
| `dev_type` | no | `phone`, `tablet`, `both`. |
| `country` | no | ISO-2. |
| `ip` | no | Only if `country` is missing. |
| `cat` | no | Category ids, comma-separated. |
| `city`, `age`, `gen` | no | Soft targeting. |
| `lim` | no | Max ads. `lim=1` for one. |
| `res` | yes | `HTML`, `JSON`, `XML`. |

Click URL they embed: `subid={CLK_ID}` and `ios_ifa={IDFA}`. Impression beacon: `https://tracking.hangmytracking.com/prt.php?adid={ad}&plid={placement}`.

MoPub tag: `https://tracking.hangmytracking.com/js/hma_mopub.min.js` with `hma_pub`, `hma_plid`, `device_id`, `hma_ip`, `bundle`, `cb`.

Empty response when nothing matches. We do not serve HangMyAds banners.

---

## Postback

```
https://{host}/postback/hangmyads
```

Paste `HangMyAdsDriver::postbackUrl()` into their publisher dashboard (HangMyTracking). They GET it (assumed — method not documented). We answer HTTP 200. Body does not matter to us; they have published no required body.

No hash, no timeout, no retry policy, no firing IPs. `verifyPostback()` is `true`. Empty `postback_ips` still rejects at the controller.

### Official macros (Hang My Tracking "Optional Variables", 2026)

Replaced when they redirect to an advertiser Offer URL **or** when an affiliate Conversion Code fires. Brace tokens must be **theirs**.

| Macro | Meaning | We use |
|---|---|---|
| `{offerid}` | Offer id | yes |
| `{offer_name}` | Offer name | yes (fallback label) |
| `{goalid}` | Goal id | no |
| `{goalname}` | Goal name | yes |
| `{type}` | `type` on the tracking link | no |
| `{subid}` … `{subid5}` | Affiliate subs from the tracking link | `{subid}` is our click id |
| `{url_id}` | Offer URL id | no |
| `{adid}` | Ad id | no |
| `{convid}` | Unique conversion id on their system | yes → `networkTxId` |
| `{cid}` | **Their** click id | no — not our UUID |
| `{payout}` | Affiliate payout, **cents** | yes, if `{pay}` is absent → ÷ 100 |
| `{pay}` | Affiliate payout, **USD** (conversion codes only) | yes — this is the paste-URL field |
| `{reward}` | Offerwall currency | no — not USD |
| `{amount}` | Advertiser sale, cents | no |
| `{"customVarName"}` | Custom var echoed from the click | no |
| `{device_brand}` `{device_model}` `{os}` `{device_os_version}` | Device | no |
| `{ios_ifa}` `{google_aid}` | Mobile advertiser ids | no |
| `{ua}` | Click user agent | no |
| `{timeDiffSecs}` | Seconds click → conversion | no |
| `{ts}` | Current timestamp | no |

There is **no** click-IP macro. `{cid}` is theirs; putting it in our click slot would 422 as `unknown_click`.

Paste URL (`postbackQuery()`):

```
https://{host}/postback/hangmyads?subid={subid}&offerid={offerid}&pay={pay}&goalname={goalname}&convid={convid}&offer_name={offer_name}
```

If they only support `{subid}`, that is enough to attribute; payout then has to arrive as `{pay}` or `{payout}` or we cannot price the conversion.

### What other sources say

| Source | Claim |
|---|---|
| hangmyads.com "S2S Postback" | They will "server call for every install and other post install events." No URL. |
| Offerwall docs | `subid2`/`subid3`/`subid4` "needed to be returned on the S2S Postback." |
| Swaarm HangMyTracking adapter (third party) | Recommended URL: `…/postback?click_id={subid}&event_id=Adv_GOAL_MACRO`. Token for our click is **`{subid}`**. |
| `getConversionDetails` field list | Pull-API names: `id`, `click_id`, `affiliate_subid`, `payout`, `goal_name`, `offer_id`, `click_ip`. Different spelling from the conversion-code macros. |

### What `parsePostback()` accepts today

| Incoming key | Required for us | Maps to |
|---|---|---|
| `subid` | yes, or `click_id`, or `affiliate_subid` | `clickId` → `offer_clicks.click_id` |
| `click_id` | fallback | same |
| `affiliate_subid` | fallback | same |
| `pay` | yes if no `payout` | USD. Negative → reversal. |
| `payout` | yes if no `pay` | **Cents**, divided by 100. Negative → reversal. |
| `offerid`, `oid`, or `offer_id` | no | `offerExternalId` |
| `goalname` or `goal_name` | no | `offerName`, and part of the composite tx id |
| `offer_name` | no | Fallback name if no goal |
| `convid`, `transaction_id`, or `conversion_id` | no | `networkTxId` if present |
| `click_ip` or `ip` | no | Conversion IP (no official macro) |
| everything else | no | Stored on `raw` |

Missing click id, or neither `pay` nor `payout` numeric → `parsePostback()` returns `null` → HTTP 422.

`{pay}` wins when both money fields arrive.

### Transaction id

`{convid}` is their unique conversion id. If `convid` / `transaction_id` / `conversion_id` arrives, that is used. Otherwise:

```
{clickId}:{offerId}:{goal}:{abs(payoutUsd)}
```

Why the composite is that wide: an offer can price several goals, and `allow_duplicates` lets one click convert more than once. Click + offer alone would drop the second fire as a duplicate. Amount is unsigned so a reversal produces the same key as its credit (`reverse()` looks up `network_txid`).

Ceiling: two identically-priced fires of the same goal on one click — the second is a duplicate. A live fire with `{convid}` removes that ceiling.

### Chargeback

ASSUMPTION: a reversal arrives as a negative `payout`, not a status flag. Reading it backwards would credit a chargeback. Confirm. If they send `status=rejected` / `pending` instead, `parsePostback()` does not know those words yet.

`conv_default_status` on the offer can be `pending`. A first fire that is held would need `status: pending` on our side. We do not map that today — every non-negative payout is `credited`.

### Allowlist

Seeded empty. Empty rejects every fire. Ask for the IPs. Do not guess.

---

## What we do not handle

- `getCategories`, `getOfferStatus`, `getOfferAds`, `getOfferURLs`, `getOfferTargeting`, `getTrackingLink`, `requestOfferAccess`, both reports.
- Auto-requesting private offers.
- Non-USD rows.
- `blocked_sources`.
- `monthly_cap` / `daily_cents_cap`.
- `installed` (their "hide if this user already completed it"). We hide on **our** approved `(end_user_id, offer_id)`.
- Offerwall JSON as a catalogue.
- Ad tags / MoPub.
- A signed postback. There is nothing to check.
- Pending conversions (`conv_default_status`).
- Docs-shaped `goals` arrays (integer keys). Only the keyed-object shape is mapped.

---

## Live-fire checklist

1. AM: API ID, API Token, **our egress IP** on their API allowlist, **their postback IPs**, postback URL + macros.
2. Network form: Test connection counts `data.total_records`. A green button with 0 offers and no error is a typo or a missing API allowlist — that path is a `NOK` and must stay red.
3. Sync: USD active rows with a tracking link appear. Paused / unapproved / EUR rows do not.
4. Click an offer: outbound URL contains `subid=<uuid>`, and does **not** contain `YOUR_CLICK_ID` / `REPLACE_WITH_GAID` / `REPLACE_WITH_IDFA`.
5. Their postback test: same uuid in `subid`, numeric `{pay}` (USD) or `{payout}` (cents), source IP on our allowlist. Expect `accepted`. A $15 fire arriving as `payout=15` is cents and credits $0.15 — that is `{pay}` missing, not a processor bug.
6. Read the raw `incoming_postbacks` row. Confirm `convid` filled and `pay` is dollars. Copy any unread key that is the real tx id.
7. Multi-goal: two fires, two `{goalname}`s, two credits.
8. Chargeback: negative `{pay}` / `{payout}` (if that is how they do it) lands on the same `network_txid`.
9. Embed (only if we frame their wall): `subid={sub_id}`, AM-confirmed `pub`/`pubid`. Repeat step 5 against the embed click.
10. `unknown_click` means `subid` was missing or not our UUID (or they sent `{cid}`). Fix the click template, not `ConversionProcessor`.
