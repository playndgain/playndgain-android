# GemiAd (`gemiad`)

Working notes for GemiAd / GemiWall: hosted offerwall, static catalogue, click tracking, and signed postbacks. Driver: `app/Networks/Drivers/GemiAdDriver.php`. Fixture: `tests/Fixtures/Networks/gemiad-offers.json`.

Captured 2026-08-18 from the publisher dashboard Documentation (three sidebar sections: Offerwall Integration, API Offers, Postbacks). The public marketing site is [gemiad.com](https://gemiad.com/). The wall and API live on `gemiwall.com` / `api.gemiwall.com`. The dashboard docs themselves are login-gated — the public wall origin only returns a loader — so this file is the copy we work from.

Their pages:

| Page | Where | What it is |
|---|---|---|
| Offerwall Integration | Publisher dashboard → Documentation | Hosted wall URL (path or query) and iframe |
| API Offers | Publisher dashboard → Documentation | `GET /api/offers/static`, field list, errors |
| Postbacks | Publisher dashboard → Documentation | Macro URL, hash recipe, reversals, retries |
| Placement settings | Publisher dashboard | Where `placementId`, API key, and postback URL are set |
| Profile Settings | Publisher dashboard | Secret key that signs `{HASH}` |
| Reports → Postbacks | Publisher dashboard | Live postback log for debugging |
| Brand assets | Publisher dashboard (marked NEW) | Logos / icons. Not used by the driver. |
| Marketing site | [gemiad.com](https://gemiad.com/) | GemiWall product, no API spec |
| Contact | [gemiad.com/contact](https://gemiad.com/contact/) | 24h email, Microsoft Teams channel |

---

## Credentials

Stored on `networks.credentials` (encrypted). Admin labels come from `credentialSchema()`.

| Our key | Their name | Required | Where they show it |
|---|---|---|---|
| `placement_id` | `PLACEMENT_ID` / `placementId` | yes | Dashboard. Unique per placement. |
| `api_key` | `apiKey` | yes | Placement settings. Catalogue auth only. |
| `secret_key` | Secret key | yes | **Profile Settings**, not the placement. Signs postbacks. |

The two secrets are not interchangeable. Pasting the API key into `secret_key` makes every hash fail. Pasting the profile secret into `api_key` makes every catalogue call a 401.

There is no documented expiry on either key.

---

## Transport

Docs print the catalogue as **plain `http://`**. The API key rides in the query string, so the driver calls **`https://`**. If they stop terminating TLS, `testConnection()` fails at the admin button rather than leaking the key on every hop.

```
GET https://api.gemiwall.com/api/offers/static?placementId={placement_id}&apiKey={api_key}
```

One placement is one unpaginated call. There is no `page`, no `next`, no cursor.

**Rate limit:** maximum **5 requests per minute per placement**. A sixth returns HTTP **429**. A normal sync interval is tens of minutes, so the only way to trip this is leaning on Test connection. Do not add a second catalogue hop.

User-Agent we send: `AdVault/1.0`. Not documented as required.

---

## Offerwall Integration (their hosted wall)

Separate product from the API. They host the tiles; we frame them. Conversions still come back on the same postback.

### URL formats

They publish two equivalent shapes. Path is marked **Recommended**.

**Option 1 — path parameters (recommended)**

```
https://gemiwall.com/{PLACEMENT_ID}/{USER_ID}
```

**Option 2 — query parameters**

```
https://gemiwall.com?placementId={PLACEMENT_ID}&userId={USER_ID}
```

| Parameter | Required | Their meaning |
|---|---|---|
| `PLACEMENT_ID` / `placementId` | yes | Placement ID from the dashboard |
| `USER_ID` / `userId` | yes | "Your user's unique identifier (must be unique per user)" |

The offerwall page documents **no** `sub1` / `sub2` on the iframe URL. The only slot that comes back on `{USER_ID}` is whatever we put in `userId`.

### Iframe they print

Path:

```html
<iframe
  src="https://gemiwall.com/{PLACEMENT_ID}/{USER_ID}"
  width="100%"
  height="600px"
  frameborder="0"
  style="border: none; border-radius: 8px;"
></iframe>
```

Query:

```html
<iframe
  src="https://gemiwall.com?placementId={PLACEMENT_ID}&userId={USER_ID}"
  width="100%"
  height="600px"
  frameborder="0"
  style="border: none; border-radius: 8px;"
></iframe>
```

### Best practices they list

- Always use HTTPS.
- `USER_ID` must be unique for each user.
- Set iframe dimensions to the layout. They suggest responsive height (`100vh` for full viewport).
- Brand assets are offered separately if we want to show their logo on our site.

### What we paste (partner wall)

Their offerwall copy says `USER_ID` is a **stable** site user. Their API copy says the same slot accepts a **clickId or userId**. Our postback reads `userId` as `PostbackDTO.clickId` and looks it up on `offer_clicks.click_id`. A stable publisher userid in that slot is an `unknown_click` on every wall conversion.

There is no second iframe parameter to carry `{sub_id}`. The click id has to go in `userId`.

Paste this into **Networks → Hosts its own wall → Partner wall URL**:

```
https://gemiwall.com/{placement_id}/{sub_id}
```

or, same values as query:

```
https://gemiwall.com?placementId={placement_id}&userId={sub_id}
```

Replace `{placement_id}` with the real placement id (plaintext in the wall URL — that is not a secret). `{sub_id}` is our embed click UUID.

| Their key | Our macro | Why |
|---|---|---|
| `userId` / path user | `{sub_id}` | Embed click UUID. This is `PostbackDTO.clickId`. |
| `placementId` | hard-coded | Not a secret, but not one of our wall macros either. |

Do **not** put `{user_id}` in their `userId` if we need per-open attribution. Their offerwall text allows a stable user; our embed row is one UUID per open. Do **not** put `api_key` or `secret_key` on this URL — it is rendered into the wall HTML.

Opening (`?open=1`) writes one embed `offer_clicks` row (`offer_id` null). Its `click_id` is `{sub_id}`.

`wall_new_tab` is for a partner whose `frame-ancestors` refuses us. Confirm whether GemiWall allows framing before leaving that off.

---

## API Offers — fetching

"Fetch offers from your server and integrate into your application."

```
GET https://api.gemiwall.com/api/offers/static
```

Marked: GET request, API key required.

### Query parameters

| Name | Required | Meaning |
|---|---|---|
| `placementId` | yes | Placement ID from the dashboard |
| `apiKey` | yes | API key from the placement settings |

No other filters are documented. We do not send country, device, or user. We store the whole catalogue and filter on our side.

`testConnection()` is the same GET. A green button reports `count(offers)` (the array length, including rows we will later skip — the fixture's id-less third row is counted here and dropped in `fetchOffers()`).

`fetchOffers()` uses a 180s timeout on this one call (the shared client is 30s). One placement's catalogue is expected to fit in memory. If one stops fitting, stream the `offers` array.

### Envelope

```json
{
  "success": true,
  "offers": [ { "id": "offer123" } ]
}
```

| Field | Type | Meaning |
|---|---|---|
| `success` | boolean | Whether the request worked |
| `offers` | array | Offer objects for this placement |

`success !== true` or a non-array `offers` is a hard failure. Reading that as an empty catalogue deactivates every GemiAd offer we have — the collapse guard only catches a drop from above twenty. The reason is written to `last_sync_error`.

Error bodies are not specified beyond HTTP status. The driver surfaces `error` or `message` from the JSON if present.

### Error responses they list

| Status | Meaning |
|---|---|
| 400 Bad Request | Missing or invalid `placementId` |
| 401 Unauthorized | Invalid API key |
| 404 Not Found | Placement not found **or** publisher inactive |
| 429 Too Many Requests | Rate limit (max 5 requests / minute) |

### Response example they print

```json
{
  "success": true,
  "offers": [
    {
      "id": "offer123",
      "name": "Download App",
      "description": {
        "en": "Download and install the app to earn rewards"
      },
      "payout": 150.5,
      "events": [
        {
          "eventId": "event1",
          "action": {
            "en": "Install and open the app"
          },
          "payout": 50
        },
        {
          "eventId": "event2",
          "action": {
            "en": "Complete tutorial"
          },
          "payout": 100.5
        }
      ],
      "multiEvent": true,
      "multiComplete": false,
      "trackingType": "CPI",
      "previewUrl": "https://example.com/preview",
      "video": "https://example.com/video.mp4",
      "banner": "https://example.com/banner.jpg",
      "icon": "https://example.com/icon.png",
      "category": "app",
      "country": ["US", "UK", "CA"],
      "url": "https://api.gemiwall.com/api/offers/click?offerId=offer123&userId=[USER_ID]&placementId=placement123",
      "device": ["android", "ios"],
      "dailyCap": 1000,
      "dailyClickCap": 5000,
      "epc": 0.25,
      "cvr": 12.5
    }
  ]
}
```

Their own example payout (150.5) equals the sum of the two event payouts (50 + 100.5). The offer `payout` is documented as the **total** for completing the offer and stays the authority. We do not recompute it from `events`.

---

## Offer object

Money is **USD** everywhere on the catalogue. No other currency is documented.

Localized strings are objects keyed by language (`{ "en": "…" }`). English only is documented. The wall has no other locale to fall back to, so we read `.en` and ignore the rest.

| Their field | Type | Their description | What we do |
|---|---|---|---|
| `id` | string | Unique offer identifier | `OfferDTO.externalId`. **Skipped if missing.** |
| `name` | string | Display name | Offer name. Fallback `"Untitled offer"`. |
| `description` | object | Localized descriptions, e.g. `en: "text"` | `description.en` only. |
| `payout` | number | Total reward for completing the offer (USD) | Offer payout. Authority over event totals. |
| `events` | array | Events the user must complete | Goals. See Events. Non-array entries dropped. |
| `multiEvent` | boolean | `true` = more than one payable event; `false` = one | **Not read.** We set `isMultiReward` from `count(events) > 1`, which also counts a zero-payout intermediate step. |
| `multiComplete` | boolean | `true` = user may complete this offer as many times as they want; `false` = once per user | **Not read.** We hide on our approved `(end_user_id, offer_id)`, which treats a `multiComplete` offer as one-and-done on the wall. |
| `trackingType` | string | `CPI` / `CPE` / `CPA` / `CPC` / `CPL` | `conversionType`. See mapping below. |
| `previewUrl` | string \| null | Preview URL (optional) | Preview. |
| `video` | string \| null | Promotional video (optional) | **Not read.** No video column. |
| `banner` | string \| null | Banner image (optional) | **Not read.** We use `icon`. |
| `icon` | string | Offer icon / logo | Icon. |
| `category` | string | `app`, `web`, `survey`, `click`, or `game` | One category name, stored as they sent it. |
| `country` | array | Country codes, e.g. `["US", "UK", "CA"]` | ISO-2. `UK` → `GB` before parse. `[]` after parse = worldwide. |
| `url` | string | Click tracking URL. Replace `[USER_ID]` with clickId/userId. Optional `&sub1=` (sourceId) and `&sub2=` (subsourceId). | Tracking URL. `[USER_ID]` → `{click_id}`. If the macro is still missing, `&userId={click_id}` is appended. |
| `device` | array | `android`, `ios`, `desktop` | Device targeting. |
| `dailyCap` | number \| null | Max daily conversions (`null` = unlimited) | Daily cap. Non-numeric → `null`. |
| `dailyClickCap` | number \| null | Max daily clicks (`null` = unlimited) | **Not read.** We have no click-cap column. |
| `epc` | number | Earnings per click, USD | **Not read.** We rank on our own click history. |
| `cvr` | number | Conversion rate as a percent (12.5 = 12.5%) | **Not read.** |

Their country table has a missing comma in one place: `["US", "UK" "TR"]`. Trust the typed array, not that sentence.

### `UK` → `GB`

`UK` is not ISO-3166-1 alpha-2. Their own sample uses it. `parseCountries()` keeps any two-letter token, so stored as-is it targets a country nothing geolocates to and every British user is filtered out of an offer bought for them. Alias is applied **before** the ISO filter.

### `trackingType` → our `conversionType`

| They send | Our enum | Why |
|---|---|---|
| `CPI` | `cpi` | Direct match. |
| `CPA` | `cpa` | Direct match. |
| `CPL` | `cpl` | Direct match. |
| `CPE` | `cpa` | Not in our enum. Falls to the driver's default rather than failing the sync. |
| `CPC` | `cpa` | Same. |
| anything else / missing | `cpa` | Same. |

### Categories they list

`app`, `web`, `survey`, `click`, `game`. Stored verbatim. A value we have never seen still becomes a wall label.

---

## Event object (`events[]`)

| Their field | Type | Their description | What we do |
|---|---|---|---|
| `eventId` | string | Unique event id. **"Removed after fetching, only used internally."** | **Not stored.** Still arrives on the postback as `{EVENT_ID}` for multi-event offers. |
| `action` | object | Localized event text, e.g. `{ "en": "Install app" }` | Goal name from `action.en`. A step with no label is dropped. |
| `payout` | number \| undefined | USD for this event. **May be absent** for non-payable intermediate steps. | Goal payout. Missing → `0`. The step is kept, because the user still has to do it. |

`isMultiReward` is `count(events) > 1` after filtering to arrays, **before** dropping unlabelled steps. An offer with one labelled event and one blank action still counts as multi-reward.

---

## Clicks (our Offers tab)

Catalogue link they ship:

```
https://api.gemiwall.com/api/offers/click?offerId=offer123&userId=[USER_ID]&placementId=placement123
```

Some live / fixture rows omit `userId` entirely. The driver then appends `&userId={click_id}`.

### Tracking URL parameters they document

| Param | Required on their side | Meaning |
|---|---|---|
| `offerId` | implied | Offer. Already on the URL they send. |
| `userId` | yes for us | "Your clickId or userId to identify the user." We put our click UUID here. Echoed as `{USER_ID}`. |
| `placementId` | implied | Placement. Already on the URL they send. |
| `sub1` | no | Source ID. Echoed as `{SUB1}`. We do not set it. |
| `sub2` | no | Subsource ID. Echoed as `{SUB2}`. We do not set it. |

Placeholder rewrite (`SUBID_PLACEHOLDERS`):

| They send | We store |
|---|---|
| `[USER_ID]` | `{click_id}` |

`buildTrackingUrl()` runs the same helper, then substitutes the UUID. A stored link with no macro — hand-edited, or synced before this existed — must not be handed to the user untouched. An untracked click is a conversion nobody can be paid for.

Their Node sample also appends `&sub1=api_source&sub2=backend_campaign`. Optional. We do not add those; the postback still works without them.

---

## Postback

The contract is a URL **we** write. They GET whatever is saved on the placement and substitute macros. Query keys below are ours because we chose them.

```
https://{host}/postback/gemiad
```

### URL to paste into Placement settings → Postback URL

Exactly this (the driver's docblock). `{PAYOUT}` not `{REWARD}`:

```
https://{host}/postback/gemiad?userId={USER_ID}&offerId={OFFER_ID}&offerName={OFFER_NAME}&eventName={EVENT_NAME}&payout={PAYOUT}&txid={TXID}&status={STATUS}&ip={IPADDR}&hash={HASH}
```

Their own example includes every macro:

```
https://your-domain.com/postback/gemiAd?userId={USER_ID}&offerId={OFFER_ID}&offerName={OFFER_NAME}&eventId={EVENT_ID}&eventName={EVENT_NAME}&payout={PAYOUT}&reward={REWARD}&txid={TXID}&status={STATUS}&ip={IPADDR}&sub1={SUB1}&sub2={SUB2}&hash={HASH}
```

We omit `{REWARD}`, `{EVENT_ID}`, `{SUB1}`, `{SUB2}` on purpose. Extra macros on their example are safe to add later; they land on `raw` and are not required to parse.

| Macro we skip | Why |
|---|---|
| `{REWARD}` | App currency at **their** placement exchange rate. Ledger is USD. Crediting `{REWARD}` would be off by that rate. |
| `{EVENT_ID}` | "Removed after fetching, only used internally." We name the conversion from `{EVENT_NAME}`. Not part of `networkTxId` — see Transaction id. |
| `{SUB1}`, `{SUB2}` | We do not send them on the click, so they come back empty. |

### HTTP

- Method: **GET** (documented).
- Source IP: **`64.226.92.208` only.** Seeded on the `gemiad` row. Empty `postback_ips` **rejects** every fire (controller, not the Filament helper text).
- Our response: HTTP **200** within **5 seconds**. Body is not specified; we send `ok` (strictest published partner contract). Any other status they retry.
- Retries: **up to 3** on failure.
- Duplicates: they say we must handle them. Unique `(network_id, network_txid)` does.

### Available macros (complete list they publish)

| Macro | Type | Their description |
|---|---|---|
| `{USER_ID}` | string | Your user's unique identifier |
| `{OFFER_ID}` | string | Offer identifier |
| `{OFFER_NAME}` | string | Name of the offer |
| `{EVENT_ID}` | string | Event ID (**only for multi-event offers**) |
| `{EVENT_NAME}` | string | Event name (**only for multi-event offers**) |
| `{PAYOUT}` | number | Total payout in **USD** (negative for reversals) |
| `{REWARD}` | number | Event reward in **your app's currency** at the placement exchange rate (negative for reversals) |
| `{TXID}` | string | Transaction ID (same ID used for reversals) |
| `{STATUS}` | string | `"completed"` or `"rejected"` (for reversals) |
| `{IPADDR}` | string | User's IP address |
| `{SUB1}` | string | Source ID from the tracking URL |
| `{SUB2}` | string | Subsource ID from the tracking URL |
| `{HASH}` | string | Security hash for verification |

### What `parsePostback()` reads

Query keys are the ones on **our** template (`userId`, `txid`, …), not the brace names.

| Incoming key | Their macro | Required for us | Maps to |
|---|---|---|---|
| `userId` | `{USER_ID}` | yes | `clickId` → `offer_clicks.click_id` |
| `txid` | `{TXID}` | yes | `networkTxId` |
| `payout` | `{PAYOUT}` | yes, numeric | USD. Negative → reversal. |
| `status` | `{STATUS}` | no | `"rejected"` → reversal, even if payout is still positive |
| `offerId` | `{OFFER_ID}` | no | `offerExternalId`. **Required for the hash.** |
| `eventName` | `{EVENT_NAME}` | no | `offerName` when present |
| `offerName` | `{OFFER_NAME}` | no | Fallback name when no event fired |
| `ip` | `{IPADDR}` | no | Conversion IP |
| `hash` | `{HASH}` | yes for verify | Not copied onto the DTO |
| `eventId`, `reward`, `sub1`, `sub2` | those macros | no | Ignored if present. Live on `raw` if the URL includes them. |

Missing `userId`, missing `txid`, or non-numeric `payout` → `parsePostback()` returns `null` → HTTP 422.

No `endUserId`: `userId` is GemiAd echoing our click id, not an identifier of theirs. The end user is the click's.

Their Node sample destructures `txId` from `req.query`. Express would only populate that if the query key is `txId`. Our template uses `txid`. Do not rename the query key without changing `parsePostback()` and `verifyPostback()`.

### Reversals (their rules)

- Fired when a conversion is reversed / rejected.
- `{STATUS}` = `"rejected"`.
- `{PAYOUT}` (USD) and `{REWARD}` (app currency) are **negative**.
- `{TXID}` matches the original conversion's transaction id.
- They tell publishers to deduct the app-currency reward. We debit USD from `{PAYOUT}`.

We reverse if **either** `status=rejected` **or** `payout < 0`. Either signal alone is enough. Reading this backwards credits a chargeback.

`payout` on the DTO is signed: credit is positive, reversal is negative. `reverse()` looks up `networkTxId` = the same `{TXID}`.

### Transaction id / multi-event

`{TXID}` is the idempotency key. Their docs say it is "the same ID used for reversals" and describe **no per-event key**.

ASSUMPTION: each payable event of a multi-event offer fires with its **own** `{TXID}`. If they reuse one id across an offer's events, the second event is dropped as a duplicate — a step the publisher earned and does not see. Suffixing `{EVENT_ID}` onto the key would fix that and **break the reversal**, because a reversal quotes only `{TXID}`. This codebase would rather miss a step than fail to claw back a chargeback.

Confirm against a live multi-event fire before taking multi-reward traffic. If two events share a `txid`, stop and change the key only together with however they identify the event on the reversal.

Single-event offers send no `{EVENT_ID}` / `{EVENT_NAME}`. Empty `eventName` falls back to `offerName`.

### Hash

Documented, not guessed.

```
SHA256( userId + offerId + txId + secretKey )
```

Hex digest. Secret is Profile Settings.

Their Node:

```js
crypto.createHash('sha256').update(userId + offerId + txId + SECRET_KEY).digest('hex')
```

We do the same, then `hash_equals` against `strtolower(hash)`. Hex is hex in either case.

Order is concatenation of the **four raw strings**, no separators, no URL, no payout. Empty `offerId` still goes into the string — changing that changes the hash.

`verifyPostback()` fails closed if `secret_key` is blank. A missing `hash` does not match.

Source-IP allowlist runs first in the controller. Both gates are real here.

Their sample rejects a bad hash with HTTP 400. We reject with **403** (`invalid signature`). They retry failed postbacks up to 3 times — a wrong secret will retry, then stop. Fix the secret, do not loosen the check.

### Their Node handler (behaviour we care about)

1. Require `hash`, `userId`, `offerId`, `txId`.
2. Verify SHA-256.
3. **Respond 200 `Approved` immediately**, then process. We process first, then 200 `ok`. Duplicates are already 200.
4. `status === 'completed'` → credit `reward` (app currency). We credit `payout` (USD).
5. `status === 'rejected'` → `Math.abs(reward)` deduct. Same TXID as the credit.

### Debug

Reports → Postbacks on their dashboard. Our side: `incoming_postbacks` for the raw query, including a hash we rejected.

---

## What we do not handle

- `{REWARD}` / placement exchange rate. Ledger is USD from `{PAYOUT}`.
- `video`, `banner`.
- `dailyClickCap`, `epc`, `cvr`.
- `multiComplete` (repeatable offers stay one-and-done on our wall).
- `multiEvent` flag (derived from event count).
- `eventId` on the stored offer.
- Localized copy other than `en`.
- `sub1` / `sub2` on the click URL.
- Their hosted wall as a catalogue (`format=json` is not a thing they publish; the wall is HTML).
- Brand-asset download.
- A second catalogue request inside the same minute.

---

## Live-fire checklist

1. Network form: Placement ID + API key + **Profile** secret. Test connection counts `offers`. 401 = wrong API key. 404 = bad placement or inactive publisher. 429 = five-a-minute.
2. Placement settings: paste the postback URL from the driver docblock, **exactly**, including `hash={HASH}` and `txid={TXID}`.
3. Confirm `postback_ips` is `64.226.92.208`. Empty rejects.
4. Sync: USD rows with an `id` appear. `UK` is stored as `GB`. Id-less rows do not.
5. Click an offer: outbound URL contains `userId=<uuid>` and does **not** contain `[USER_ID]`.
6. Their postback test: same uuid in `userId`, numeric `payout`, `txid` set, `status=completed`, valid `hash`, source `64.226.92.208`. Expect accepted.
7. `unknown_click` means `userId` was missing or not our UUID. Fix the click / wall template, not `ConversionProcessor`.
8. Hash fail: recompute `sha256(userId + offerId + txid + profileSecret)`. Wrong field order or the placement API key used as the secret are the usual causes.
9. Multi-event: two fires, two `txid`s, two credits. If they share a `txid`, stop — see Transaction id.
10. Chargeback: `status=rejected` and/or negative `payout`, **same** `txid` as the credit.
11. Duplicate: same `txid` again → 200, no second credit.
12. Embed (only if we frame their wall): `userId={sub_id}` on the iframe. Repeat step 6 against the embed click. Do not put `{user_id}` in that slot.
