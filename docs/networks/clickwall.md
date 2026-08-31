# ClickWall (`clickwall`)

Working notes for ClickWall: hosted iframe wall, unpaginated catalogue, and HMAC-signed postbacks. Driver: `app/Networks/Drivers/ClickwallDriver.php`. Fixture: `tests/Fixtures/Networks/clickwall-offers.json`.

Captured 2026-08-22 from the publisher dashboard (Web Offerwall, Postback / S2S, Postback Security, API Access) and [docs.clickwall.net](https://docs.clickwall.net/). The public marketing site is [clickwall.net](https://clickwall.net/).

| Page | Where | What it is |
|---|---|---|
| Web Offerwall | Publisher dashboard | Hosted iframe `/app/iframe/{APP_ID}/{USER_ID}` |
| Postback (S2S) | Publisher dashboard | Macro URL, `completed` / `rejected`, `{amount}` vs `{payout}` |
| Postback Security | Publisher dashboard | HMAC-SHA256 recipe and Postback Key |
| API Access | Tools → Apps → Request API | Catalogue key, pending until an admin approves |
| Offers API | [docs.clickwall.net/offers-api](https://docs.clickwall.net/offers-api) | `GET /api/?app_id=` field list |
| Postback | [docs.clickwall.net/postback](https://docs.clickwall.net/postback) | Older macro list, no signature |
| Marketing site | [clickwall.net](https://clickwall.net/) | Live tiles in USD, homepage sample `payout: 1.50` |

The dashboard docs are the ones we implement against. The public docs site is older: it omits `api_key`, `{status}`, and `sig`.

---

## Credentials

Stored on `networks.credentials` (encrypted). Admin labels come from `credentialSchema()`.

| Our key | Their name | Required | Where they show it |
|---|---|---|---|
| `app_id` | App ID | yes | Apps section. Public — it sits in the iframe URL. |
| `api_key` | API key | no | Tools → Apps → Request API, then View API Key. Catalogue only. |
| `secret_key` | Postback Key | yes | App card → Postback Key. 64 characters. Signs postbacks. |

The two secrets are not interchangeable. Pasting the API key into `secret_key` makes every hash fail. Pasting the postback key into `api_key` makes every catalogue call a refusal.

API access starts **Pending**. The iframe and postbacks work without it. Until the key is pasted, `testConnection()` fails closed and the sweep throws rather than returning an empty catalogue. Switch the row to **Embedded wall only** if the catalogue should stay off.

---

## Transport

```
GET https://clickwall.net/api/?app_id={app_id}&api_key={api_key}
```

One app is one unpaginated call. There is no `page`, no `next`, no cursor. Optional `country_code` / `os` filters are not sent — we pull the full catalogue and filter on our side.

`success: false` on HTTP 200 is a refusal, not an empty catalogue — throwing here is what stops the sweep from deactivating every Clickwall offer we have.

User-Agent we send: `AdVault/1.0`. Not documented as required.

---

## Offer object

| Their field | Ours |
|---|---|
| `id` | `externalId`. Missing → skip |
| `name` | `name` |
| `description` | `description` |
| `payout` | `payout` (USD, offer total — not recomputed from events) |
| `type` | `categories` as sent; `app` / `game` map to `cpi` |
| `image_url` | `iconUrl` |
| `tracking_url` | `[USER_ID]` / `[user_id]` → `{click_id}` |
| `countries` | CSV or list. `UK` → `GB`. `All` → worldwide |
| `operating_system` | `Android` / `iOS` / `Windows` / `macOS` / `All` |
| `events[]` | `goals` (`event_name` / `event_payout`) |

`Windows` / `macOS` / `PC` are translated to `desktop` before `parseDevices()` — left raw they store an empty list, which means every device.

ASSUMPTION: catalogue `payout` is USD. The homepage sample is `1.50` and the live tiles are dollar figures. The dashboard API sample is `payout: 80` against events of `$0.096 + $0.704`. Confirm a live row before traffic; if that sample is real, we would show a $80 tile for an $0.80 offer.

---

## Clicks

Catalogue links look like:

```
https://clickwall.net/app/tracking/{app}/{offer}/[USER_ID]/{token}
https://clickwall.net/app/tracking/{app}/{offer}/[user_id]
```

`[USER_ID]` is our `{click_id}`. A link with no placeholder gets `?user_id={click_id}` appended — a path segment would sit after their tracking token and they would not see it.

---

## Hosted wall

```
https://clickwall.net/app/iframe/{APP_ID}/{USER_ID}
```

Seeded as:

```
https://clickwall.net/app/iframe/YOUR_APP_ID/{sub_id}
```

`{USER_ID}` is the only slot that comes back on `{user_id}`. It has to be the embed click (`{sub_id}`), not the publisher userid — `ConversionProcessor` attributes a conversion entirely off a click. The embed row is one per `(app, partner, end user)` and is reused, so the id is unique and unchanging per user, which is what they ask for.

Replace `YOUR_APP_ID` before framing. The iframe they publish asks for `allow="camera; microphone; geolocation"`; our partner frame only grants `clipboard-write`. Offers that need the camera will fail in the nested frame until that attribute is widened, which is a wall change, not a driver change.

Older public docs list `{user_id}` as `varchar(25)`. Our click UUID is 36 characters. Confirm a live fire echoes the whole id; truncation would match nothing.

---

## Postbacks

Paste `ClickwallDriver::postbackUrl()` into Apps → Edit Settings → Postback URL.

```
https://{host}/postback/clickwall?user={user_id}&reward={amount}&payout={payout}&tx={txid}&oid={offer_id}&offer_name={offer_name}&status={status}&ip={user_ip}
```

They append `&sig=…`.

| Macro | What we do with it |
|---|---|
| `{user_id}` | Click id (`user=`) |
| `{amount}` | Coins. Signature only (`reward=`). Never the ledger. |
| `{payout}` | USD. The ledger. |
| `{txid}` | Idempotency key |
| `{offer_id}` | External id, and the `oid=` term of the hash |
| `{offer_name}` | Conversion label |
| `{status}` | `completed` credits, `rejected` reverses |
| `{user_ip}` | Stored on the conversion |

They expect HTTP 200 with body `ok` / `1` / `SUCCESS`. The controller already returns `ok`.

A chargeback is `status=rejected` carrying the original `{txid}` and (usually) the original amount. The sign of `{payout}` is also read, so a negative payout with no status still reverses.

ASSUMPTION: each payable event of a multi-event offer fires with its own `{txid}`. If they reuse one id, the second event is dropped as a duplicate. Suffixing the offer id would break the clawback. Confirm against a live multi-event fire.

They publish **no** firing addresses. The allowlist starts empty and `allow_any_postback_ip` stays on. The HMAC is the whole of the authentication until someone pastes a list and turns the flag off.

---

## Signature

Postback Key on the app card. Payload, exactly:

```
user_id={user_id}&oid={offer_id}&amount={amount}&tx_id={txid}
```

`hash_hmac('sha256', $payload, $secret)`. Compared to `sig`. Values are the strings they sent — do not recast `amount`.

The hash does **not** cover `{payout}`. An intercepted request can change the USD figure and still verify. That is their recipe. The IP allowlist is the second gate once we have addresses.

---

## Enablement

1. Seed / create the `clickwall` row (disabled).
2. Paste App ID and Postback Key.
3. Replace `YOUR_APP_ID` on the wall URL.
4. Paste `ClickwallDriver::postbackUrl()` into their Postback URL field.
5. Request API access; paste the key when approved. Or set integration to Embedded wall only.
6. Test connection. Fire a live test before traffic.
7. Switch the row on.
