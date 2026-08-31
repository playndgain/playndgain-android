# Offery (`offery`)

Working notes for Offery: unpaginated catalogue, hosted offerwall, click links with a path-segment subid, and an S2S postback **they write**. Driver: `app/Networks/Drivers/OfferyDriver.php`. Fixture: `tests/Fixtures/Networks/offery-offers.json`. Seeder row: `offery` / [offery.io](https://offery.io). Logo: `public/img/networks/offery.svg`.

Captured 2026-08-31 from [offery.io/docs](https://offery.io/docs/). The public page is the whole spec — Getting Started, Integrate Offerwall, Offers API, S2S Postback. The dashboard itself is login-gated.

The postback contract is the best-specified of the unsigned-IP partners and the second-best signed one after GemiAd: they POST a **fixed** set of keys, they publish the md5 recipe, they name chargebacks (`status=2`) and test fires (`debug=1`), and they require the body `ok`. There is no macro template to compose. What they do **not** publish is a firing-IP list, so the seeded allowlist stays empty and `allow_any_postback_ip` stays on until someone pastes addresses and turns the flag off.

---

## Sources

| Page | URL | What it is |
|---|---|---|
| Docs index | [offery.io/docs](https://offery.io/docs/) | The whole public contract. One page. |
| Getting Started | same page | Account, Add New App, virtual currency, Postback URL |
| Integrate Offerwall | same page | Hosted wall URL. Web / Android / iOS / React Native |
| Offerwall Parameters | same page | `[API_KEY]`, `[USER_ID]` — both `varchar(32)` |
| Offers API | same page | `GET /api/`, optional filters, sample envelope |
| S2S Postback | same page | Fixed POST payload, signature, chargebacks, `ok` |
| Marketing | [offery.io](https://offery.io/) | Product site. No extra API notes. |
| Publisher dashboard | login-gated from the docs | Apps, API key, secret key, Postback URL |

Native Android / iOS / React Native snippets on the same page are WebView wrappers around the hosted wall. Not our product.

---

## What this is for us

| Our piece | Their piece |
|---|---|
| `networks.credentials.api_key` | Website API key. Catalogue query + wall path. Visible on the iframe URL. |
| `networks.credentials.secret_key` | Secret key from the Offery dashboard. Signs postbacks. **Never** on a wall or click URL. |
| `networks.postback_ips` | **Not published.** Seeded empty. Any-source stays on until an AM list is pasted. |
| `postbackQuery()` | Empty. Paste `/postback/offery` and nothing else. |
| `fetchOffers()` / `syncsCatalogue()` | One unfiltered `GET https://offery.io/api/?apiKey=`. |
| `verifyPostback()` | `md5(subId + transId + reward + secret_key)` vs `signature`. Fails closed if the secret is blank. |
| `buildTrackingUrl()` | Trailing `USER_ID` path segment → `{click_id}`. |
| Hosted wall | Optional. Not seeded. `https://offery.io/offerwall/{API_KEY}/{sub_id}`. |

`subId` is the value they echo. On catalogue clicks that is our click UUID. On their hosted wall it has to be the embed click (`{sub_id}`), not the publisher userid — `ConversionProcessor` attributes entirely off a click.

---

## Credentials

Stored on `networks.credentials` (encrypted). Admin labels come from `credentialSchema()`.

| Our key | Their name | Required | Where they show it |
|---|---|---|---|
| `api_key` | API key / `[API_KEY]` | yes | After Add New App. On the catalogue query and the wall path. `varchar(32)`. |
| `secret_key` | Secret key / `SECRET_KEY` | yes | Same dashboard. Third term of the postback hash. |

The two are not interchangeable. The API key in `secret_key` makes every hash fail. The secret on the iframe URL leaks the only thing that authenticates a conversion.

New apps are reviewed; they say you can integrate immediately on **temporary** API keys. There is no documented expiry or rotation rule. Regenerating either key is an AM / dashboard action — confirm before assuming the old one still works.

Virtual-currency fields on their app (name, decimal places, units per $1) feed `reward`, `reward_name`, and `reward_value` on the postback. Our ledger is USD from `payout`. Those settings do not belong in credentials.

Talk to the AM / dashboard for: API key, secret key, postback firing IPs, and whether a live multi-event offer reuses one `transId`.

---

## Dashboard model

One publisher account. Then:

| Step they print | What it is |
|---|---|
| Add New App | App Name, URL, platform type |
| Virtual currency | Name, round (≤ 2 dp), exchange rate (their units per $1 USD) |
| Postback URL | One global endpoint. They POST a fixed payload here. |
| API key / secret | Issued on the app. Temporary until the app is approved. |

There is no documented per-offer postback and no pixel CRUD API.

---

## Hosts

| Host | Role |
|---|---|
| `offery.io` | Marketing, docs, catalogue (`/api/`), hosted wall (`/offerwall/…`), click redirect (`/click/…`) |
| `offery.io/img/offers/…` | Offer icons in the fixture / sample |

One origin for everything public. A hardcoded host is still a time bomb if they rotate; nothing in the docs says they do.

---

## Transport

```
GET https://offery.io/api/?apiKey={api_key}
```

One app is one unpaginated call. There is no `page`, no `next`, no cursor. `fetchOffers()` sets timeout **180** seconds for that reason. `testConnection()` uses the default 30s client.

The docs print the key two ways: the endpoint heading says `apiKey`, the example request says `apikey`. The query string is **case-sensitive**. The driver sends **`apiKey`**. `apikey` is a different parameter and comes back as a refusal, not an empty catalogue.

Optional filters they document and we **do not send**:

| Parameter | Type | Example | Why we omit it |
|---|---|---|---|
| `countries` | CSV | `us,ca,de` | Narrows the dump. A DE-only offer would never be stored. |
| `offer` | string | `Opera GX` | Name search. Not a catalogue. |
| `device` | CSV | `iOS,Android` | Same as countries — the wall filters stored `devices`. |
| `featured` | `yes` / `no` | `yes` | Would drop the rest of inventory. |
| `id` | integer | `1234` | Single-offer lookup. |

Their own best practice: call from the backend every **5 minutes**, never from the client. Our sweep is 60 minutes on a new row; that is slower than they ask, not faster.

User-Agent we send: `AdVault/1.0`. Not documented as required.

**Rate limit:** not documented beyond the 5-minute hint.

**Errors:** not documented as a table. The sample success envelope is `{ "status": "success", "results": N, "data": […] }`. A non-`success` `status` on HTTP 200 is a refusal — `testConnection()` goes red and `fetchOffers()` throws. Reading that as an empty catalogue deactivates every Offery offer we have; the collapse guard only catches a drop from above twenty.

`testConnection()` counts `data` as they sent it, including rows `fetchOffers()` will later skip (the fixture's id-less fourth row is counted here and dropped on sync).

---

## Offer object

Money on the catalogue is **USD** in `payout.usd`. `payout.reward` / `payout.currency` are their wall coins and are ignored.

| Their field | Sample | What we do |
|---|---|---|
| `offer.id` | `"9529"` | `OfferDTO.externalId`. **Skipped if missing.** |
| `offer.name` | `"Offer Name"` | Name. Fallback `"Untitled offer"`. |
| `offer.description` | string | Description. |
| `offer.requirements` | `"Achieve a specific target"` | Instructions — what the user has to do. |
| `offer.image` | icon URL | `iconUrl`. |
| `payout.usd` | `"4.36"` | Offer payout. **Authority.** Do not recompute from `events`. Their own sample does not add up (0.50 + 1.17 + 3.09 ≠ 4.36). |
| `payout.reward` | `"436"` | Their coins. **Not read.** |
| `payout.currency` | `"Coins"` | Their currency name. **Not read.** |
| `featured` | `"yes"` / `"no"` | **Not read.** |
| `devices` | `["Android"]` / `["PC"]` | Device targeting. `PC` → `desktop` before `parseDevices()`. |
| `events[]` | `{ name, payout }` | Goals. See Events. |
| `geo_targeting.allowed_countries` | `["US"]` | ISO-2 allowlist. Empty → worldwide. |
| `geo_targeting.excluded_countries` | `[]` | **Dropped.** `offers.countries` is an allowlist and nothing else. |
| `url` | `https://offery.io/click/{token}/{id}/USER_ID` | Tracking URL. `USER_ID` → `{click_id}`. |

No category field, no `dailyCap`, no OS floor, no preview URL, no `offer_type` on the catalogue row (`offer_type` exists only on the postback). `conversionType` stays `'cpa'`. An invented category is worse than none.

`isMultiReward` when more than one event survives the array filter.

### Events

```json
{
  "name": "Install",
  "payout": 0.50
}
```

| Their field | What we do |
|---|---|
| `name` | Goal name. Blank name drops the step. |
| `payout` | Goal payout in USD. Missing / zero is kept — a step the user still completes. |

Their sample third event is `"Finish Game"` at `3.09`. A zero-payout middle step is listed, not hidden.

### Devices

| They send | We store |
|---|---|
| `Android` | `android` |
| `iOS` | `ios` |
| `PC` | `desktop` |
| `All` / empty / unknown | `[]` → every device |

`PC` is their word for desktop. `parseDevices()` does not know it. Left unmapped, a PC-only offer stores an empty list, which means **every** device: the offer is shown to the Android and iOS users it cannot pay for. The alias is applied before parse.

### Countries

`allowed_countries` is an array in the sample (and the fixture). `parseCountries()` also accepts a CSV string. Empty / missing → worldwide.

`excluded_countries` has nowhere to live. A worldwide-minus-Brazil offer is stored as worldwide; a Brazilian user can click it; Offery declines that conversion. The upgrade is an `excluded_countries` column on `offers` read by the wall filter — not this driver's job until exclusion-shaped inventory shows up as unpaid clicks.

---

## Clicks

Catalogue links they ship:

```
https://offery.io/click/{token}/{offerId}/USER_ID
```

`USER_ID` is a **literal path segment**, not a query key. The driver substitutes it for `{click_id}`. A link that arrives without the placeholder gets `/{click_id}` appended as a final segment — that is where their documented format puts it.

`buildTrackingUrl()` runs the same helper, then replaces `{click_id}` with the UUID. A stored link with no slot — hand-edited, or synced before this existed — must not be handed to the user untouched. An untracked click is a conversion nobody can be paid for.

Do not put the API key or the secret on `url`. The token in the path is theirs.

---

## Hosted offerwall

Separate product from the API. They host the tiles; we frame them (or open a tab). Conversions still come back on the same postback.

```
https://offery.io/offerwall/[API_KEY]/[USER_ID]
```

iframe they print:

```html
<iframe
    scrolling="yes"
    frameborder="0"
    src="https://offery.io/offerwall/[API_KEY]/[USER_ID]"
    width="100%"
    height="600"
></iframe>
```

`window.open(…)` is the new-tab variant. Same URL.

| Parameter | Required | Their meaning |
|---|---|---|
| `[API_KEY]` | yes | Website API key. Visible in the iframe `src`. |
| `[USER_ID]` | yes | "Unique identifier for your user." `varchar(32)`. Echoed as `subId`. |

Their copy says this is a **stable** site user. Our postback reads `subId` as `PostbackDTO.clickId`. A publisher userid in that slot is an `unknown_click` on every wall conversion.

The row is **not** seeded as `hosts_wall`. Integration starts as API. An admin who later frames their wall sets **Both** (or **Embedded wall only**) and pastes:

```
https://offery.io/offerwall/YOUR_API_KEY/{sub_id}
```

| Their key | Our macro | Why |
|---|---|---|
| `USER_ID` (path) | `{sub_id}` | Embed click UUID. This is `PostbackDTO.clickId`. |
| `API_KEY` (path) | hard-coded | Public on the wall by design. Not a wall macro. |

Do **not** put `{user_id}` in their user slot if we need per-open attribution. Do **not** put `secret_key` on this URL.

Opening (`?open=1`) writes one embed `offer_clicks` row (`offer_id` null). Its `click_id` is `{sub_id}`.

`varchar(32)` vs our click UUID (36 with hyphens, 32 without). Confirm a live fire echoes the whole id; truncation matches nothing. If they truncate, strip hyphens on the way out and store the hyphenless form as `click_id` — do not guess that in this file.

Android / iOS / React Native samples are the same URL inside a WebView. Unused.

---

## Postback

They POST a **fixed** payload to whatever URL is saved on the app. You do not name the query keys. Unlike GemiAd / Monlix / Clickwall, there is no macro template.

Paste this and nothing else:

```
https://{host}/postback/offery
```

That is `OfferyDriver::postbackUrl()` (`postbackQuery()` is `[]`). Extra query keys we invent will not be filled; extra keys they send still land on `raw`.

### HTTP

- Method: **POST**. Their PHP samples read `$_REQUEST` (GET would also parse). We accept both; they document POST.
- Body they require: **`ok`**. Any other response is marked failed. The controller already returns `ok`.
- Timeout / retries: **not documented.**
- Duplicates: they tell you to key on `transId`. Unique `(network_id, network_txid)` does.
- Source IP: **not documented.** Seeded empty. `allow_any_postback_ip` starts on. The signature is the whole of the authentication until someone pastes a list and turns the flag off.

### Parameters they publish (complete list)

| Parameter | Their description | Example | What we do |
|---|---|---|---|
| `subId` | User's unique identifier | `user123` | `clickId`. Required. |
| `transId` | Unique transaction ID | `XX-12345678` | `networkTxId`. Required. |
| `offer_id` | Completed offer ID | `123456` | `offerExternalId`. |
| `offer_name` | Offer name | `Offery - Register and Earn` | `offerName`. Needed on embed. |
| `offer_type` | `ptc` / `offer` / `task` / `shortlink` | `ptc` | **Not read.** Lives on `raw`. |
| `reward` | Virtual currency amount to credit | `1.25` | Signature only, **as the string they sent**. Never the ledger. |
| `reward_name` | Your currency name | `Points` | Ignored. |
| `reward_value` | Currency units per $1 payout | `1000.00` | Ignored. |
| `payout` | Offer payout in USD | `0.100000` | Ledger. Required, numeric. Absolute — sign from `status`. |
| `userIp` | User's IP address | `192.168.1.0` | Conversion IP. |
| `country` | User's country (ISO2) | `US` | **Not copied** onto the DTO. |
| `status` | `1` = credit, `2` = chargeback | `1` | Direction. |
| `debug` | `1` = test, `0` = live | `1` | `1` → `rejected` (recorded, no money). Checked **before** `status`. |
| `signature` | MD5 hash | `17b4e2a70d6efe9796dd4c5507a9f9ab` | Verify only. |

> The "reward" and "payout" parameters are absolute values. Check the "status" parameter to determine whether to add or subtract the amount.

That sentence is the chargeback contract. Do not also treat a negative `payout` as a second reversal signal unless a live fire shows they send both.

Missing `subId`, missing `transId`, or non-numeric `payout` → `parsePostback()` returns `null` → HTTP 422.

No `endUserId`: `subId` is Offery echoing our click id, not an identifier of theirs. The end user belongs to the click.

### Chargebacks

`status=2` carrying the original `transId`. Amounts stay positive. The driver takes `abs(payout)` and flips the sign. `reverse()` looks up that same `transId`.

Their PHP sample:

```php
if ($status == 2) {
  $reward = -abs($reward);
}
```

We do the equivalent on `payout`, not `reward`.

### Debug / test fires

`debug=1` is a signed, genuine fire of a test conversion. It is recorded as `rejected` so the row exists and the ledger does not move. A debug chargeback must not claw back a real credit, which is why `debug` is read before `status`.

### Transaction id / multi-event

`transId` is the idempotency key. Their docs describe it as unique and name **no per-event key**.

ASSUMPTION: each payable event of a multi-event offer fires with its own `transId`. If they reuse one id across an offer's events, the second event is dropped as a duplicate. Suffixing `offer_id` or an event name would fix that and **break the chargeback**, because a chargeback quotes only `transId`. This codebase would rather miss a step than fail to claw one back.

Confirm against a live multi-event fire before taking multi-reward traffic.

---

## Signature

Documented, not guessed. Their PHP:

```php
md5($subId . $transId . $reward . $secret)
```

Concatenation. No separators, no URL, no `payout`. Compared to `signature` with `hash_equals` after `strtolower` (hex is hex in either case).

`reward` is concatenated as the **string they sent**. Casting `1.50` to float and back yields `1.5`, and every postback then fails a signature that was never wrong — which looks exactly like an outage on their side.

`verifyPostback()` fails closed if `secret_key` is blank. A missing `signature` does not match.

The hash does **not** cover `payout`. An intercepted request can change the USD figure and still verify. That is their recipe. The IP allowlist is the second gate once we have addresses.

Their sample rejects a bad hash with a printed error and no `ok`. We reject with **403**. If they retry failed postbacks (not documented), a wrong secret will retry, then stop. Fix the secret, do not loosen the check.

---

## What we do not handle

- Optional catalogue filters (`countries`, `device`, `featured`, `offer`, `id`).
- `payout.reward` / `payout.currency` / postback `reward*` — their wall rate, not ours.
- `featured`.
- `offer_type` as a first-class field.
- `geo_targeting.excluded_countries`.
- Native Android / iOS / React Native SDKs (WebView around the same wall).
- Their hosted wall chrome unless an admin sets `hosts_wall`.
- A published firing-IP list. There is none.
- Per-event keys on the postback. There are none.

---

## Driver vs docs

| Topic | Docs | What we do |
|---|---|---|
| Catalogue | `GET /api/?apiKey=` | Same. Their example request prints `apikey`; that casing does not authenticate. |
| Filters | Optional | None sent. |
| Envelope | `{ status, results, data }` | `status === success` and `data` is an array, or throw. |
| Payout | `payout.usd` + coins | USD only. |
| Click slot | `/click/…/USER_ID` | Substitute `{click_id}`. Append the segment if missing. |
| Wall | `/offerwall/{API_KEY}/{USER_ID}` | Not seeded. Paste `{sub_id}` in the user slot if we frame it. |
| Postback URL | You configure one endpoint | Bare `/postback/offery`. |
| Method | HTTP POST | Accepted. GET would also parse. |
| Auth | `md5(subId + transId + reward + SECRET_KEY)` | Same, `reward` as string. |
| Status | `1` credit, `2` chargeback | Same. Amounts absolute. |
| Debug | `1` = test | `rejected`, no money. Before status. |
| Response | body `ok` | Already. |
| Firing IPs | Not published | Seeded empty. Signature is the gate. |

---

## Enablement

1. Seed / create the `offery` row (disabled).
2. Paste API key and **secret** key. They are not the same value.
3. Paste `https://{host}/postback/offery` into their Postback URL. Save. Confirm the dashboard did not add query keys we will never read.
4. Test connection. Expect `Connected.` and a count of `data` (including rows we will skip).
5. Dry-run sync: USD rows with an `offer.id` appear; `PC` is `desktop`; `USER_ID` is gone from stored links.
6. Ask the AM for firing IPs. Paste the **full** list and turn **Allow any source IP** off. A partial list silently drops the missing address.
7. Fire a live test before traffic. Switch the row on.

---

## Live-fire checklist

1. Network form: Test connection counts `data`. A green button on `status: error` is a bug — that path must stay red.
2. Sync: three-event fixture-shaped rows keep `payout.usd` as the tile price, not the event sum. Id-less rows do not store.
3. Click an offer: outbound URL ends with `/<uuid>` and does **not** contain `USER_ID`.
4. Their postback test: POST, same uuid in `subId`, numeric `payout`, `transId` set, `status=1`, `debug=0`, valid `signature`, body we return is `ok`. Expect `accepted`.
5. Hash fail: recompute `md5(subId + transId + reward + secret)` with `reward` exactly as received (`1.50` ≠ `1.5`). Wrong field used as the secret is the usual cause.
6. `unknown_click` means `subId` was missing or not our UUID. Fix the click / wall template, not `ConversionProcessor`.
7. Chargeback: `status=2`, **same** `transId`, amounts still positive. Expect `reversed` and a negative ledger amount.
8. Debug: `debug=1` writes a `rejected` row and moves no money, including `status=2`.
9. Duplicate: same `transId` again → 200 `ok`, no second credit.
10. Multi-event: two fires, two `transId`s, two credits. If they share a `transId`, stop — see Transaction id.
11. Embed (only if we frame their wall): path user = `{sub_id}`. Repeat step 4 against the embed click. Confirm the echoed `subId` is the full UUID (`varchar(32)` vs 36).
12. Do not credit `reward`. A publisher on 1000 coins to the dollar would be overpaid a thousand times.

---

## Ask the AM

- Postback firing IPs (required before turning any-source off).
- Whether `USER_ID` / `subId` truncates at 32 characters.
- Whether each event of a multi-event offer gets its own `transId`.
- Whether a chargeback `payout` is ever negative in addition to `status=2`.
- HTTP timeout and retry count on a failed `ok`.
- Whether temporary API keys survive app approval or are rotated.
