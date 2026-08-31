# Klink (`klink`)

Working notes for Klink / Klink Quest: Offer API, click redirect, hosted iframe wall, and unsigned postbacks. Driver: `app/Networks/Drivers/KlinkDriver.php`. Fixture: `tests/Fixtures/Networks/klink-offers.json`.

Captured 2026-08-20 from [publisher.klinklabs.com/docs](https://publisher.klinklabs.com/docs/guides/publisher/offer-api).

| Page | URL | What it is |
|---|---|---|
| Offer API | `/docs/guides/publisher/offer-api` | Paginated catalogue, categories, countries |
| Offer Wall (iframe) | `/docs/guides/publisher/offer-wall-integration` | Hosted wall URL |
| User Offer Redirect | `/docs/guides/publisher/offer-redirect` | JWT click (recommended) and query-string click |
| Publisher Postback | `/docs/guides/publisher/postback` | Fixed GET/POST payload, chargebacks, firing IPs |
| Overview | `/docs/guides/publisher/publisher-overview` | Onboarding |

---

## Credentials

Stored on `networks.credentials` (encrypted). Admin labels come from `credentialSchema()`.

| Our key | Their name | Required | Where they show it |
|---|---|---|---|
| `pub_id` | Publisher ID | yes | Onboarding. Example `271e6dc9-d2fd-4f21-bba4-cdabc9df3ad2` |
| `api_key` | API key | yes | Onboarding. Catalogue auth only |

Auth on every catalogue hop:

```
Authorization: Bearer {pub_id}:{api_key}
```

The two values are one token, colon-joined. Pasting only the key is a 401.

They also issue an **Activity JWT Secret** for the recommended click path. We do not store it. Catalogue clicks use the documented query-string redirect instead.

---

## Transport

```
GET https://klink-quest.klink.finance/api/v1/publisher/offers?page=1&limit=500
```

`limit` is 1–500 (their default 100). The driver asks for 500. Pagination is `currentPage` / `totalPages` / `data[]`. `success: false` on HTTP 200 is a refusal, not an empty catalogue — throwing here is what stops the sweep from deactivating every Klink offer we have.

Optional filters they document (`country`, `platform`, `device_name`, `category`, `sort_by`) are not sent. We pull the full catalogue and filter on our side.

User-Agent we send: `AdVault/1.0`. Not documented as required.

They also publish `/api/v1/publisher/categories` and `/countries`. Unused — we do not build their filters.

---

## Offer object

No tracking URL on the row. Name and description are localized objects (`{ "en": "…" }`).

| Their field | Ours |
|---|---|
| `offerId` | `externalId`. Missing → skip |
| `name.en` | `name` |
| `description.en` | `description` |
| `totalPayout` | `payout` (USD, offer total — not recomputed from activities) |
| `activities[]` | `goals`, sorted by `order` |
| `images.logo` | `iconUrl` |
| `countries` | ISO-2 |
| `categories` | as sent |
| `platform` + `deviceName` | `devices` |
| `isActive: false` | skip |

`deviceName` is `desktop` or `mobile`. `platform` is `web`, `android`, or `ios`. `mobile` + `web` is stored as android+ios — `parseDevices()` maps `web` to desktop, which would hide the phones the offer was bought for.

---

## Clicks

The catalogue has no link. We build:

```
https://klink-quest.klink.finance/redirect?pubId={pub_id}&offerId={offerId}&userId={click_id}
```

`userId` is documented as "your internal user ID or click ID". It comes back on the postback as `userId`, so it is our click UUID.

They recommend a JWT to `https://app.klinkfinance.com/quest-pn?ot=<JWT>` signed with the Activity JWT Secret (`offerId`, `sub`, `pub`, 2-hour `exp`, optional `custom_params.k1`–`k5`). Not implemented. The query form is their documented fallback.

`k1`–`k5` are optional on the redirect and echoed on the postback. The driver reads `k1` only as a click-id fallback if `userId` is blank.

---

## Embedded wall

```
https://offerwall.klinkfinance.com/wall?pub_id={PUB_ID}&user_id={USER_ID}
```

Paste into **Networks → Hosts its own wall → Partner wall URL**, with `{sub_id}` in `user_id` — that is `PostbackDTO.clickId`. Replace `YOUR_PUB_ID` with the real publisher id (plaintext; not a secret).

```
https://offerwall.klinkfinance.com/wall?pub_id=YOUR_PUB_ID&user_id={sub_id}
```

Do not put `{user_id}` in their `user_id` if we need per-open attribution. Do not put `api_key` on this URL — it is rendered into the wall HTML.

The seeded row starts as **API and embedded wall**. The iframe is empty until `YOUR_PUB_ID` is replaced.

---

## Postbacks

Their dashboard uses `{{macro}}` on GET. A bare path with GET arrives empty and 422s. Paste the URL the admin network screen copies — `KlinkDriver::postbackUrl()`:

```
https://{host}/postback/klink?payout={{payout}}&status={{status}}&userId={{userId}}&offerId={{offerId}}&offerName={{offerName}}&eventId={{eventId}}&eventName={{eventName}}&taskId={{taskId}}&eventType={{eventType}}&conversionId={{conversionId}}&reversedConversionId={{reversedConversionId}}&k1={{k1}}&k2={{k2}}&k3={{k3}}&k4={{k4}}&k5={{k5}}&ip={{ip}}
```

POST sends the same keys as JSON and needs no macros. No signature.

Do **not** map `{{payoutToUser}}`. That is coins after their multiplier. Ledger `{{payout}}` only.

**Firing IPs** (seeded):

```
34.118.33.53
138.68.125.171
64.226.93.56
74.220.53.15
74.220.53.234
74.220.53.235
```

| Field | Role |
|---|---|
| `userId` | Our click id (`k1` if `userId` is blank) |
| `conversionId` | Idempotency key on a credit |
| `reversedConversionId` | Idempotency key on a chargeback — the *original* `conversionId` |
| `payout` | USD string. Negative on chargeback |
| `status` | `completed`, `pending` (hold, no credit), or `cancelled` |
| `ip` | End-user IP at the event |
| `eventType` | `conversion` or `chargeback` |
| `offerId` / `offerName` / `eventName` | Snapshot. Event name wins for the conversion label |
| `k1`–`k5` | Optional customs |

A reversal is `eventType=chargeback` **or** `status=cancelled` **or** a negative payout. Their chargeback example mints a new `conversionId` and points `reversedConversionId` at the credit. `reverse()` looks up `network_txid`, so the DTO carries the original id.

They describe `payout` as "includes publisher + user reward". Treated as the USD they pay us, same as `totalPayout` on the catalogue. Confirm against a live fire before traffic — crediting a combined figure would overpay.

---

## Enable checklist

1. Seed / create the `klink` row (disabled).
2. Paste Publisher ID and API key. Test connection.
3. Paste the GET macro URL from the network edit screen (or switch the method to POST and leave the path bare).
4. Confirm any-source is acceptable until the six IPs are verified live, then turn it off.
5. Replace `YOUR_PUB_ID` on the partner wall URL before framing.
6. Activate. Fire a live test conversion and a chargeback; confirm `reversedConversionId` matches the credit.
