# Adtogame (`adtogame`)

Working notes for Adtogame: catalogue fetch, tracking links, and partner postbacks. Driver: `app/Networks/Drivers/AdtogameDriver.php`. Fixture: `tests/Fixtures/Networks/adtogame-offers.json`. Seeder row: `adtogame` / [adtogame.com](https://adtogame.com).

Adtogame is **Everflow underneath**. The Affiliate API lives on `api.eflow.team`. Their AM (Eslam CPA, 2026-08-18) pointed at Everflow's public docs and gave two tracking slots of their own. The Everflow pages describe the platform; the AM note is the only Adtogame-specific contract.

Fetched 2026-08-18 from the live Mintlify docs (every Affiliate API sidebar group opened) plus the partner helpdesk. The old Intercom URL they pasted (`helpdesk.everflow.io/en/articles/4057176-…`) is **404**. The current article is [Managing Postbacks As A Partner](https://helpdesk.everflow.io/collaborator/managing-postbacks-as-a-partner). The old docs path `/docs/affiliate/` now redirects to `/api-reference/affiliate-overview`. The old `#find-all` hash is now **GET List Postbacks** (`GET /v1/affiliates/pixels`).

---

## Sources

| Page | URL | What it is |
|---|---|---|
| Affiliate API overview | [developers.everflow.io/api-reference/affiliate-overview](https://developers.everflow.io/api-reference/affiliate-overview) | Auth, base URL, EU host |
| llms.txt | [developers.everflow.io/llms.txt](https://developers.everflow.io/llms.txt) | Full page list; append `.md` to any docs URL |
| Authentication | […/user-guide/authentication](https://developers.everflow.io/user-guide/authentication) | Key types, header, 401 / 403 |
| Paging | […/user-guide/paging](https://developers.everflow.io/user-guide/paging) | `page` / `page_size` / `total_count` |
| Rate limiting | […/user-guide/rate-limiting](https://developers.everflow.io/user-guide/rate-limiting) | Affiliate bucket |
| Errors | […/user-guide/errors](https://developers.everflow.io/user-guide/errors) | Status codes + `{ "error": "…" }` |
| Request / response | […/user-guide/request-response-format](https://developers.everflow.io/user-guide/request-response-format) | Money, IDs, timestamps |
| Relationships | […/user-guide/relationships](https://developers.everflow.io/user-guide/relationships) | Nested `relationship` objects |
| Rulesets | […/user-guide/rulesets](https://developers.everflow.io/user-guide/rulesets) | Include / exclude targeting |
| Security | […/user-guide/security](https://developers.everflow.io/user-guide/security) | HTTPS, IP allowlists |
| List Runnable Offers | […/get-affiliatesoffersrunnable](https://developers.everflow.io/api-reference/get-affiliatesoffersrunnable) | Catalogue we pull |
| List Visible Offers | […/get-affiliatesalloffers](https://developers.everflow.io/api-reference/get-affiliatesalloffers) | Catalogue including not-yet-approved |
| Get Offer | […/get-affiliatesoffer](https://developers.everflow.io/api-reference/get-affiliatesoffer) | One offer + extra relationships |
| Get Tracking URL | […/get-affiliatesofferurl](https://developers.everflow.io/api-reference/get-affiliatesofferurl) | Fresh link for offer + URL id |
| Get Impression URL | […/get-affiliatesofferimpressionurl](https://developers.everflow.io/api-reference/get-affiliatesofferimpressionurl) | Impression pixel. We do not call it. |
| Create / Update / Get / List / Find Postbacks | […/post-affiliatespixels](https://developers.everflow.io/api-reference/post-affiliatespixels) and siblings | Partner pixel CRUD. API word is `pixel`. |
| Search Conversions | […/post-affiliatesreportingconversions](https://developers.everflow.io/api-reference/post-affiliatesreportingconversions) | Per-conversion dump with `sub1`–`sub10` |
| OpenAPI — offers | […/openapi/affiliate-offers.yaml](https://developers.everflow.io/openapi/affiliate-offers.yaml) | Machine schema for the catalogue |
| OpenAPI — postbacks | […/openapi/affiliate-postbacks.yaml](https://developers.everflow.io/openapi/affiliate-postbacks.yaml) | Machine schema for pixels |
| Parameters & macros | [helpdesk …/your-guide-to-parameters-macros](https://helpdesk.everflow.io/customer/your-guide-to-parameters-macros) | Full `{macro}` table. Updated **2026-05-18** (`sub6`–`sub10`) |
| Managing postbacks as a partner | [helpdesk …/managing-postbacks-as-a-partner](https://helpdesk.everflow.io/collaborator/managing-postbacks-as-a-partner) | Portal UI, retries, auto-deactivation |
| Intro to partner postbacks | [helpdesk …/introduction-to-partner-advertiser-postbacks](https://helpdesk.everflow.io/customer/introduction-to-partner-advertiser-postbacks) | One-shot fire, no re-fire on reject |
| Partner API keys | [helpdesk …/partner-api-keys-api-documents](https://helpdesk.everflow.io/customer/partner-api-keys-api-documents) | Where the key is created / shown |
| Everflow server IPs | [helpdesk …/everflow-server-ips](https://helpdesk.everflow.io/customer/everflow-server-ips) | Nine firing addresses |
| Reversal postbacks | [helpdesk …/reversal-postback-setup-…](https://helpdesk.everflow.io/customer/reversal-postback-setup-for-processing-canceled-transactions) | Advertiser → Everflow reversals. **Not** partner outbound. |
| Partner portal | Company → My Account → **API** tab, plus **Postbacks** | Where the key and the S2S URL live |
| Marketing site | [adtogame.com](https://adtogame.com) | Brand only. No API spec. |

Affiliate API sidebar groups on 2026-08-18 (every group opened):

| Group | Endpoints |
|---|---|
| Overview | Auth + base URL |
| Coupon Codes | `GET` List Coupon Codes |
| Deals | `POST` Search All Deals, `GET` Get Deal, `GET` Get Offer Deals |
| Encoding / Decoding | `POST` Encode IDs, `POST` Decode IDs |
| Invoices | `GET` Get Invoice, `POST` Find Invoices (Advanced) |
| Offers | `GET` Get Offer, `GET` Get Tracking URL, `GET` Get Impression URL, `GET` List Visible Offers, `GET` List Runnable Offers |
| Postbacks | `POST` Create, `PUT` Update, `GET` Get, `GET` List (Find All), `POST` Find (Advanced) |
| Product Feeds | search / get / download |
| Reporting | aggregated table + export, dashboard, get click, search/export/get conversions, on-hold, raw clicks stream |
| Traffic | get / list traffic controls, list blocked sources, list blocked variables |

We call **List Runnable Offers** only. The rest is here so a later postback or reporting change does not start from a blank page.

---

## Adtogame vs Everflow

Everflow's UI says **Partner**. The API path still says `/affiliates/`. Same thing. Marketplace (`/v1/partners/` on `partners.everflow.io`) is a different product and is not Adtogame.

Adtogame is one Everflow network. Our driver talks to their Affiliate API as that partner. A Network API key (`/v1/networks/…`) from their admin would be the wrong realm — HTTP **403** `Out of realm`.

EU-hosted Everflow accounts use `https://api-eu.eflow.team/v1`. Adtogame's catalogue host in the driver is `api.eflow.team`. Confirm with the AM before flipping.

---

## Credentials

Stored on `networks.credentials` (encrypted). Admin labels come from `credentialSchema()`.

| Our key | Their name | Required | Where they show it |
|---|---|---|---|
| `api_key` | Affiliate / Partner API key (`X-Eflow-Api-Key`) | yes | Partner Portal → Company → My Account → **API**. Created by *their* admin at Partners → Manage → [partner] → API → Add. Affiliates cannot mint their own key. |

Affiliate keys **stay visible** to the network admin who created them. If we lose ours, ask Adtogame to re-share it; they do not have to mint a new one.

There is no documented postback secret and no HMAC. `verifyPostback()` is a no-op. The only real check is `networks.postback_ips`, which **rejects when empty**. Paste the nine Everflow firing IPs (below) before the network is switched on.

Talk to the AM for: Partner API key, confirmation we are on `api.eflow.team` (not EU), permission to create a global S2S postback (some networks disable `POST /affiliates/pixels`), and a test fire that fills `sub2`.

---

## Transport

```
GET https://api.eflow.team/v1/affiliates/offersrunnable?page=1&page_size=100
X-Eflow-Api-Key: {api_key}
Accept: application/json
```

HTTPS only. Plain HTTP is rejected. JSON bodies on `POST` / `PUT` / `PATCH`. GET has no body.

Docs print the header as both `X-Eflow-API-Key` (user guide) and `X-Eflow-Api-Key` (OpenAPI / Try it). HTTP headers are case-insensitive. The driver sends `X-Eflow-Api-Key`.

Everflow rejects a bad or missing key with a **real HTTP status**, not a success envelope:

| Status | Body | Meaning |
|---|---|---|
| 401 | `{ "error": "No Authentication Method Found" }` or invalid/revoked key | Header missing or key dead |
| 403 | `{ "error": "Out of realm" }` | Affiliate key used on a Network path, or the reverse |
| 400 | `{ "error": "…" }` | Bad query / body |
| 404 | `{ "error": "…" }` | Unknown id |
| 429 | `{ "error": "…" }` | Rate limit. Retry with backoff. |
| 500 / 503 / 504 | `{ "error": "…" }` | Their side. Retry with backoff. |

`failed()` and `->throw()` are the whole of it. A driver that swallowed a 401 and yielded nothing would tell the sync job the network has no offers left, and the sweep would deactivate every Adtogame tile we have.

### Rate limit (Affiliate)

Shared **per network**, not per key.

| | |
|---|---|
| Sustained | **5 requests / second** |
| Burst | **20** |
| Headers | `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset` (unix seconds of next refill) |

Reporting with high-cardinality columns (geo, device, `sub1`–`sub5`, …) has a separate **1,000 queries / rolling hour** bucket, shared across every affiliate of that network. Catalogue GETs do not use it.

### Paging

Default `page=1`, `page_size=50`. Typical max page size **2,000**. Response:

```json
{
  "paging": {
    "page": 1,
    "page_size": 50,
    "total_count": 150
  }
}
```

The driver asks for `page_size=100` and stops when `seen >= paging.total_count`, or on an empty page if no total is given. Backstop `MAX_PAGES = 100` (10,000 offers at that size). `testConnection()` asks for one row and reports `total_count` (the whole catalogue, not the page).

POST search endpoints (`/pixelstable`, `/reporting/conversions`) also take `page` / `page_size` as **query** parameters, even though the rest of the filter is JSON. The user-guide example that puts them in the body is for Network reporting.

### Conventions

- Resource IDs are integers (`network_offer_id`). Currency IDs are ISO 4217 strings (`USD`).
- Money is **full currency units**, not cents. `25.00` is $25.
- Response timestamps are unix seconds (`time_created`, `time_saved`).
- `PUT` is a full replace. Omit a field and it resets. We do not PUT.
- Relationship lists often wrap as `{ "entries": […], "total": N }` instead of a bare array. Payouts and creatives do this. Category is a single object.

---

## List Runnable Offers (what we fetch)

```
GET /v1/affiliates/offersrunnable
```

"Returns offers the authenticated affiliate is authorized to run. This includes public offers and offers the affiliate has been approved for. Unlike `/affiliates/alloffers`, this only returns offers the affiliate can actively generate traffic for."

Full `relationship` on every row: category, creatives, payouts, ruleset, URLs, channels, remaining caps, reporting.

### Query

| Name | Default | Meaning |
|---|---|---|
| `page` | 1 | 1-based |
| `page_size` | 50 | Driver sends 100 |
| `search` | — | Filter by name or id. We do not send it. |

We pull the unfiltered catalogue and filter after storing.

### Sample envelope (from their Try-it block, 2026-08-18)

```json
{
  "offers": [
    {
      "network_offer_id": 123,
      "network_id": 123,
      "name": "<string>",
      "offer_status": "<string>",
      "currency_id": "<string>",
      "visibility": "<string>",
      "thumbnail_url": "<string>",
      "html_description": "<string>",
      "is_description_plain_text": true,
      "preview_url": "<string>",
      "tracking_url": "<string>",
      "redirect_tracking_url": "<string>",
      "impression_tracking_url": "<string>",
      "network_category_id": 123,
      "is_caps_enabled": true,
      "caps_timezone_id": 123,
      "daily_conversion_cap": 123,
      "weekly_conversion_cap": 123,
      "monthly_conversion_cap": 123,
      "global_conversion_cap": 123,
      "daily_click_cap": 123,
      "weekly_click_cap": 123,
      "monthly_click_cap": 123,
      "global_click_cap": 123,
      "daily_payout_cap": 123,
      "weekly_payout_cap": 123,
      "monthly_payout_cap": 123,
      "global_payout_cap": 123,
      "is_force_terms_and_conditions": true,
      "is_using_explicit_terms_and_conditions": true,
      "terms_and_conditions": "<string>",
      "is_use_direct_linking": true,
      "is_using_suppression_list": true,
      "suppression_list_id": 123,
      "network_tracking_domain_id": 123,
      "app_identifier": "<string>",
      "date_live_until": "<string>",
      "time_created": 123,
      "time_saved": 123,
      "relationship": {
        "offer_affiliate_status": "<string>",
        "blocked_variables_count": 123,
        "category": { "network_category_id": 123, "name": "<string>", "status": "<string>" },
        "channels": { "entries": [{ "network_channel_id": 123, "name": "<string>" }], "total": 1 },
        "creative_bundle": { "url": "<string>" },
        "creatives": { "entries": [{ "network_offer_creative_id": 123, "resource_url": "<string>" }], "total": 1 },
        "payouts": {
          "entries": [
            {
              "network_offer_payout_revenue_id": 123,
              "entry_name": "<string>",
              "payout_type": "<string>",
              "payout_amount": 123,
              "payout_percentage": 123,
              "is_default": true,
              "is_enforce_caps": true,
              "is_postback_disabled": true
            }
          ],
          "total": 1
        },
        "remaining_caps": {
          "remaining_daily_click_cap": 123,
          "remaining_daily_conversion_cap": 123,
          "remaining_daily_payout_cap": 123
        },
        "ruleset": {},
        "urls": { "entries": [{ "network_offer_url_id": 123, "name": "<string>", "preview_url": "<string>" }], "total": 1 }
      }
    }
  ],
  "paging": { "page": 1, "page_size": 50, "total_count": 150 }
}
```

### Offer row — what we do

| Their field | What we do |
|---|---|
| `network_offer_id` | `OfferDTO.externalId`. **Skipped if missing.** |
| `name` | Offer name. Fallback `"Untitled offer"`. |
| `offer_status` | Kept only when `active` (case-insensitive). Paused / deleted rows are skipped so the sync sweep deactivates them. |
| `currency_id` | Must be `USD`. Anything else is skipped. |
| `tracking_url` | Tracking link. We rewrite subid slots (see Tracking links). Missing link → skip. |
| `redirect_tracking_url` | **Not read.** |
| `impression_tracking_url` | **Not read.** |
| `html_description` | Flattened with `Plain::text()`. The wall escapes what it prints; stored raw it would show `<b>reach level 10</b>`. |
| `is_description_plain_text` | **Not read.** We flatten either way. |
| `thumbnail_url` | Icon. |
| `preview_url` | Preview. |
| `visibility` | **Not read.** Runnable already means we can send traffic. |
| `app_identifier` | **Not read.** |
| `date_live_until` | **Not read.** |
| `is_caps_enabled` + `*_cap` | **Not read.** Caps are theirs; we do not enforce them on the wall. |
| `relationship.payouts.entries` | Price + goals. See Payouts. |
| `relationship.ruleset.countries` | Include-only `country_code`. See Targeting. |
| `relationship.ruleset.platforms` | Include-only `platform`. See Targeting. |
| `relationship.category` | Wrapped in a one-item list; `categoryNames()` would otherwise file the offer under its id. |
| `relationship.offer_affiliate_status` | **Not read** on runnable (already authorized). On `/alloffers` the values are `approved`, `pending`, `not_applied`, `rejected`, `public`. |
| `relationship.creatives` / `urls` / `channels` / `remaining_caps` / `reporting` | **Not read.** |
| `relationship.payouts.entries[].is_postback_disabled` | **Not read.** An event that does not fire a partner postback can still look payable on the wall. Worth a later filter. |

---

## Payouts

`relationship.payouts` is a list, not a scalar. The base conversion and every extra event sit in `entries`. What the offer is worth to us is the **sum** of `payout_amount` on those entries. A revenue-share entry prices itself as `payout_percentage` with `payout_amount: 0` and contributes nothing — an offer that pays *only* revshare totals zero and is skipped (a wall slot that cannot earn either of us anything).

| Entry field | Meaning |
|---|---|
| `entry_name` | Snake_case event key. Empty on the base conversion. We label that `Base` and title-case the rest (`first_deposit` → `First Deposit`). |
| `payout_type` | `cpa` / `cpi` / `cps` / … Our `conversionType`. Unknown → `cpa`. Taken from the default entry. |
| `payout_amount` | Dollars per fire. Zero-amount entries are not goals. |
| `payout_percentage` | Revshare. Not added into the wall payout. |
| `is_default` | The entry the offer is priced on before anything fires. First entry if none is flagged. |
| `is_postback_disabled` | That event will not hit our URL. |
| `is_enforce_caps` | Cap applies to this event. |
| `network_offer_payout_revenue_id` | Event id on their side. `{event_id}` on the postback. **Not stored.** |

`isMultiReward` when more than one kept goal.

---

## Targeting (rulesets)

Every rule carries `targeting_type`: `include` or `exclude`. Read an exclude country as an include and the tile is aimed at exactly the places it may not run.

The driver keeps only includes. An exclude-only ruleset degrades to worldwide — `OfferDTO` has no "everywhere but here". Country field is `country_code` (ISO-2). Platform field is `platform` (`android`, `ios`, `desktop`, …). `parseDevices()` drops anything it does not know, which becomes "every device" (the DTO default), not wrong targeting.

Everflow also documents `regions`, `cities`, `dmas`, `postal_codes`, `browsers`, `device_types`, `brands`, `os_versions`, `isps`, `connection_types`, `ips`, `languages`, `is_block_proxy`, and day parting. We do not read those. Geo precedence on their side is City > DMA > ZIP > Region > Country.

---

## List Visible Offers (we do not call)

```
GET /v1/affiliates/alloffers?page=1&page_size=50&search=&category_id=
```

Same top-level offer fields, thinner `relationship` (mostly `offer_affiliate_status`). Includes public + require-approval offers we cannot run yet. `tracking_url` on those rows is often empty. We skip that shape on runnable already; pulling alloffers would just add pending tiles we cannot click.

Visibility enum: `public`, `require_approval`, `private`.

---

## Get Offer / Tracking URL / Impression URL

```
GET /v1/affiliates/offers/{offerId}
GET /v1/affiliates/offers/{offerId}/url/{urlId}
GET /v1/affiliates/offers/{offerId}/impressionurl/{urlId}
```

Get Offer is runnable plus `relationship.email`, `email_optout`, `integrations`. **404** if the offer is not visible to us.

Tracking URL: `urlId=0` for the default landing. **400** if we are not authorized. Response is `{ "url": "https://…" }`. Catalogue already ships `tracking_url`; we do not call this unless a later change needs a fresh link (extra landers live in `relationship.urls.entries[].network_offer_url_id`).

Impression URL is the same shape. We do not track impressions.

---

## Tracking links

Everflow tracking links are ordinary query strings. Anything we put on `sub1`–`sub10`, `source_id`, `user_id`, … is stored on the click and echoed back as `{sub1}` … on the partner postback. A parameter that was never on the click arrives **empty**, not as the literal `{sub1}`. A macro their dashboard does not know, pasted on *our* postback URL, arrives as the literal `{token}` — the driver already strips those.

### Adtogame AM (2026-08-18) — source of truth for slots

> sub1 – Your source id
> sub2 – Your click id

That is the opposite of generic Everflow copy, which says `sub1` is "commonly used for the partner's click ID". On this network we follow the AM.

| Slot | AM meaning | Everflow macro that echoes it | What should go on the click |
|---|---|---|---|
| `sub1` | Source id | `{sub1}` | Stable source / wall / placement id. **Not** the click UUID. |
| `sub2` | Click id | `{sub2}` | Our click UUID. This is how a conversion finds a click. |

`{source_id}` / `{affiliate_source_id}` is a separate Everflow slot (`source_id=` on the link). Do not confuse it with Adtogame's "source id" in `sub1`.

`{user_id}` is "Partner specific unique user ID associated with a user" — their end-user id, not a click id. `{transaction_id}` is **Everflow's** generated click id, not ours.

### What the driver does today

`withSubIdMacros()` writes the click id into **`sub1` and `user_id`**. It replaces an existing `sub1=` rather than appending a second one (a second `sub1=` is ignored by the tracker — conversion comes back with somebody else's subid). It does **not** put the click id on `sub2`. If the catalogue link already has `sub2=wall`, that value stays.

`parsePostback()` reads `user_id`, then `sub1`. It does **not** read `sub2`.

That is a mismatch with the AM. A live fire that follows their slots will put our UUID on `sub2` and a source string on `sub1`. The current parser would then treat the source string as the click id, match nothing, and drop the conversion. Fix the writer and the reader together when we next touch this driver: `sub2={click_id}`, and parse `sub2` first.

`user_id=` on the link is still useful if we want `{user_id}` on the postback as a fallback, but it is not what Adtogame asked for.

---

## Partner postbacks (we write the URL)

Everflow fires **out** to us. We do not invent the query string they happen to send — we paste a URL with **our** parameter names on the left and **their** macros on the right.

```
https://partner.com/postback?click_id={sub2}&sale_total={sale_amount}&payout={payout_amount}
```

`click_id` / `sale_total` / `payout` are ours. `{sub2}` / `{sale_amount}` / `{payout_amount}` are theirs.

### Portal (Partner Platform)

1. **Postbacks** in the nav.
2. **+ Postback**.
3. Type: Conversion / Event / CPC.
4. Scope: Global (all offers) or Specific (one offer).
5. Method: **Postback** (S2S). Not HTML pixel.
6. Paste the URL.

Some networks disable partner-created postbacks. Then `POST /affiliates/pixels` errors and the AM has to paste the URL on their side (Partners → Postbacks in the *network* UI).

API word is `pixel`. UI word is `postback`. `network_pixel_id` === `postback_id`. Edit screens live at `/affiliates/postbacks/{id}/edit`.

### Types / levels / methods

| API field | Values | Meaning |
|---|---|---|
| `pixel_type` | `conversion`, `post_conversion`, `cpc` | Conversion = base. `post_conversion` = Event in the UI (extra payouts). CPC = click. |
| `pixel_level` | `global`, `specific`, `global_offer` | Global = every offer. Specific needs `network_offer_id`. |
| `pixel_status` | `active`, `inactive` | |
| `delivery_method` | `postback`, `html`, `facebook`, `tiktok`, `snapchat`, `rumble` | We want `postback`. |
| `delay_ms` | 0–300000 | Max 5 minutes. Default 0. |
| `network_offer_payout_revenue_id` | int, 0 if not event-specific | Ties a pixel to one payout entry. |

For a wall that pays base + events we need **both** a Conversion postback and an Event (`post_conversion`) postback, or one global of each type. A conversion-only pixel does not fire for `first_deposit`.

`is_postback_disabled` on a payout entry wins: that event never fires even if a pixel exists.

### Recommended URL to paste

Ours to receive, theirs to fill. AM slots + every id we can key on:

```
https://{host}/postback/adtogame
  ?click_id={sub2}
  &source_id={sub1}
  &user_id={user_id}
  &transaction_id={transaction_id}
  &conversion_id={conversion_id}
  &campaign_id={offer_id}
  &campaign_name={offer_name}
  &event_id={event_id}
  &event_name={event_name}
  &payout={payout_amount}
  &currency={offer_currency}
  &ip={session_ip}
```

| Our query key | Macro | Why |
|---|---|---|
| `click_id` | `{sub2}` | AM: click id lives here. |
| `source_id` | `{sub1}` | AM: source id. |
| `user_id` | `{user_id}` | Fallback if we also put the UUID on `user_id=` of the link. |
| `transaction_id` | `{transaction_id}` | Everflow's click id. Stable, unique per click. Best idempotency key they actually own. |
| `conversion_id` | `{conversion_id}` | Unique per conversion / event fire. Better than a composite if it fills. |
| `campaign_id` | `{offer_id}` | `network_offer_id`. |
| `campaign_name` | `{offer_name}` | |
| `event_id` | `{event_id}` | Payout-revenue id. Separates multi-event fires. |
| `event_name` | `{event_name}` | |
| `payout` | `{payout_amount}` | Partner payout in **offer currency**. Official macro table. Partner helpdesk "common parameters" also lists `{payout}` as an alias — confirm on a test fire which one fills. **`{payout_usd}` is not an Everflow macro.** |
| `currency` | `{offer_currency}` | |
| `ip` | `{session_ip}` | Click IP. `{conversion_ip}` is the conversion IP. `{user_ip}` is documented as an *advertiser inbound* parameter whose macro is `{conversion_ip}` — do not put `{user_ip}` on a partner URL and expect a user IP. |

Macros are always `{curly_brackets}`. `{transaction_id}=click_id` (macro on the left) is the common paste error.

`{sale_amount}` and `{adv1}`–`{adv10}` on partner postbacks are **gated** by Control Center → Platform Configurations → Global → "Set Macro Parameter Visibility for Partners" (or a per-partner override). Empty until Adtogame turns them on.

Any parameter we sent on the tracking link can come back as `{that_name}`, including free-form names. Free-form ones do not appear in Everflow reporting.

Hashing suffix if we ever need it: `{sub2>SHA256}`, `{sub2>LC_MD5}`. We do not.

### Current driver comment vs this URL

The class docblock still tells admin to paste:

```
?user_id={user_id}&campaign_id={offer_id}&campaign_name={offer_name}&ip={user_ip}&payout={payout_usd}
```

Three of those macros are wrong or incomplete on Everflow: `{payout_usd}` does not exist, `{user_ip}` is the wrong side of the pipe, `{user_id}` is not the AM click slot. Parser still accepts `user_id` / `sub1` / `payout` / `campaign_id` / `event_name` / `transaction_id` / `conversion_id`. Align the paste URL, the writer, and the parser in one change.

### Delivery, retries, auto-deactivation

From [Managing Postbacks As A Partner](https://helpdesk.everflow.io/collaborator/managing-postbacks-as-a-partner), last updated 2026-05-18:

- Method **Postback** only (HTML / social are not auto-deactivated).
- **5 attempts** to reach us before they count one failure.
- Failure = no response or a non-2xx. **Any 2xx, including 204**, is success.
- Auto-deactivate when there are **≥ 10 consecutive failures** *and* the first-to-latest failure span is **> 72 hours**. A burst inside 72 hours stays active. Worked example: 114 failures in 20 hours stayed up; one more six days later crossed 72 hours and killed it.
- Super users + the AM are emailed on deactivation.
- Debug: Reporting → Postback → ⋮ → View Debug Info.
- A global pixel that requires a Facebook click id will fail on every other source and can deactivate itself. Prefer Specific if that happens.

Our controller should keep answering **200** on a handled postback. A 422 on a missing click id will be retried five times, then counted as a failure toward the 72-hour kill. That is the same trap Ayet documents (they retry 12 times / hour). Prefer 200 + log when the payload is structurally valid but we choose not to credit.

### One-shot — reversals do not come back

Partner postbacks fire **once**, when the conversion is first approved. They do **not** re-fire when:

- the payout is edited
- the conversion is rejected / charged back
- the conversion is approved again
- the conversion is reprocessed

The current driver treats a **negative `payout`** as a reversal. Everflow's partner docs never say they send a second fire with a negative amount. Advertiser *inbound* reversals exist (different URL, `order_id`, not `transaction_id`) and stop at Everflow; they do not automatically notify us.

So `status: reversed` from a negative payout is an **assumption**, not a documented contract. A rejected conversion on their side will sit credited on ours until someone notices. Options later: poll `POST /affiliates/reporting/conversions`, or ask the AM if they have a custom reject pixel.

On-hold conversions **do not** fire partner postbacks. They fire when approved.

If no pixel existed on first approve, then reject → add pixel → approve again, the second approve *does* fire. If a pixel already fired, the second approve does not.

### Find All / create via API

The AM linked `#find-all`. That is:

```
GET https://api.eflow.team/v1/affiliates/pixels
    ?network_offer_id=
    &pixel_status=active|inactive
    &pixel_type=conversion|post_conversion|cpc
    &delivery_method=postback|html|facebook
X-Eflow-Api-Key: {api_key}
```

Response: `{ "pixels": [ { network_pixel_id, postback_url, pixel_type, pixel_level, … } ] }`. No paging object on this GET. Use `POST /affiliates/pixelstable` when the list is long (`page` / `page_size` on the query string).

Create (may be disabled on this network):

```
POST /v1/affiliates/pixels
{
  "delivery_method": "postback",
  "pixel_level": "global",
  "pixel_status": "active",
  "pixel_type": "conversion",
  "postback_url": "https://{host}/postback/adtogame?click_id={sub2}&payout={payout_amount}&transaction_id={transaction_id}&conversion_id={conversion_id}&campaign_id={offer_id}&event_name={event_name}"
}
```

Repeat with `pixel_type: "post_conversion"` for events. `PUT /affiliates/pixels/{pixelId}` is a full replace. `GET /affiliates/pixels/{pixelId}` is one row. 400 on create if `delay_ms` > 300000 or a required field is missing.

Their own OpenAPI example uses `{revenue}` in the sample URL. `{revenue}` is **not** on the partner macro table. Partner money is `{payout_amount}`. `{revenue}` / `{amount}` are advertiser-side.

---

## Macros we actually care about (PP = partner postback)

Copied from the 2026-05-18 table. `PP` = usable on the URL we paste. `PTL` = usable on the tracking link.

| Macro | Surfaces | Meaning |
|---|---|---|
| `{sub1}` … `{sub10}` | PTL PP | Passthrough. Adtogame: `sub1` source, `sub2` click. |
| `{source_id}` / `{affiliate_source_id}` | PTL PP | Separate source slot. |
| `{user_id}` | AP PP | Partner user id. |
| `{transaction_id}` | DLP PP | Everflow click id. |
| `{conversion_id}` | PP | Unique conversion id. |
| `{offer_id}` / `{offer_name}` / `{offer_currency}` | DLP PP | Offer. |
| `{campaign_id}` | PP | Campaign associated to the click. Not the same as offer id. |
| `{event_id}` / `{event_name}` | PP (event_name PP only) | Extra event. |
| `{payout_amount}` | PP | Partner payout, offer currency. |
| `{payout}` | Partner helpdesk "common parameters" only | Listed as "your payout amount". Confirm vs `{payout_amount}`. |
| `{sale_amount}` | PP ** | Advertiser sale. Visibility toggle. |
| `{session_ip}` | DLP PP | Click IP. |
| `{conversion_ip}` | PP | Conversion IP. |
| `{datetime}` | DLP PP | `YYYY-MM-DD HH:MM:SS` UTC. |
| `{timestamp}` | AP PP | Unix UTC. |
| `{order_id}` | AP PP | Order id from advertiser. |
| `{affiliate_id}` / `{affiliate_name}` | DLP PP | Us, on their network. |
| `{geo_country_code}` | DLP PP | ISO-2. |
| `{device_platform}` / `{device_type}` | DLP PP | |
| `{user_agent}` | DLP AP PP | |
| `{random}` | AP PP | 100000000–999999999. |
| `{is_view_through}` | PP | Impression-attributed. |
| `{origin}` | PP | `click` or `impression`. |
| `{adv1}`–`{adv10}` | AP PP ** | Advertiser passthrough. Visibility toggle. |

`**` = network admin must enable partner visibility.

`{amount}` is **advertiser postback only**. `{adv_event_id}` is advertiser only.

---

## Firing IPs

From [Everflow Server IPs](https://helpdesk.everflow.io/customer/everflow-server-ips). Paste all nine onto `networks.postback_ips` (and any WAF in front). Last captured 2026-08-18:

```
35.196.95.104
34.138.191.250
34.138.95.128
34.91.131.78
34.147.37.169
34.147.67.230
34.73.83.118
35.227.72.33
34.74.122.67
```

Their article is written for *advertiser inbound* ("whitelist so Everflow can reach the advertiser") but these are the addresses Everflow S2S leaves from. Partner postbacks come from the same pool. A miss is a silent drop on our side — their report may still show 200 if a proxy ate it, or timeout / 403 if we blocked.

Adtogame may fire from a subset or from extra hosts. Confirm with a Debug Info row before going live. The controller **rejects an empty allowlist**, so an Adtogame row with no IPs earns nothing rather than trusting the world.

No signature. IP allowlist is the whole authentication.

---

## Search Conversions (reconciliation later)

```
POST /v1/affiliates/reporting/conversions?page=1&page_size=50
{
  "from": "2026-03-01",
  "to": "2026-03-31",
  "timezone_id": 67,
  "show_conversions": true,
  "show_events": true,
  "query": { "filters": [] }
}
```

`show_conversions` must be **explicitly `true`** or the list is empty. Date window max **365 days**. Each row has `conversion_id`, `transaction_id`, `sub1`–`sub10`, `source_id`, `event`, `is_event`, `revenue` (their name for *our* payout in this affiliate report), `sale_amount`, `session_user_ip`, `conversion_user_ip`, `relationship.offer`.

This is how we would notice a rejected conversion that never came back as a negative postback. Not wired up.

`GET /v1/affiliates/reporting/conversions/{conversionId}` is the same row. `GET /v1/affiliates/reporting/clicks?transaction_id=` (Get Click) is the click half.

Aggregated report: `POST /v1/affiliates/reporting/entity/table`. Affiliate-scoped; no affiliate filter. Max 10,000 rows. `revenue` in that table is the affiliate payout.

---

## What `parsePostback()` does today

| Incoming key | Role |
|---|---|
| `user_id` then `sub1` | Click id. Literal `{…}` ignored. |
| `payout` | Required numeric. `< 0` → reversed. |
| `campaign_id` / `offer_id` | Offer external id. |
| `event_name` | Goal name; also used as offer name when present. |
| `campaign_name` / `offer_name` | Fallback offer name. |
| `transaction_id` / `conversion_id` | Preferred `networkTxId`. Else `{click}:{offer}:{event}:{abs(payout)}`. |
| `ip` / `user_ip` | End-user IP. |

Unsigned amount in the composite key so a (hypothetical) negative fire lands on the same conversion. Two identically-priced unnamed events on one click still collide.

---

## Gaps to confirm on a live fire

1. AM slots: click id really comes back on `{sub2}`, source on `{sub1}`.
2. `{payout_amount}` vs `{payout}` — which fills.
3. Whether they ever send a second fire on reject (they document that they do not).
4. Whether `{conversion_id}` is unique per event on a multi-event offer.
5. Whether Adtogame allows `POST /affiliates/pixels` or the AM must paste the URL.
6. Whether they are on `api.eflow.team` or `api-eu.eflow.team`.
7. Whether all nine IPs appear in Debug Info, or a subset / extras.
8. `is_postback_disabled` events in the live catalogue.

---

## Unused Affiliate endpoints (inventory)

Kept so we do not rediscover them.

| Endpoint | Why we skip it |
|---|---|
| Coupon codes / deals / product feeds | Not wall inventory. |
| Encode / decode IDs | Offer/affiliate id obfuscation. Tracking links already work. |
| Invoices | Billing. |
| Impression URL | No impression tiles. |
| Create/Update pixel | Prefer the portal unless they ask us to provision in code. Feature can be disabled. |
| Aggregated / dashboard / clicks stream | Reporting, not sync. |
| On-hold conversions | No postback until approved. |
| Traffic controls / blocked sources / variables | Their blocks; we already skip unlinkable rows. |
