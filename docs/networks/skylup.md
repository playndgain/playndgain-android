# Skylup (`skylup`)

Working notes for Skylup: Swaarm Feed API catalogue, click tracking, and unsigned publisher postbacks. Driver: `app/Networks/Drivers/SkylupDriver.php`. Fixture: `tests/Fixtures/Networks/skylup-offers.json`. Seeder row: `skylup` / [skylup.co](https://www.skylup.co/). Logo: `public/img/networks/skylup.svg`.

Skylup is a growth agency / affiliate network. **Swaarm underneath.** The feed host is `feed.skylup.swaarm-clients.com`. Clicks go through `skylup.trckswrm.com`. Their in-house wall is **PixyLabs** — a product they sell to GPT sites, not a catalogue we pull. We talk to the Feed API as a publisher.

Captured 2026-08-31 from their live Swagger page, a full v1.3 dump (484 ads, publisher id `519`), and Swaarm's public publisher docs. The Swagger YAML they publish is **stale**: it still says v1.2 and `apiKey`. The link they handed us is v1.3 and `api_key`. Trust the live URL.

Their pages:

| Page | URL | What it is |
|---|---|---|
| Feed API (Swagger UI) | [feed.skylup.swaarm-clients.com/feed/docs/feed-api.html](https://feed.skylup.swaarm-clients.com/feed/docs/feed-api.html) | Loads `feed-api.yml`. Field list for `/ads`. |
| OpenAPI YAML | […/feed/docs/feed-api.yml](https://feed.skylup.swaarm-clients.com/feed/docs/feed-api.yml) | Marked **v1.2**, `basePath: /feed/v1.2`, query auth name `apiKey`. |
| PI / API link they sent | `GET /feed/v1.3/ads?api_key=…` | The catalogue we actually call. |
| Swaarm Feed API | [docs.swaarm.com …/5172218-feed-api](https://docs.swaarm.com/en/articles/5172218-feed-api) | Who sees an offer, configurator, debug URL. Network-admin side. |
| Publisher tracking details | [docs.swaarm.com …/4612464-publisher-tracking-details](https://docs.swaarm.com/en/articles/4612464-publisher-tracking-details) | Click params + the full `#{macro}` table. |
| Publisher postback setup | [docs.swaarm.com …/4676908-publisher-postback-setup](https://docs.swaarm.com/en/articles/4676908-publisher-postback-setup) | Where the AM pastes our URL. Status / Level / PEA. |
| Create a publisher | [docs.swaarm.com …/14321333-create-and-configure-a-publisher](https://docs.swaarm.com/en/articles/14321333-create-and-configure-a-publisher) | `pub_click_id` is required. Default fire = approved + passed. |
| Marketing | [skylup.co](https://www.skylup.co/) | Agency + PixyLabs. No API spec. |
| Swaarm case study | [swaarm.com/case-studies/…skylup…](https://swaarm.com/case-studies/how-skylup-cut-fraud-reversals-80-percent/) | Confirms Swaarm + Verisoul. Not a contract. |

We call **`GET /feed/v1.3/ads` only**. The rest is here so postback setup does not start from a blank page.

---

## Credentials

Stored on `networks.credentials` (encrypted). Admin labels come from `credentialSchema()`.

| Our key | Their name | Required | Where they show it |
|---|---|---|---|
| `api_key` | Feed API key / `api_key` | yes | Partner Platform → Settings → Feed API, or the AM. UUID-shaped. |

There is **no documented postback secret** and no HMAC on publisher outbound fires. `verifyPostback()` is a no-op. The only real check is `networks.postback_ips`, which **rejects when empty**. They publish no firing addresses — ask the AM and paste them, or leave any-source on until then.

`pub_id` is already baked into every `click_url` they ship (`pub_id=519` on this account). We do not store it separately.

Talk to the AM for: Feed API key, postback firing IPs, permission to save a global postback (Approved **and** Rejected), and a test fire that fills `pub_click_id`.

Do **not** commit the live key. It lives on the network row.

---

## Transport

Swagger prints `http` and `https`. The key rides in the query string, so the driver calls **`https://`**.

```
GET https://feed.skylup.swaarm-clients.com/feed/v1.3/ads?api_key={api_key}
```

One unpaginated call. There is no `page`, no `next`, no cursor. A `?page=1` we tried was ignored — same 484 rows. Live dump on 2026-08-31: **~1.6 MB**, ~1.3 s. `fetchOffers()` should use a 180s timeout (shared client is 30s). `testConnection()` can use the default client; this feed is not slow.

**Query name is `api_key`, not `apiKey`.** Swagger's `securityDefinitions.auth.name` is `apiKey`. Live v1.3 with `apiKey=` is HTTP **403** `Publisher does not exist.` — same body as a missing or garbage key. That is a refusal, not an empty catalogue.

`GET /feed/v1.2/ads?api_key=` also 200s (484 ads, ~1.46 MB). v1.3 adds `creative_packs`, `payoutPoints`, `status`, `tags`. We stay on **v1.3** because that is the URL they sent and because `status` / `tags` are drop signals.

User-Agent we send: `AdVault/1.0`. Not documented as required.

**Rate limit:** not published. A normal sync interval is tens of minutes. Do not add a second catalogue hop.

### Envelope

```json
{
  "ads": [ { "id": "34986" } ],
  "publisher": { "id": "519", "subSources": null }
}
```

| Field | Type | Meaning |
|---|---|---|
| `ads` | array | Offer objects. Missing / non-array is a hard failure. |
| `publisher.id` | string | Our publisher id on Skylup. Already on every `click_url`. |
| `publisher.subSources` | object \| null | Allowed / disallowed sub ids for the whole publisher. Live: `null`. **Not read.** |

A missing `ads` array, or HTTP 403, is a refusal. Reading that as an empty catalogue deactivates every Skylup offer we have — the collapse guard only catches a drop from above twenty.

### Errors

Swagger's `ErrorResponse` (`{ "errors": [ { "reason", "message" } ] }`) is **not** what v1.3 returns on a bad key.

| Status | Body | Meaning |
|---|---|---|
| 200 | `{ "ads": […], "publisher": {…} }` | Catalogue, including rows we will later skip. |
| 403 | `Publisher does not exist.` (plain text, 25 bytes) | Missing key, `apiKey` instead of `api_key`, or a dead key. |
| 400 | Swagger: `ErrorResponse` | Not seen live. Treat as a hard failure. |
| 401 | Swagger: `ErrorResponse` | Not seen live. 403 is what they actually send. |

`failed()` and `->throw()` are the whole of it. Surface the plain-text body; do not look for `errors[0].message` first.

`testConnection()` reports `count(ads)` as they sent it, including rows `fetchOffers()` will skip.

---

## Offer object

Money is **USD in the main denomination** (YAML: "dollars not cents"). Live `payout_currency` is `USD` on every row. `payout` is the **default-action** price and is **0 on 246 / 484** rows — the money is on `eventTypes`.

| Their field | In YAML | In live v1.3 | What we do |
|---|---|---|---|
| `id` | string | `"34986"` | `OfferDTO.externalId`. **Skipped if missing.** |
| `name` | string | `"Balls and Ropes: Cut 'n Bounce"` | Offer name. Fallback `"Untitled offer"`. |
| `offer_name` | — | always `null` | **Not read.** |
| `description` | string | HTML (`<p>…</p>`). 472 rows. Often `<p><br></p>` | Strip tags. Empty after strip → null. |
| `offer_description` | — | always `null` | **Not read.** |
| `additional_information` | — | short blurb, 477 rows | Instructions, first choice. |
| `kpi` | — | longer completion rules, 311 rows | Instructions fallback when `additional_information` is blank. |
| `app_description` / `app_title` | yes | always `null` | **Not read.** |
| `payout` | number, default action | `0` or e.g. `0.3375` | See Payout. Not always the tile price. |
| `payout_currency` | string | `"USD"` every row | Non-USD is **dropped**. Ledger is USD. |
| `payout_type` | — | always `null` | **Not read.** |
| `payoutPoints` | — | always `null` (v1.3 only) | **Not read.** Their points, not USD. |
| `leadflow` | `CPI` / `CPA` | `CPA` 350, `CPI` 116, `MULTI_REWARD` 18 | `conversionType`. See mapping. |
| `eventTypes` | array | 250 rows have some | Goals. See Events. |
| `click_url` | string | `https://skylup.trckswrm.com/click?offer_id={id}&pub_id={pub}` | Tracking URL. We append `pub_click_id`. **Dropped if empty.** |
| `preview_url` | string | store / web URL, 394 rows | Preview. |
| `app_id` | string | `com.…` / `id1624388008` / `"WEB"` | **Not read.** |
| `app_categories` | array | always `[]` | **Not read.** |
| `icons` | `{72,96,128,256}` | always `[]` | Dead. Use `creatives_url`. |
| `thumbs` | array | always `[]` | **Not read.** |
| `creatives_url` | — | PNG/WebP on their CDN, 473 rows | Icon. |
| `creative_packs` | — | v1.3 banners | **Not read.** We need one icon, not a pack. |
| `targeting` | object | always present | Countries + devices + `minOsVersion`. |
| `targeted_payouts` | array | always `null` | **Not read.** No live geo-priced rows. |
| `daily_leads` | integer | 140 rows | `dailyCap`. Non-numeric → `null`. |
| `daily_click_budget` | integer | 52 rows | **Not read.** No click-cap column. |
| `hourly_*` / `daily_impression_budget` | integer | rare / unused | **Not read.** |
| `status` | — | `"active"` every live row (v1.3) | Only `active` is stored. Missing status treated as active. |
| `tags` | — | v1.3. Almost every row has `OFFERWALL` | Categories as sent. `HIDDEN` / `No_Traffic` are drop flags. |
| `restrictions` | string | CSV of traffic rules, 480 rows | **Not read.** Publisher-facing, not user-facing. |
| `custom_restrictions` | — | always `null` | **Not read.** |
| `subSources` | object | `{ allowedSubIds, disallowedSubIds }` both null | **Not read.** |
| `impression_url` | string | 12 rows | **Not read.** |
| `revenue_share_offer` | — | `true` on 5 survey aggregators | **Not read** as a flag; those rows already fail the $0 / no-events drop. |
| `landing_page_html_templates` | — | always `[]` | **Not read.** |
| `margin` / `phantom` / `rating` | — | always `null` | **Not read.** |

**Drop rules** (not stored, so a later sync deactivates them):

- no `id`
- `click_url` null or empty
- `payout_currency` set and not `USD`
- `status` present and not `active`
- tag `HIDDEN`
- tag `No_Traffic`
- no payable price: `payout == 0` **and** no MONETIZATION event with a name (the four HIDDEN survey rows)

### Payout

YAML: `payout` is "the total payout for the default action of the offer". Live that default is often **$0** while `eventTypes` hold the real money (Pop Block Puzzle: `payout: 0`, eleven events summing to `$9.6525`).

The other direction also exists: Soccer Referee Simulator is `$0.8925` with one QUALITY event (`payout: null`). Hero Wars is `$0.0075` plus twelve MONETIZATION events summing to `$84.90`. Using `payout` as the tile price would show a $0.0075 Hero Wars.

Rule:

1. Build `goals` from MONETIZATION events (below).
2. If `goals` is non-empty → `OfferDTO.payout = sum(goals)`. Do **not** add the default `payout` as a fake "Install" step (Hero Wars' `$0.0075` is noise next to `$84.90`).
3. If `goals` is empty → `OfferDTO.payout = (float) ad.payout`. Skip the row when that is `0`.

`isMultiReward` is `count(goals) > 1`. `leadflow: MULTI_REWARD` is **not** the signal — Testaankoop is `MULTI_REWARD` with zero events and a `$13.50` default; Deezer is `MULTI_REWARD` with one `$3.60` event.

### Events (`eventTypes[]`)

```json
{
  "id": "16407",
  "name": "Complete Level 20",
  "classification": "MONETIZATION",
  "payout": { "currency": "USD", "value": 0.015, "margin": null, "points": null },
  "budgets": [],
  "targetedPayouts": null
}
```

| Their field | What we do |
|---|---|
| `id` | **Not stored** on the offer. Arrives on the postback as `#{offer.eventTypeId}`. |
| `name` | Goal name. Blank name drops the step. |
| `description` | **Not read.** `name` is enough. |
| `classification` | `MONETIZATION` → keep. `QUALITY` → **drop** (live payout is `null`; 120 QUALITY events, Monopoly Go! has nine of them). |
| `payout.value` | Goal payout, USD. Missing → `0`. A named MONETIZATION step at `$0` is kept. |
| `payout.currency` | Non-USD step dropped. Live MONETIZATION currency is `USD`. |
| `budgets` / `targetedPayouts` / `advancedSettings` | **Not read.** |

Live histogram: 234 ads with no events, 114 with one, then a long tail (6, 2, 7, 4, …). Classification mix: 1438 MONETIZATION, 120 QUALITY.

### `leadflow` → our `conversionType`

| They send | Our enum | Why |
|---|---|---|
| `CPI` | `cpi` | Direct match. |
| `CPA` | `cpa` | Direct match. |
| `MULTI_REWARD` | `cpa` | Not in our enum. Multi-ness is `isMultiReward`, not the type. |
| anything else / missing | `cpa` | Same. |

### Targeting

```json
{
  "allowedDeviceTargeting": { "os": "ANDROID", "minOsVersion": "7.0.0", "maxOsVersion": null, "deviceBrand": null },
  "allowedGeoTargeting": { "countries": ["US"], "regions": [], "cities": [] },
  "disallowedDeviceTargeting": { "os": null, "minOsVersion": null, "maxOsVersion": null, "deviceBrand": null },
  "disallowedGeoTargeting": { "countries": null, "regions": [], "cities": [] },
  "advancedTargeting": null
}
```

Live OS allow: `WEB` 179, `ANDROID` 165, `IOS` 127, `CHROME OS` 9, `MACOS` 4. Disallow OS is always null. Disallow countries is always empty. `deviceBrand` is always null.

| They send | We store |
|---|---|
| `ANDROID` | `android` |
| `IOS` | `ios` |
| `WEB` | `[]` (every device) — see trap below |
| `CHROME OS` | `desktop` |
| `MACOS` | `desktop` |
| missing / null | `[]` |

**`WEB` trap.** `parseDevices()` maps any token containing `web` to `desktop`. Skylup `WEB` is 37% of the catalogue (surveys, subscriptions, Whatnot). Passing it through unchanged stores `desktop` and hides those tiles from phones. Treat `WEB` as all devices **before** parse.

**`CHROME OS` / `MACOS` trap.** `parseDevices()` does not know them. Left raw they store `[]` = every device, so a Chrome-OS survey is shown to iPhones. Alias to `desktop` before parse.

`minOsVersion` → `osMinVersion`. Live values include `"7.0.0"`, `"16.0.0"`, `"145.0.0"` (Chrome), `"None"`-shaped empties. Blank → `null`.

**Countries.** `allowedGeoTargeting.countries` is ISO-2. Empty after parse = worldwide. Eight live rows list 246–250 countries (survey aggregators + a couple of games) — treat **200+** codes as worldwide (`[]`) so the wall key stays small. `disallowedGeoTargeting` has nowhere to live; it is empty today. `UK` was not seen; if it appears, alias to `GB` before `parseCountries()` (same trap as GemiAd / Clickwall).

### Categories

`tags` as sent: `OFFERWALL`, `FREE`, `NO_CC`, `GAMING_APP`, `PREMIUM`, `CC_REQUIRED`, `UNDER_5MIN`, `GAMBLING_APP`, `SIGNUP_ONLY`, … Stored verbatim after the drop-tag filter. `HIDDEN` and `No_Traffic` never become labels because those rows are dropped.

---

## Clicks (our Offers tab)

Catalogue link they ship (every live row, same host):

```
https://skylup.trckswrm.com/click?offer_id=34986&pub_id=519
```

No `pub_click_id` on the feed URL. Swaarm: without it they "send empty click id value, which will lead to the incorrect postback attribution at Publisher's side."

Stored tracking URL:

```
{their click_url}&pub_click_id={click_id}
```

If `{click_id}` is still missing after that, append it. `buildTrackingUrl()` runs the same helper, then substitutes the UUID.

### Click query params (Swaarm publisher tracking)

| Param | Required on their side | Meaning |
|---|---|---|
| `offer_id` | yes | Already on the URL they send. |
| `pub_id` | yes | Already on the URL they send. |
| `pub_click_id` | **yes for us** | Our click UUID. Echoed as `#{click.publisher.clickId}`. |
| `pub_sub_id` | recommended by them | We do not set it. |
| `gaid` / `idfa` | no | Optional device ids. We do not send placeholders. |
| `app` / `creative` / `site` / `user_id` / `unique1`–`unique5` | no | Unused. |

Do not put `api_key` on the click URL.

Impression path is `/impression` with the same params. We do not call it.

---

## Hosted wall (PixyLabs) — not used

[skylup.co](https://www.skylup.co/) sells PixyLabs as their GPT offerwall. There is **no** public iframe URL, placement id, or `userId` slot in the Feed API docs. We do not seed `hosts_wall`. Integration starts as **API**. If an AM later hands us a PixyLabs embed, that is a separate paste into **Networks → Hosts its own wall** — and whatever slot they echo must be `{sub_id}`, not `{user_id}`.

Swaarm also has a Rewarded GraphQL offerwall. That is a different product. Not this feed.

---

## Postback

The contract is a URL **we** write. They GET whatever is saved on the publisher (Partner Platform → Postback Links, or the AM). Query keys below are ours because we chose them. Brace tokens must be **theirs**, including the leading `#`.

```
https://{host}/postback/skylup
```

### URL to paste

```
https://{host}/postback/skylup?click_id=#{click.publisher.clickId}&payout=#{payout.theyGetInDollars}&txid=#{id}&status=#{status.state}&offer_id=#{offer.offerId}&offer_name=#{offer.name}&event_id=#{offer.eventTypeId}&event_name=#{eventType.name}&ip=#{click.user.connection.ip}
```

| Macro | Why |
|---|---|
| `#{click.publisher.clickId}` | What we put in `pub_click_id`. This is `PostbackDTO.clickId`. |
| `#{payout.theyGetInDollars}` | USD they pay us, 2 dp. Ledger. **Not** `theyGetInCents`, **not** `theyGetPoints`. |
| `#{id}` | Swaarm unique postback id. Candidate for `networkTxId`. |
| `#{status.state}` | `APPROVED` / `REJECTED` (and `PENDING` if they enable it). |
| `#{offer.offerId}` | Catalogue `id`. |
| `#{offer.name}` | Snapshot label. |
| `#{offer.eventTypeId}` / `#{eventType.name}` | Which goal fired. Empty on the default event. |
| `#{click.user.connection.ip}` | End-user IP. Unavailable on PEA fires. |

**Do not** map `#{payout.theyGetPoints}` — rewarded-user coins. **Do not** map `#{click.id}` — that is *their* click id; putting it in our click slot 422s as `unknown_click`.

`#` is part of the macro. Swaarm will not substitute if it is stripped. Their dashboard replaces the whole `#{…}` token before the GET, so the `#` never arrives here as a URL fragment.

### Settings the AM must save

Swaarm default: fire only on status **Approved** and decision **Passed**, level **Default** (install only). For a multi-event catalogue that is not enough.

| Setting | Value we need |
|---|---|
| Offer | empty (global) |
| Event | Default |
| Level | **Default + Event** (their UI: Both) |
| Status | **Approved** and **Rejected** (Pending only if we decide to hold) |
| Attribution | Click ID + PEA Chain (their default) |
| Send Only Paid | off — a $0 QUALITY fire should not be the only signal we missed; paid events still have a payout |

Ask them to enable Rejected. Otherwise chargebacks never arrive.

### HTTP

- Method: **GET** (assumed — Swaarm publisher postbacks are URL substitution; method not spelled out as POST).
- Source IP: **not published.** Empty `postback_ips` rejects at the controller. Seed empty; any-source stays on until an AM list is pasted.
- Our response: HTTP 200. Body is not specified; `ok` matches the strictest partner contract.
- Signature: none.
- Retries: not published.

`verifyPostback()` returns `true`. Safe only after the allowlist is filled **or** any-source is on.

### What `parsePostback()` should read

| Incoming key | Their macro | Required for us | Maps to |
|---|---|---|---|
| `click_id` | `#{click.publisher.clickId}` | yes | `clickId` → `offer_clicks.click_id` |
| `payout` | `#{payout.theyGetInDollars}` | yes, numeric | USD. Negative → reversal. |
| `txid` | `#{id}` | no | See Transaction id |
| `status` | `#{status.state}` | no | `REJECTED` → reversal. `PENDING` → `pending`. Else `credited` if payout ≥ 0 |
| `offer_id` | `#{offer.offerId}` | no | `offerExternalId` |
| `event_name` | `#{eventType.name}` | no | `offerName` when present |
| `offer_name` | `#{offer.name}` | no | Fallback name |
| `event_id` | `#{offer.eventTypeId}` | no | Part of the composite tx id |
| `ip` | `#{click.user.connection.ip}` | no | Conversion IP |
| everything else | — | no | Stored on `raw` |

Missing `click_id`, or non-numeric `payout` → `parsePostback()` returns `null` → HTTP 422.

`theyGetInDollars` wins. If a live fire ever arrives with only cents, that is a wrong paste, not a parser bug.

### Status / chargeback

| `status` | Payout | Our `PostbackDTO.status` |
|---|---|---|
| `APPROVED` / missing / unknown | ≥ 0 | `credited` |
| `REJECTED` | any | `reversed` |
| `PENDING` | any | `pending` |
| any | < 0 | `reversed` |

Either `REJECTED` or a negative payout is enough. Reading this backwards credits a chargeback.

Default Swaarm does not fire Rejected. Until the AM flips that, we will not see chargebacks.

### Transaction id

`#{id}` is unique **per postback**. ASSUMPTION: a REJECTED fire **mints a new** `#{id}`, because that is what "unique postback ID" means. `reverse()` looks up `network_txid`, so using `txid` alone would credit the reject as a new conversion and never find the original.

Use a composite that is the same on credit and reject:

```
{clickId}:{offerId}:{eventId or 'default'}
```

`#{id}` still lands on `raw` so a live fire can prove or kill this. If they reuse `#{id}` on the reject, switch the key to `txid` and drop the composite — a reused id is a better key.

Ceiling: two identically-keyed fires of the same event on one click — the second is a duplicate. That is correct for Swaarm's usual "one conversion per click per event" model.

### Allowlist

Seeded empty. Empty rejects every fire unless any-source is on. Ask for the IPs. Do not guess Swaarm egress — it is not on the public publisher pages.

---

## What we do not handle

- v1.2 (stale Swagger path).
- Feed filters (Swaarm configurator is on *their* side; the dump is already scoped).
- `targeted_payouts` / per-event `targetedPayouts`.
- `icons` / `thumbs` / `creative_packs` (use `creatives_url`).
- `daily_click_budget`, hourly budgets, impression budgets.
- `restrictions` / `custom_restrictions` / `subSources`.
- `impression_url`.
- `revenue_share_offer` as a first-class type (rows are unpriceable anyway).
- QUALITY events as goals.
- `HIDDEN` / `No_Traffic` rows.
- PixyLabs / Rewarded GraphQL as a catalogue or an embed (no URL).
- A signed postback. There is nothing to check.
- PEA-only fires with an empty `click_id` — those 422 until we have a PEA path, which we do not.

---

## Live-fire checklist

1. Network form: API key only. Test connection counts `ads`. **403 `Publisher does not exist.`** = wrong query name or a dead key, not "zero offers".
2. Confirm the request is `api_key=` on **v1.3** over HTTPS.
3. AM: paste the postback URL **exactly**, including `#{…}` (do not strip `#`). Level = Default+Event. Status = Approved+Rejected.
4. Ask for firing IPs. Paste them and turn any-source off — or leave any-source on until they exist.
5. Sync: USD active rows with a click URL and a price appear. HIDDEN / No_Traffic / $0-with-no-events do not. WEB offers are visible on phones. CHROME OS / MACOS are desktop-only.
6. Click an offer: outbound URL contains `pub_click_id=<uuid>` and does **not** contain a leftover `{click_id}`.
7. Their postback test: same uuid in `click_id`, numeric `payout` in **dollars**, source IP acceptable. Expect `accepted`.
8. `unknown_click` means `pub_click_id` was missing on the click, or they echoed `#{click.id}` instead of `#{click.publisher.clickId}`. Fix the template, not `ConversionProcessor`.
9. Multi-event: two fires, two `event_id`s, two credits.
10. Chargeback: `status=REJECTED` (and/or negative `payout`) lands on the same composite `network_txid` as the credit. If `txid` is reused instead, switch the key.
11. Default-event fire: `event_id` / `event_name` empty, still attributes on `click_id`.
