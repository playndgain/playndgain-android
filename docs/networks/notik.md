# Notik (`notik`)

Working notes for handling Notik's catalogue, iframe, and postback. Driver: `app/Networks/Drivers/NotikDriver.php`. Fixture: `tests/Fixtures/Networks/notik-offers.json`.

Their pages: All Offers API, Iframe, Server Postback, [publisher settings](https://notik.me/publisher-settings).

---

## Credentials

Stored on `networks.credentials` (encrypted). Admin labels come from `credentialSchema()`.

| Key | Required | Where they show it |
|---|---|---|
| `api_key` | yes | Offer API / app / iframe |
| `pub_id` | yes | Publisher ID |
| `app_id` | yes | App ID |
| `postback_secret` | yes | App detail → secret key (HMAC) |

Auth is query-string on every catalogue hop. `next_page_url` does not carry the keys, so `fetchOffers()` puts them back on before the next request. A page-two 401 silently ends the catalogue at page one.

---

## All Offers API

Unfiltered catalogue. We filter after storing. Their rule: pull every **15 minutes**, at most **30 requests / 15 minutes**, **1000 offers per page**. Sending traffic to a disabled offer is not paid.

```
GET https://notik.me/api/v2/get-offers/all?api_key={api_key}&pub_id={pub_id}&app_id={app_id}
```

**Pagination.** Follow `offers.next_page_url` until it is `null`. Do not invent `?page=N`.

**Envelope**

```json
{
  "status": "success",
  "code": 200,
  "offers": {
    "data": [ { "offer_id": "…" } ],
    "next_page_url": null,
    "has_more_pages": false,
    "per_page": 1000,
    "total": 5
  }
}
```

`rowsFrom()` also accepts a top-level `data` (older fixtures).

### Offer row

| Their field | What we do |
|---|---|
| `offer_id` | `OfferDTO.externalId`. Skipped if missing. |
| `name` | Offer name. Fallback `"Untitled offer"`. |
| `click_url` | Tracking URL. We append `s1={click_id}`. |
| `payout` | USD float. |
| `reward_name` | If `"Points"`, the row is **dropped**. |
| `image_url` | Icon. |
| `description1` | Description and first instruction line. |
| `description2`, `description3` | Extra instruction lines. |
| `description` | Present on some live rows. **Not read.** |
| `country_code` | ISO-2, uppercased. `[]` = worldwide. |
| `os` | Device targeting. |
| `devices`, `platforms` | **Not read** — we use `os`. |
| `categories` | Labels as they arrive (`CPE`, `Surveys`, …). |
| `events` | Goals. `isMultiReward` when more than one. |

Live `click_url` they ship ends with `{user_id}`:

```
https://notik.me/l/….html?pub_id=…&app_id=…&user_id=PREFIX_{user_id}
```

Their docs: replace `{user_id}` with a **stable** site user id; put a changing click/session id on `&s1=`. `buildTrackingUrl()` is only handed the click id, so `{user_id}` is left as they sent it and `s1` carries our UUID.

---

## Iframe (Offerwall tab)

```html
<iframe src="https://notik.me/coins?api_key={YOUR_API_KEY}&pub_id={YOUR_PUB_ID}&app_id={YOUR_APP_ID}&user_id={YOUR_UNIQUE_USER_ID}"
        style="height: 100vh; width: 100%; margin: 0; padding: 0; border: 0;"></iframe>
```

| Parameter | Required | Meaning |
|---|---|---|
| `api_key` | yes | App API key (visible in the iframe `src`) |
| `pub_id` | yes | Publisher ID |
| `app_id` | yes | App ID |
| `user_id` | yes | Their "unique and constant" site user |
| `s1` | no for them, **yes for us** | Changing click / session id. Echoed on the postback. |

Paste this into **Networks → Hosts its own wall → Partner wall URL**:

```
https://notik.me/coins?api_key=YOUR_API_KEY&pub_id=YOUR_PUB_ID&app_id=YOUR_APP_ID&user_id={user_id}&s1={sub_id}
```

| Their key | Our macro | Why |
|---|---|---|
| `user_id` | `{user_id}` | Publisher `userid` from our wall iframe. Constant per player. |
| `s1` | `{sub_id}` | Embed click UUID. This is `PostbackDTO.clickId`. |

Do not put `{sub_id}` in `user_id`. That slot is the player, `s1` is the click. Do not put `postback_secret` on this URL.

Opening (`?open=1`) writes one embed `offer_clicks` row (`offer_id` null). Its `click_id` is `{sub_id}`.

---

## Clicks (our Offers tab)

Stored tracking URL:

```
{their click_url}&s1={click_id}
```

The user is sent that with `{click_id}` replaced by the UUID. `{user_id}` on their link is not substituted — the interface has no end-user argument.

---

## Postback

```
https://{host}/postback/notik
```

Paste that into App Settings → Server Postback. They GET it. We must answer **HTTP 200** within **15 seconds**. Body `ok` is fine. Any other status they retry twice, then email. Duplicates still get 200 — we already do that.

**Allowlist:** `158.69.116.45` only. Seeded on the `notik` row. Empty would reject every fire.

They encode query values as RFC 1738 (space → `+`). Do not rebuild the query when checking `hash`.

| Their field | Required for us | Maps to |
|---|---|---|
| `txn_id` | yes | Credit: `networkTxId`. |
| `rewarded_txn_id` | chargebacks | The **original** `txn_id`. `reverse()` looks this up. |
| `payout` | yes, numeric | USD. Negative on a chargeback. |
| `amount` | no | Virtual currency at their rate. **Ignored** — ledger is USD. |
| `s1` | yes, or `UnknownClick` | `clickId` → `offer_clicks.click_id` |
| `user_id` | no | Not copied onto the conversion. The click already has the publisher user. |
| `offer_id` | no | `offerExternalId` |
| `offer_name` | no | Needed on embed (no offer of ours). |
| `conversion_ip` | no | Conversion IP. |
| `event_id`, `event_name` | no | Not stored. |
| `currency_name`, `timestamp`, `pub_id`, `app_id` | no | Ignored. |
| `hash` | yes | HMAC-SHA1 of the callback URL with `hash` stripped, keyed by the app secret. |

Chargeback: `payout` and `amount` are negative, and `rewarded_txn_id` is the credit they already sent. We set `status = reversed` and `networkTxId = rewarded_txn_id`.

Missing or empty `s1` is `UnknownClick`. Admin can attach the click by hand.

### Hash

Documented recipe (their PHP):

1. Build `https://{host}{request_uri}` as received.
2. Strip `&hash={hash}` from the end (they put it last).
3. `hash_hmac('sha1', $urlWithoutHash, $appSecret)`.
4. Compare to `hash`.

Empty parameters must stay empty strings, not NULL — they warn that changes the hash. We strip `hash` off the raw URI and do not re-encode.

`verifyPostback()` fails closed if the secret or `hash` is blank.

---

## What we do not handle

- **Top Converting Offers** — separate endpoint. We do not call it.
- Their "hide if this user already completed it" rule. We hide on **our** approved `(end_user_id, offer_id)`.
- `devices` / `platforms`.
- `amount` / `currency_name` — publisher currency is our rate, not theirs.

---

## Live-fire checklist

1. Network form: Test connection counts `offers.data`.
2. Sync: USD rows appear; `reward_name = Points` rows do not.
3. Click an offer: outbound URL contains `s1=<uuid>`.
4. Their postback test: same uuid in `s1`, `txn_id` set, `hash` valid, source `158.69.116.45`. Expect `accepted`.
5. `unknown_click` means `s1` was missing or not our UUID. Fix the wall/click template, not `ConversionProcessor`.
6. Embed: iframe `src` has `{user_id}` = publisher userid and `s1` = embed click uuid. Repeat step 4.
7. Chargeback test: negative `payout` + `rewarded_txn_id` = the credit's `txn_id`.
