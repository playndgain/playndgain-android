# Ayet Studios (`ayetstudios`)

Working notes for Ayet's catalogue, tracking links, hosted wall, and conversion callback. Driver: `app/Networks/Drivers/AyetStudiosDriver.php`. Fixture: `tests/Fixtures/Networks/ayetstudios-offers.json`.

Fetched 2026-08-18 from their product docs (every `.md` page under [docs.ayetstudios.com/v/product-docs](https://docs.ayetstudios.com/v/product-docs/)) and Publisher API OpenAPI **v1.19.0** ([www.ayetstudios.com/openapi/publisher-doc](https://www.ayetstudios.com/openapi/publisher-doc)). GitBook product pages embed the OpenAPI for the real field lists; the product site alone is not enough.

They write the catalogue. **We write the postback URL.** Nothing below works until an admin pastes the template into Placements → Settings. Dropping a macro from that template fails silently: the fire still arrives, we still answer 200, and the field is null forever. Two macros do not: without `{transaction_id}` or `{payout_usd}` `parsePostback()` returns null, the controller answers **422**, and they retry **12 times over one hour**.

---

## Sources

| Page | URL | What it is |
|---|---|---|
| Product docs index | [docs.ayetstudios.com/v/product-docs](https://docs.ayetstudios.com/v/product-docs/) | Dashboard, walls, callbacks, FAQ |
| llms.txt | [docs.ayetstudios.com/v/llms.txt](https://docs.ayetstudios.com/v/llms.txt) | Full page list; append `.md` to any product URL |
| Publisher API (OpenAPI 1.19.0) | [www.ayetstudios.com/openapi/publisher-doc](https://www.ayetstudios.com/openapi/publisher-doc) | Static API, Offerwall API, Reward Status, callbacks, HMAC, IPs |
| Static API (product) | […/offerwall/api-integrations/static-api](https://docs.ayetstudios.com/v/product-docs/offerwall/api-integrations/static-api.md) | Cadence, key location, eligible placement/adslot |
| Offerwall API (product) | […/offerwall/api-integrations/offerwall-api](https://docs.ayetstudios.com/v/product-docs/offerwall/api-integrations/offerwall-api.md) | Per-user catalogue. We do not call it. |
| Web Offerwall | […/offerwall/web-integrations/web-offerwall](https://docs.ayetstudios.com/v/product-docs/offerwall/web-integrations/web-offerwall.md) | Hosted iframe / new-tab wall |
| Offerwall callbacks | […/callbacks/offerwall-callbacks](https://docs.ayetstudios.com/v/product-docs/callbacks-and-testing/callbacks/offerwall-callbacks.md) | Macros, chargebacks, 200-or-retry |
| HMAC | […/hmac-security-hash-optional](https://docs.ayetstudios.com/v/product-docs/callbacks-and-testing/callback-verification/hmac-security-hash-optional.md) | `X-Ayetstudios-Security-Hash` recipe + worked example |
| IP whitelist | […/callbacks/ip-whitelist](https://docs.ayetstudios.com/v/product-docs/callbacks-and-testing/callbacks/ip-whitelist.md) | Six firing addresses. Last update **2025-01-30** |
| Callback tester | […/testing/callback-tester](https://docs.ayetstudios.com/v/product-docs/callbacks-and-testing/testing/callback-tester.md) | Dashboard "Send Test Postback" |
| Sandbox identifier | […/testing/sandbox-identifier](https://docs.ayetstudios.com/v/product-docs/callbacks-and-testing/testing/sandbox-identifier.md) | Test offers + `&is_sandbox=1` |
| Failed redirects | […/failed-redirect-destination](https://docs.ayetstudios.com/v/product-docs/callbacks-and-testing/failed-redirects/failed-redirect-destination.md) | Click reject landing + `{reason_code}` |
| Offerwall vs Static | […/faq/offerwall-api-vs-static-api](https://docs.ayetstudios.com/v/product-docs/faq/offerwall-api-vs-static-api.md) | Why they recommend Static only for networks |
| Publisher dashboard | [www.ayetstudios.com](https://www.ayetstudios.com) | Placements / Apps, Account Settings |

---

## Dashboard model

A **Placement** is the app / site / network. Type is frozen at create: Android App, iOS App, Website, or **Network / API**. A **AdSlot** is the integration on that placement (Offerwall, Offerwall API, Static API, Surveywall, Rewarded Video). One placement can carry many adslots. The **AdSlot ID** is what almost every URL wants.

Wrong Placement × AdSlot combo is a hard fail, not a quieter catalogue:

| What we want | Placement type | AdSlot type |
|---|---|---|
| Catalogue sync (our driver) | Network / API (or Website / Android / iOS — they list all four) | **Static API** |
| Hosted wall iframe | Website | **Offerwall** |
| Per-user JSON wall | Android / iOS / Website | **Offerwall API** |
| Surveys JSON | Android / iOS / Website | **Surveywall API** |

Static API key is **adslot-specific** and only appears in AdSlot details after the account manager approves the slot. The account-level **Publisher API key** under Account Settings is a different secret: it signs postbacks and talks to reporting. Pasting the account key into the Static API field returns HTTP 200 with `errorCode` **103**.

Currency conversion rate (`$1 = X` virtual currency) lives on the adslot Overview. Ledger on our side is USD (`payout_usd`). Their `currency_amount` is their rate, not ours. The "Set Currency Conversion Rate API" was removed in v1.16.1 — rates are dashboard-only.

---

## Credentials

Stored on `networks.credentials` (encrypted). Labels come from `credentialSchema()`.

| Our key | Their name | Required | Where they show it |
|---|---|---|---|
| `adslot_id` | AdSlot ID | yes | Placements / Apps → Edit AdSlot. Path segment of `/offers/get/{adslot}`. |
| `api_key` | Static API key | yes | Same modal, **after AM approval**. Not the account key. |
| `publisher_api_key` | Publisher API key | no | Account Settings. HMAC of every callback. Fetches nothing. |

Swapping the two keys breaks both halves and neither error names the other. `verifyPostback()` is open when `publisher_api_key` is blank — a live catalogue can earn for months before anyone finds the account key. The IP allowlist is the floor underneath and **rejects when empty**.

---

## Static API (what we fetch)

Recommended only for network / special integrations: no per-user device match, so some campaigns never appear. Call every **15–30 minutes** so caps, targeting, and bids stay current. We pull the unfiltered catalogue and filter after storing.

```
GET https://www.ayetstudios.com/offers/get/{adslot_id}?apiKey={api_key}
```

No pagination. One response is the whole slot. `fetchOffers()` uses a 180s timeout.

### Optional filters (we do not send)

| Query | Shape | Values |
|---|---|---|
| `countries[]` | ISO-2 array | e.g. `countries[]=US` |
| `platform[]` | array | `android`, `ios`, `desktop` |
| `conversion_type[]` | array | `cpi`, `cpa`, `cpe`, `cpl` |

Older docs mentioned `include_cpe` on Static API (v1.9.1). Offerwall API dropped that parameter in v1.17.0 — CPE is always returned. We do not send it.

### Envelope

Success:

```json
{
  "status": "success",
  "num_offers": 3,
  "support_url": "",
  "offers": [ { "id": 218592 } ]
}
```

Failure is often **HTTP 200**. A wrong key, a wrong adslot, and a disabled account all look like that. `status` is the literal string `error` on every one of them. The sentence is in `errorMessage`; the code their AM asks for is `errorCode`.

Live misconfiguration we have recorded:

```json
{
  "status": "error",
  "errorCode": 103,
  "errorMessage": "Unknown adslot 28833, invalid adslot type or adslot api key incorrect."
}
```

`testConnection()` and `fetchOffers()` both require `status === success`. Treating a 200-with-error as "connected, 0 offers" would green the button on a typo and then sweep every stored Ayet offer off (the collapse guard only fires above twenty). `fetchOffers()` throws so the reason lands in `networks.last_sync_error`.

HTTP 400 / 401 / 429 exist on Offerwall API. Static API docs only show 200. We still treat a failed status line as a connection failure.

### Offer row

Official example tracking link (OpenAPI 1.19.0):

```
https://www.ayetstudios.com/s2s/pub/{offer_id}/{…}/{…}/{adslot}?external_identifier={external_identifier}
```

Older / fixture shapes the driver also sees:

```
https://www.ayetstudios.com/offers/click/{id}/USER_ID_HERE
https://www.ayetstudios.com/offers/click/{id}?adslot={adslot}&external_identifier=[USER_ID]
```

| Their field | Type | What we do |
|---|---|---|
| `id` | int | `OfferDTO.externalId`. Skipped if missing. |
| `name` | string | Name. Fallback `"Untitled offer"`. |
| `tracking_link` | string | Tracking URL. We rewrite user placeholders and append `custom_1={click_id}`. |
| `payout_usd` | float | USD. Used only when `tasks` is empty. |
| `tasks[]` | array | Goals. Offer payout = **sum of `tasks[].payout`**. `isMultiReward` when more than one. |
| `tasks[].name` | string | Goal name. |
| `tasks[].payout` | float | Goal USD. |
| `tasks[].uuid` | string | Persistent task id. **Not stored.** |
| `tasks[].event_name` | string | Internal event. **Not stored.** |
| `tasks[].currency_amount` | float | Their VC. **Ignored.** |
| `tasks[].conversion_limit` | int | **Not stored.** |
| `tasks[].single_conversion_per_day` | bool | **Not stored.** |
| `tasks[].type` | string | `normal` / `retention` / `purchase`. Replaces deprecated `bonus_task`. **Not stored.** |
| `tasks[].i18n` | object | **Not read.** |
| `description` | string | Description. If blank, fall back to `introduction`. |
| `introduction` | string | Fallback description; also second instruction line. |
| `conversion_instructions_short` | string | First instruction line. |
| `conversion_instructions` / `_long` | string | **Not read.** |
| `rules_requirements` | string | **Not read.** |
| `icon` | string | Icon. |
| `icon_large` | string | **Not read.** |
| `video_url` / `video_url_vp9` | string | **Not read.** |
| `countries` | ISO-2[] | Uppercased. `[]` = worldwide. |
| `state_targeting` | US ISO state[] or `null` | US-only restriction. Clicks from other US states fail with **`[#325]`**. **Not stored.** |
| `platforms` | string[] | Device targeting. Preferred. |
| `platform` | string | Single-platform offers carry this instead of `platforms`. |
| `devices` | string[] | `phone` / `tablet` / `pc` / `mac`. **Not read** — we use `platforms` / `platform`. |
| `devices_whitelist` / `devices_blacklist` | array | **Not read.** |
| `tags.categories` | string[] | Wall categories. Preferred. |
| `tags.tab` / `tags.tasks` | string / string[] | **Not read.** |
| `conversion_type` | string | `cpi` / `cpa` / `cpe` / `cpl`. Our `conversionType`. Also a category fallback. |
| `category` | string | Shelf (`incent`, …). Category fallback when tags are empty. |
| `currency_amount` | float | Their VC at the adslot rate. **Ignored.** |
| `epc` | number or `"new"` | **Not stored.** |
| `daily_cap` | int | **Not stored.** |
| `impression_url` | string | 1×1 GIF. Still contains `{external_identifier}`. **Not fired.** |
| `store_id` | string | Package / bundle id. **Not stored.** |
| `landing_page` | string | **Not stored.** |
| `conversion_time` | int | Seconds. **Not stored.** |
| `max_conversion_time` | int | Seconds **since install** (clarified v1.16.1). **Not stored.** |
| `created` / `start_date` / `end_date` | datetime | **Not stored.** |
| `offer_owner` / `score` / `offer_complexity` | mixed | **Not stored.** |
| `payment_required` | bool | **Not stored.** |
| `kpi.arpu_d1…d90` / `iap_iaa_ratio` | float? | Replaces deprecated `roas_*`. **Not stored.** |
| `i18n` | object | `en` `de` `es` `fr` `pt` `ru` `ja` `ko` `th` `zh` `id` `it`. **Not read.** |
| `min_android_version` / `max_android_version` | string? | **Not stored.** |
| `min_ios_version` / `max_ios_version` | string? | **Not stored.** |
| `browser_requirements[]` | `{browser, min_version, max_version}` | **Not stored.** |
| `has_installation_callback` | bool | Whether an install optional-callback can fire. **Not stored.** |
| `publisher_cpi_compensation` | bool | Publisher paid on CPI even if the campaign is not. **Not stored.** |
| `rating` / `screenshots` | mixed | **Not stored.** |
| `support_url` | string | Per-offer and top-level. **Not stored.** |

A task-based offer's `payout_usd` is **not** the authority — completing every task is what the offer is worth, so we sum `tasks[].payout`.

---

## Tracking links

`custom_1`…`custom_5` can be appended to any `tracking_link`. They echo on the callback as `{custom_1}`…`{custom_5}`. Same five keys also take `age` (integer years) and `gender` (`male` / `female` / `non_binary`).

`external_identifier` on the link is their **persistent user** slot. It keys Reward Status. Publishers who cannot supply a stable one may use `{DYNAMIC_USER}` after AM sign-off (v1.13.1). A fresh UUID in that slot tells Ayet every click is a new user and per-user caps pay once.

Our click id is a fresh UUID per tap. It rides in **`custom_1`**, which they document as "custom parameter to pass variables to the conversion callbacks" and give no meaning of their own.

`buildTrackingUrl()` is only handed the click id, so Ayet's user placeholder is filled with that same UUID — least-bad option so two clicks are not the same user to them. Attribution does **not** depend on it. `parsePostback()` reads `custom_1` first, then `external_identifier` as a fallback for in-flight clicks made before `custom_1` existed (conversion windows run to weeks).

Placeholders `withClickIdMacro()` rewrites today:

| Literal on the link | Becomes |
|---|---|
| `user=1` | `user={click_id}` |
| `[USER_ID]` / `[user_id]` | `{click_id}` |
| `USER_ID_HERE` | `{click_id}` |
| `{{userid}}` | `{click_id}` |

Then, if `custom_1={click_id}` is not already present, it is appended. A stray `custom_1` already on the link is appended after rather than rewritten — last key wins on PHP.

**Gap:** live OpenAPI examples use `external_identifier={external_identifier}`. That token is **not** in the rewrite table. Those links keep the literal braces and still get `custom_1={click_id}`. Attribution still works. Ayet sees every click as user `{external_identifier}` unless we add that token later (or start passing a real publisher user id, which this method does not have).

`{DYNAMIC_USER}` is also not rewritten.

Stored click URL the user is sent:

```
{their tracking_link with placeholders replaced}&custom_1={click_id}
```

---

## Impression pixel

Each offer can carry `impression_url`, a 1×1 GIF. Fire it when the creative is actually shown (or scrolled into view). Static API URLs still contain `{external_identifier}` — substitute the user. Offerwall API already substitutes it.

```
https://www.ayetstudios.com/offers/shown/{offer};…/img.gif?external_identifier={external_identifier}
```

We do not fire it. They use it to optimise the slot.

---

## Offerwall API (we do not call)

Per-user catalogue. Better match, more campaigns than Static. Server **or** client.

```
GET https://www.ayetstudios.com/offers/offerwall_api/{adslot}
    ?external_identifier={stable_user}
    &user_agent=…
    &client_hints=…
    &ip=…          # required when called server-side
```

Recommended combo: `[ip] + user_agent + client_hints`. `client_hints` only exists in a secure browser context (`navigator.userAgentData.getHighEntropyValues(...)`).

Alternatives: `ip + device_make + device_model + os + os_version` (mobile) or `ip + browser + browser_version` (desktop).

Also: `gaid`, `idfa`, `custom_1`…`custom_5`, `request_live_networks`, `num_offers`, `offer_sorting` (`payout` / `conversion_rate` / `epc` / `ecpm`), `minimum_payout`, `include_mobile_offers` (default false — include mobile offers on a desktop request), `language`, `age`, `gender`.

CPE is always in the response (v1.17.0). Extra fields vs Static: `offer_status` (`new` / `started` / `in_progress`), `offer_status_days_left`, `cpe_instructions[]` with `status` (`available` / `unavailable` — `locked` was removed), `remaining_time`, `require_reservation`, virtual-currency `payout` / `payout_name` / `button_text`, `offerwall.currency_sale`.

HTTP 400 / 401 / 429 are documented. Placement × AdSlot must be Offerwall API.

---

## Reward Status API

Progress for every campaign the user has clicked, until roughly a year after max conversion time expires.

```
GET https://www.ayetstudios.com/rest/v1/userSupport/get_reward_status
    ?placementId={placement_id}
    &externalIdentifier={external_identifier}
    &language=en
```

`external_identifier` is the **stable user**, not our click UUID. That is why stuffing the click id into their user slot also breaks this API. The older CPE status endpoint is deprecated (v1.14.12). We do not call either.

---

## Web Offerwall (partner wall tab)

Website placement + Offerwall adslot. Open in a new tab or iframe. Not meant for in-app webview — link out to a real browser.

```
https://offerwall.ayet.io/offers?adSlot={ADSLOT_ID}&externalIdentifier={YOUR_USER_IDENTIFIER}
```

`externalIdentifier` is camelCase on this URL. Minimum **3 characters**. Mandatory. That is the player, echoed as `{external_identifier}` on the callback.

| Their key | Required | Our macro | Why |
|---|---|---|---|
| `adSlot` | yes | literal AdSlot ID | Not `{adslot}` — copy from Edit AdSlot → Integration URL. |
| `externalIdentifier` | yes | `{user_id}` | Publisher userid. Constant per player. |
| `custom_1` | no for them, **yes for us** | `{sub_id}` | Embed click UUID. This is `PostbackDTO.clickId`. |

Paste into **Networks → Hosts its own wall → Partner wall URL**:

```
https://offerwall.ayet.io/offers?adSlot=YOUR_ADSLOT_ID&externalIdentifier={user_id}&custom_1={sub_id}
```

Do not put `{sub_id}` in `externalIdentifier`. Do not put either API key on this URL. Opening (`?open=1`) writes one embed `offer_clicks` row (`offer_id` null). Its `click_id` is `{sub_id}`.

Sandbox: put a dashboard Sandbox Identifier in `externalIdentifier`. The wall shows three test offers. Completing one fires a real-shaped callback with fake money and `&is_sandbox=1`.

---

## Postback

```
https://{host}/postback/ayetstudios
```

Paste that, **with macros**, into Placements / Apps → Edit Placement → Callback URL. They GET it. Parameters that are not on the template are not sent. They do **not** append a default set (changed in v1.3, 2017-10-28).

**Required on our template** (every one `parsePostback()` reads):

```
https://{host}/postback/ayetstudios
    ?callback_type={callback_type}
    &transaction_id={transaction_id}
    &is_chargeback={is_chargeback}
    &payout_usd={payout_usd}
    &custom_1={custom_1}
    &external_identifier={external_identifier}
    &offer_id={offer_id}
    &offer_name={offer_name}
    &ip={ip}
```

Answer **HTTP 200**. Body `ok` (what `PostbackController` already returns). Any other status they resend **12 times over one hour** then give up.

That makes 403 (bad IP, bad HMAC, network not active) and 422 (unparseable) a retry storm, not a quiet reject. An unattributable postback is deliberately **not** one of those paths: missing `custom_1` still parses, click id is null, we answer 200, and the row waits in `incoming_postbacks` for an admin to attach.

Enable **Reversal Callbacks** on the placement or chargebacks never leave their dashboard. Optional separate Reversal Callback URL; empty = same Conversion Callback URL.

### Chargebacks

They send all three at once. We believe **any one**:

| Signal | Credit | Chargeback |
|---|---|---|
| `{callback_type}` | `conversion` | `chargeback` |
| `{is_chargeback}` | `0` | `1` |
| `{payout_usd}` / `{currency_amount}` | positive | **negative** |
| `{transaction_id}` | `381f9933…` | `r-381f9933…` |

`reverse()` matches `network_txid` exactly. We strip a single leading `r-` only when we already decided it is a reversal, so the chargeback meets the credit. A credit whose own id happens to begin `r-` stores as `r-X`; its chargeback arrives as `r-r-X`; they still meet.

Also on chargebacks: `{chargeback_reason}`, `{chargeback_date}`.

Reading a chargeback as a credit pays the publisher twice. Reading a credit as a chargeback finds no row and logs it. Asymmetry settles it: any one signal reverses.

### Shared `{transaction_id}`

One user event can fire the Conversion Callback **and** one or more Optional Callbacks at the same time. They share one `{transaction_id}`. We key credits on `(network_id, network_txid)`, so siblings collapse into one credit. That is the direction to err: under-credit is a ticket, over-credit is a refund.

Do not widen the key with `callback_type` — a chargeback's type is `chargeback` where its credit's was `conversion`, and the reverse would never meet.

### Optional callbacks (we do not want these on our URL)

Independently enabled per placement, each with its own URL (falls back to Conversion Callback URL).

| Type | `{callback_type}` | Money | When |
|---|---|---|---|
| Installation | `installation` | none | `INSTALLATION_TRACKED`, even if the task is hidden / unpaid |
| Optional conversion | `optional` | `payout_usd=0` | Visible unpaid task |
| IAP | `iap` | `{event_value}` = purchase | In-app purchase |
| IAA | `iaa` | `{event_value}` = ad rev | In-app ad |

`{callback_type}` values on conversion URL: `conversion`, `chargeback`. On optional URLs: `installation`, `optional`, `iap`, `iaa`. Product-docs offerwall page also lists `survey`. Leave optional types **off** (or pointed at a URL we do not parse) so they cannot share a transaction id with a paid conversion on our endpoint.

### Conversion macros (OpenAPI + product docs)

Every macro also has `{name_sanitized}`: keep `0-9 a-z A-Z - _`, whitespace → `_`.

| Macro | Type | What we do |
|---|---|---|
| `{transaction_id}` | string | Credit: `networkTxId`. Required. Duplicate key. Chargeback: original id with `r-` prepended. |
| `{payout_usd}` | float | USD. Required, numeric. Negative on chargeback. Sign lives in status; we store `±abs`. |
| `{callback_type}` | string | `conversion` / `chargeback` (/ optional types). One of three reverse signals. |
| `{is_chargeback}` | 0\|1 | Second reverse signal. |
| `{custom_1}` | string | **Our click id.** What `buildTrackingUrl()` wrote. |
| `{external_identifier}` | string | Fallback click id (legacy). Offerwall API: user passed at fetch. Static API OpenAPI text says "value of the `sub_id` parameter appended to the tracking link" — product docs say "user originally passed on the wall". Treat as **their user slot**, not our player. |
| `{offer_id}` | int | `offerExternalId` |
| `{offer_name}` | string | Needed on embed (no offer of ours). |
| `{ip}` | string | Conversion IP, or `0.0.0.0`. |
| `{user_id}` | int | **Ayet's** internal user id. **Not copied.** Processor uses the click's `end_user_id` (publisher identity). Filling this would overwrite it with an id that matches nothing we hold. |
| `{currency_amount}` | float | Their VC. Negative on chargeback. **Ignored.** |
| `{placement_identifier}` | string | Placement slug. Ignored. |
| `{adslot_id}` | int | Which adslot converted. Use if one callback URL serves wall + video. Ignored. |
| `{sub_id}` | string | Placement id `PL-1…n`. **Not** our `{sub_id}` / click id. Ignored. |
| `{device_uuid}` / `{device_make}` / `{device_model}` | string | Ignored. |
| `{advertising_id}` | GAID/IDFA | Ignored. |
| `{sha1_android_id}` / `{sha1_imei}` | string | Ignored. |
| `{chargeback_reason}` / `{chargeback_date}` | string | Only when `is_chargeback=1`. Not stored. |
| `{event_name}` | string | CPA/CPE internal event. Ignored. |
| `{event_value}` | float | Non-billable (IAP/IAA). Ignored — money is `payout_usd`. |
| `{task_name}` / `{task_uuid}` | string | CPE step. Ignored. |
| `{currency_identifier}` | string | Adslot VC name. Ignored. |
| `{currency_conversion_rate}` | decimal | Rate used for `currency_amount`. Ignored. |
| `{click_date}` | `Y-m-d H:i:s` | Ignored. |
| `{callback_ts}` | int | Unix time of the event. Ignored. |
| `{custom_2}`…`{custom_5}` | string | Ignored unless we start writing them. |
| `{is_sandbox}` | 1 | **Not a documented macro** — sandbox fires append `&is_sandbox=1` on the wire. We do not special-case it. Discard in admin if a test lands. |

Surveywall-only extras (same endpoint if you point a survey slot here): `{is_screenout}`, `{is_survey}`, `{survey_id}`, `{loi}`. We do not integrate Surveywall.

### Jinja2 snippets on their template

They evaluate these **before** the GET, on their side:

```
{%variable==value?true_result:false_result%}
```

Operators: `==` `!=` `>` `<` `>=` `<=`. Missing / invalid → empty string.

```
is_install={%event_name==INSTALLATION_TRACKED?1:0%}
{%is_chargeback==1?&chargeback_callback=true:%}
tier={%payout_usd>10?high:low%}
```

We do not need them if the template above is pasted whole.

---

## HMAC (`X-Ayetstudios-Security-Hash`)

Always sent. Optional to verify. Secret is the **account** Publisher API key, not the Static API key.

Documented recipe (their PHP, which is what `verifyPostback()` does):

1. Take every query parameter (`$_GET` / `$request->all()`). The hash header is **not** a parameter.
2. `ksort($params, SORT_STRING)`.
3. `http_build_query($params, '', '&')` — RFC 1738, so space → `+`.
4. `hash_hmac('sha256', $sortedQueryString, $publisherApiKey)`.
5. Compare to header `X-Ayetstudios-Security-Hash` (`HTTP_X_AYETSTUDIOS_SECURITY_HASH`).

Worked example from their HMAC page:

```
transaction_id=8ee08f32ae611231b0a49d1bd66e9bf193132561
amount=0.10
payout=1.50
user_id=testuser123456
click_id=1234abcd5678021
offer_name=TEST OFFER
```

Sorted, values encoded:

```
amount=0.10&click_id=1234abcd5678021&offer_name=TEST+OFFER&payout=1.50&transaction_id=8ee08f32ae611231b0a49d1bd66e9bf193132561&user_id=testuser123456
```

Key `9f2228fea0d8e7ce10b2ac36053db14c` →

```
62a32725866780ada1dec3d62232645f2801e05a91df7b0202e9b780f804f04b
```

A wrong recipe 403s every fire and looks like downtime. Escape hatch: clear `publisher_api_key` and the network runs on the allowlist alone.

---

## IP allowlist

Seeded on the `ayetstudios` row. Last published **2025-01-30**. Check their page when they bump it — a partial list is the failure that costs money: the network looks fine and the conversions from the missing addresses are rejected at the door.

```
51.79.101.241
158.69.185.134
158.69.185.154
35.165.166.40
35.166.159.131
52.40.3.140
```

Three OVH + three AWS. An earlier seed with only the AWS three dropped the OVH fires. Empty list rejects everyone.

Their Callback Tester and sandbox fires come from these same addresses.

---

## Testing

**Callback Tester** (Edit Placement): send a synthetic fire with the macros on the template. Some fields are editable, some locked. Then Reports → Conversions → Details shows the populated URL. A tester fire with `payout_usd=0` still hits us — `parsePostback()` accepts it if `transaction_id` is present.

**Sandbox Identifier** (Edit Placement): a user id you choose (e.g. `testuser123`). Pass it as `externalIdentifier` on the wall (or as the Static API user placeholder). Wall shows three test offers. Earn button fires a callback with fake payout / VC and `&is_sandbox=1`. Avoids their fraud blocks so you can test across devices and GEOs.

---

## Failed redirects

Clicks that do not reach the store (VPN, wrong GEO, device, quality, state `[#325]`) land on their "Share Offer" error page unless the placement sets **Failed Redirect Destination**:

```
https://yours/redirect?user_id={external_identifier}&offer_id={offer_id}&reason_code={reason_code}&reason_message={reason_message}
```

We do not host this. Useful when diagnosing why a tracking link never converts.

---

## Currency, sales, blacklist

- All Ayet money is USD. VC rate is `$1 = X` on the adslot. Example they use: `$1 = 1000` coins and a 40% user share → set **400**.
- Currency sale: 1.3×–2× multiplier, per placement, optional GEO include/exclude, schedule. Dashboard Overview → Currency Sale. Platform-wide holiday sales via their AM. Offerwall API exposes `offerwall.currency_sale`.
- Blacklist: Reports → Offers → Blacklist, per placement or `(global)`.
- Ayet's in-wall User ID (orange number under Reward Status) is `{user_id}` on the callback — theirs, not the publisher's.

---

## Surveywall / Rewarded Video / Reporting (out of scope)

Recorded so we do not confuse them with the offer catalogue.

- **Surveywall API:** `GET /surveys/surveywall_api/{adslot}?external_identifier=…` plus optional profiling `q{id}=…&hash=HMAC-SHA256` (secret = AdSlot "Profiling Data Validation Hash"; same sort + `http_build_query` recipe). SDK: `https://cdn.ayet.io/offerwall/js/ayetsurveywallapisdk_source.js`.
- **Rewarded Video callbacks:** same placement Callback URL; enable "Send S2S Callbacks" on the video adslot. Distinguish with `{adslot_id}`. No chargeback macros on the video page.
- **Publisher Reporting API:** `GET /api2/publisher/reporting?startDate=&endDate=&apiKey=` — **account** key. 20 calls / hour.
- **Cohorted KPI:** `GET /rest/v1/publisher/campaign/cohorted_kpi?apiKey=&campaign_id=` — 120 / 5 min, max 31-day window.
- **ads.txt:** `GET /api2/publisher/placements/get_ads_txt?placementId=&hash=`.
- Live API: removed (v1.9.9). Do not call it.

---

## What we do not handle

- Offerwall API / Surveywall API / Reward Status / impression pixel.
- `{DYNAMIC_USER}` and the live `{external_identifier}` placeholder (see Tracking).
- `state_targeting`, OS/browser version gates, device white/black lists.
- `currency_amount` / their conversion rate / currency sales.
- Optional callbacks (`installation` / `optional` / `iap` / `iaa`).
- `{is_sandbox}` discard.
- HMAC when `publisher_api_key` is empty (allowlist only).
- Their "hide if this user already completed it" — we hide on **our** approved `(end_user_id, offer_id)`.

---

## Live-fire checklist

1. Placement type + AdSlot type = **Static API**. AdSlot approved; Static API key visible in the modal.
2. Network form: `adslot_id` + Static API key. Test connection counts `offers` and fails on `errorCode` 103 (wrong key / wrong slot type).
3. `publisher_api_key` = Account Settings key (HMAC). Optional until you want the second gate.
4. `postback_ips` = all six addresses. Do not trim.
5. Placement Callback URL = the template in **Postback** above. Enable Reversal Callbacks. Leave optional callback types off.
6. Sync: USD rows appear; multi-task payout is the sum of tasks; `custom_1={click_id}` is on every stored link.
7. Click an offer: outbound URL contains `custom_1=<uuid>`.
8. Callback Tester: same uuid in `custom_1`, `transaction_id` set, `payout_usd` numeric, source one of the six IPs, `X-Ayetstudios-Security-Hash` valid if the publisher key is set. Expect `accepted` and body `ok`.
9. `unknown_click` means `custom_1` (and the `external_identifier` fallback) missed our UUID. Fix the wall / click template, not `ConversionProcessor`.
10. Embed: iframe has `externalIdentifier={user_id}` and `custom_1={sub_id}`. Repeat step 8.
11. Chargeback test: `callback_type=chargeback` + `is_chargeback=1` + negative `payout_usd` + `transaction_id=r-{original}`. Expect reverse of the credit.
12. Sandbox: identifier in `externalIdentifier`, three test offers, callback has `is_sandbox=1` — attach or discard by hand; it is still a 200.
