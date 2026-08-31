# Monlix (`monlix`)

Working notes for Monlix: hosted offerwall, static catalogue, click tracking, and S2S postbacks. Driver: `app/Networks/Drivers/MonlixDriver.php`. Fixture: `tests/Fixtures/Networks/monlix-offers.json`.

Fetched 2026-08-18 from [docs.monlix.com](https://docs.monlix.com/) — every sidebar page opened, Next.js page bundles dumped, tables and code samples copied from the live DOM. The site is a static Nextra export (`buildId` `HEiIWFwXcT5BmiYD8zaLJ`). There is no `llms.txt` and no sitemap.

Catalogue and postback both follow these pages. Token recipe, `/api/static` envelope, nested `goals[].payouts[0].payout`, and a postback URL we write with their `{{macros}}`. They authenticate by echoing the dashboard secret; ClaimBits / Vie compare that string. There is no HMAC.

Their pages:

| Page | URL | What it is |
|---|---|---|
| Welcome | [docs.monlix.com](https://docs.monlix.com/) | Index. Web walls, Android/iOS SDKs, postbacks, FAQs. |
| Get Started | [docs.monlix.com/docs/get-started](https://docs.monlix.com/docs/get-started) | One paragraph. Account + approved site required. Nothing else. |
| Postback (S2S params) | [docs.monlix.com/docs/postback](https://docs.monlix.com/docs/postback) | Macro table only. No HTTP method, no hash, no IPs, no retries. |
| FAQs | [docs.monlix.com/docs/faq](https://docs.monlix.com/docs/faq) | Currency, multiplier, approval, payout terms, contact. |
| HTML5 Integration | [docs.monlix.com/integrations/html-5-integration](https://docs.monlix.com/integrations/html-5-integration) | Hosted wall iframe. |
| JSON API Integration | [docs.monlix.com/integrations/api-integration](https://docs.monlix.com/integrations/api-integration) | Per-user `/api/campaigns`. Virtual-currency payouts. **We do not call this.** |
| Static API Integration | [docs.monlix.com/integrations/static-api-integration](https://docs.monlix.com/integrations/static-api-integration) | Bulk USD catalogue. **This is `fetchOffers()`.** |
| Custom Offers API | [docs.monlix.com/integrations/custom-offers-api-integration](https://docs.monlix.com/integrations/custom-offers-api-integration) | Whitelisted subset. Same row shape as static. Extra AM token. **We do not call this.** |
| Survey API | [docs.monlix.com/integrations/survey-api-integration](https://docs.monlix.com/integrations/survey-api-integration) | Per-user surveys. Different schema. **We do not call this.** |
| Android SDK | [docs.monlix.com/integrations/android-sdk](https://docs.monlix.com/integrations/android-sdk) | Native wall. Not our product. |
| iOS SDK | [docs.monlix.com/integrations/ios-sdk](https://docs.monlix.com/integrations/ios-sdk) | Native wall. Not our product. |
| ClaimBits | [docs.monlix.com/integrations/claim-bits](https://docs.monlix.com/integrations/claim-bits) | Third-party GPT script. Best official example of a postback URL + secret check. |
| Vie Faucet | [docs.monlix.com/integrations/vie-faucet](https://docs.monlix.com/integrations/vie-faucet) | Same, plus chargeback (`status == 2`) and `DUP` body. |
| Shiftcode | [docs.monlix.com/integrations/shiftcode](https://docs.monlix.com/integrations/shiftcode) | GPT admin steps. Screenshot host is dead. |
| Marketing / dashboard | [monlix.com](https://monlix.com/) | Sites page (API key), Postback tab (secret + callback URL). Login-gated. |
| Contact | `support@monlix.com` or live chat on the site | Approval, AM token, firing IPs. |

---

## Credentials

Stored on `networks.credentials` (encrypted). Admin labels come from `credentialSchema()`.

| Our key | Their name | Required | Where they show it |
|---|---|---|---|
| `api_key` | API key / App ID / `appid` / `[your_app_id]` | yes | Dashboard → **Sites**. Same value on the iframe, every catalogue hop, and the click redirect path. Visible to end users on the wall URL. |
| `secret_key` | Secret key / secret / `{{secretKey}}` | yes | Dashboard → **Postback** tab. Signs nothing on the catalogue. Compared as a plaintext query value on the postback. |

The two are not interchangeable. The API key in `secret_key` makes every catalogue token wrong (`md5(apiKey + secretKey)`). The secret on the iframe URL leaks the only thing that authenticates a conversion.

Keys appear only after the site is **approved** (FAQ: maximum 3 business days). There is no documented expiry.

A third value exists only on the Custom Offers API: `token` = `apitoken_provided_by_account_manager`. We do not store it. Static auth does not use it.

Historical rename: `app_id` → `api_key`, `secret` → `secret_key`. Migration `2026_01_06_000001_move_renamed_monlix_credentials.php` moves live rows. Old keys left in place fail closed (blank `secret_key` rejects every postback).

---

## Hosts

| Host | Role |
|---|---|
| `api.monlix.com` | Catalogue, click redirect, surveys. |
| `offers.monlix.com` | Hosted offerwall (iframe). |
| `imps.mnlx.me` | Image proxy. Path is `/i/{base64(original url)}`. |
| `redirect.mnlx.me` | Survey click redirect (Survey API `link` only). |
| `monlix.com` | Dashboard / marketing. |
| `static.vnative.co` | Third-party icons on the **JSON campaigns** sample. Not on static rows. |
| `docs.monlix.com` | This documentation. |

No documented IP allowlist for either direction. Empty `postback_ips` still **rejects**. Either paste the AM's firing addresses, or turn on **Allow any source IP** (signature is then the only gate). Our egress IP is not documented as needing an allowlist (unlike HangMyAds).

---

## Get started / approval

The Get Started page is a heading, one sentence, and a callout:

> In order to use any of our services you will need a registered account, and approved sites or apps by our team.

FAQ fills the rest:

- Saving a site starts approval. **Maximum 3 business days.** After that, API key and secret appear.
- Settings can be changed later.
- **Virtual currency** = plural name shown on their wall (`USD`, `$`, `Points`, `Credits`).
- **Currency multiplier** = how many of *their* units a user earns per **$1.00** of conversion. `rewardValue` on the postback is `payout (USD) * multiplier`. Our ledger is USD — ignore `rewardValue`.
- Publisher payouts: **NET30**, minimum **$20**, automatic around the **15th**. Methods: Payeer, FaucetPay, AirTM, Bitcoin.

---

## Transport (what we call)

```
GET https://api.monlix.com/api/static?appid={api_key}&token={md5(api_key + secret_key)}
```

One unpaginated dump. Sample envelope `total` is **5919**. No `page`, no cursor, no `next`. `fetchOffers()` sets timeout **180** seconds for that reason. `testConnection()` uses the default 30s client — a slow first hop can fail the admin button while sync still works.

**Token.** Documented recipe, printed as a code block:

```
md5(apiKey + secretKey)
```

Concatenation. No separator, no colon, no hex prefix. PHP `md5()` of the two credential strings in that order. The secret is "the one you have on dashboard under postback tab".

User-Agent we send: `AdVault/1.0`. Not documented as required.

**Rate limit:** not documented.

**Errors:** not documented. No sample 401/403 body. `testConnection()` treats any failed HTTP as `"Monlix returned HTTP {status}."` and counts `campaigns` on success.

Envelope they print:

```json
{
   "total": 5919,
   "campaigns": [ { "id": 63261 } ]
}
```

The fixture wraps that in `"status": "success"`. The live docs do not. `rowsFrom()` is not involved — the driver reads `json('campaigns')` only. Extra keys are ignored.

---

## HTML5 Integration (their hosted wall)

Separate product from the API. They host the tiles; we frame them. Conversions still come back on the same postback.

```html
<iframe src="https://offers.monlix.com/?appid=[your_app_id]&userid=[your_user_id]&subid=[your_sub_id]"
        style="width: 100%; height: 800px; margin: 0; padding: 0; border: 0;" />
```

Callout: do not keep the `[` `]` brackets.

The prose only explains two of the three query keys. `subid` is on the iframe and on the postback table, and nowhere else on this page.

| Parameter | Required | Their meaning |
|---|---|---|
| `appid` | yes | Sites page API key. |
| `userid` | yes | "unique identifier of your user on your website" — keep it constant so they can track stats and pick surveys/offers. |
| `subid` | not explained here; **yes for us** | Echoed as `{{subId}}`. This is our click UUID. |

Paste this into **Networks → Hosts its own wall → Partner wall URL**:

```
https://offers.monlix.com/?appid=YOUR_APP_ID&userid={user_id}&subid={sub_id}
```

| Their key | Our macro | Why |
|---|---|---|
| `userid` | `{user_id}` | Publisher `userid` from our wall iframe. Constant per player. |
| `subid` | `{sub_id}` | Embed click UUID. This is `PostbackDTO.clickId`. |

Do not put `{sub_id}` in `userid`. That slot is the player. Do not put `secret_key` on this URL.

`appid` **is** visible in the iframe `src`. The Filament helper text says never put a key on `wall_url`. For Monlix the app id is designed to be public — it is on every wall and every click redirect. The secret is not.

ClaimBits and Vie Faucet samples omit `subid` on the iframe. That makes every conversion `UnknownClick` on our side. Do not copy those snippets.

Opening (`?open=1`) writes one embed `offer_clicks` row (`offer_id` null). Its `click_id` is `{sub_id}`.

---

## Static JSON API (our catalogue)

> Static API allows you to bulk import all of Monlix's offers for approved apps/games/websites. This API does not do any manipulation to virtual currencies, and does not handle removal of the offers once they are completed by specific users, though they are blocked on redirect on our side if they try to do same offer multiple times.

That paragraph is the whole product difference vs `/api/campaigns`:

- Payouts are **USD numbers**, not wall Points.
- The list is **not** filtered per user. A completed offer stays in the dump.
- They still block a second click on their redirect.
- Token auth is required (device-side campaigns API has none).

Callout on the page: CPE steps live under `goals`.

### Offer row (static / custom — same shape)

| Their field | Type in the sample | Their use | What we do |
|---|---|---|---|
| `id` | number | Campaign id | `OfferDTO.externalId`. Skipped if missing. |
| `name` | string | Display name | Offer name. Fallback `"Untitled offer"`. |
| `description` | string | Instructions. May contain HTML entities (`S&#039;Inscrire`) and `\\r`. | Description. |
| `countries` | string[] | ISO-2, already uppercased in the sample (`TH`, `US`, `GB`) | `parseCountries()`. `[]` = worldwide. |
| `browsers` | string | `"all"` on every printed row | **Not read.** |
| `oss` | string | `"android"` / `"ios"` / `"all"` | **Not read.** We use `os`. |
| `os` | string | Duplicate of `oss` on every printed row | Device targeting via `parseDevices()`. |
| `payout` | number | USD. `0.08`, `23.44`, `0.3` (no trailing zeros required) | Offer payout. |
| `hasGoals` | boolean | `true` when `goals` is non-empty | **Not read.** `isMultiReward` is `count(goals) > 1`. |
| `goals` | array | CPE steps. Empty array when `hasGoals` is false | See Goals. |
| `categories` | string[] | Free labels: `Android`, `CPI`, `Free`, `Gambling`, `Lifestyle`, `Gaming`, `Offer`, `Entertainments`, `Social Casino`, `Social Media`, `Finance`, `Technology`, `Video` | Stored verbatim. |
| `image` | string | `https://imps.mnlx.me/i/{base64}` | Icon. |
| `multipleTimes` | boolean | `false` on every printed row. Completing twice is blocked on redirect even so. | **Not read.** We hide on our approved `(end_user_id, offer_id)`. |
| `url` | string | Click redirect. Contains `{{userid}}`. | Tracking URL. We append `&subid={click_id}`. We do **not** rewrite `{{userid}}`. |
| `total` (envelope) | number | Catalogue size | **Not read.** |

No `campaignId`, `currency`, `cr`, `featured`, `createdAt` on static rows. Those exist only on `/api/campaigns`.

### Goals (static)

```json
{
  "id": "d8d29f7c-ca3d-11ed-873e-06a99418fcc3",
  "name": "Completed",
  "payouts": [
    {
      "payout": 0.08,
      "geo": ["all"]
    }
  ],
  "hash": "47823ecd63297ff6c2ac9251b21337fff99e8640"
}
```

| Their field | What we do |
|---|---|
| `id` | **Not stored.** UUID in the sample. |
| `name` | Goal name. Blank name drops the step. |
| `payouts[0].payout` | Goal payout. Dot path `payouts.0.payout` in `goalList()`. |
| `payouts[0].geo` | **Not read.** |
| `payouts[n]` n>0 | **Not read.** Only index 0. |
| `hash` | **Not read.** |

`isMultiReward` when more than one goal survives the array filter. A single `"Completed"` step is not multi-reward.

The JSON **campaigns** API flattens this to `{ id, name, payout }` (no `payouts` wrapper, values in Points). Pointing `fetchOffers()` at that endpoint would store `0` for every goal.

### Complete static row (CPE)

Copied from their sample. First of 17 printed campaigns; `total` claims 5919.

```json
{
  "id": 63261,
  "name": "Shopee 4.4 Crazy Flash Sale",
  "description": "Run the app (new users only) Confirms Instantly",
  "countries": ["TH"],
  "browsers": "all",
  "oss": "android",
  "payout": 0.08,
  "hasGoals": true,
  "goals": [
    {
      "id": "d8d29f7c-ca3d-11ed-873e-06a99418fcc3",
      "name": "Completed",
      "payouts": [{ "payout": 0.08, "geo": ["all"] }],
      "hash": "47823ecd63297ff6c2ac9251b21337fff99e8640"
    }
  ],
  "categories": ["Android", "CPI", "Free"],
  "image": "https://imps.mnlx.me/i/aHR0cHM6Ly9tYWluLXAuYWdtY2RuLmNvbS9vZmZlcnMvNDE1ODAzLVZZWFBxVmpELnBuZw==",
  "multipleTimes": false,
  "url": "https://api.monlix.com/api/cmp/redirect/74c6f10c7a5c5088dcecbf0ee0e44b4b/63261/{{userid}}",
  "os": "android"
}
```

CPI row with no goals (same sample):

```json
{
  "id": 63260,
  "name": "Casino Max Promos & - Android",
  "description": "S&#039;Inscrire (Nouveaux Utilisateurs Uniquement) Confirms Instantly This offer rewards within 24 hours. New users only.",
  "countries": ["FR"],
  "browsers": "all",
  "oss": "android",
  "payout": 0.19,
  "hasGoals": false,
  "goals": [],
  "categories": ["Offer"],
  "image": "https://imps.mnlx.me/i/aHR0cHM6Ly9zdGF0aWMub2ZmZXJ0b3JvLmNvbS91cGxvYWRzL29mZmVyc19pbWFnZS9iNzc2YjZmNTc2Y2FhODc5MjZmNzhhMjY0YTQ3NzllMmJmZDhkZTViZTE5MzI2Njk5NmU2ODNjYjQwNzgyYWRkLnBuZw==",
  "multipleTimes": false,
  "url": "https://api.monlix.com/api/cmp/redirect/74c6f10c7a5c5088dcecbf0ee0e44b4b/63260/{{userid}}",
  "os": "android"
}
```

`os` / `oss` values seen: `android`, `ios`, `all`. `all` becomes no device filter after `parseDevices()`. Countries seen in the sample: `TH`, `FR`, `ES`, `IT`, `US`, `GB`, `CO`, `CA`, `SA`, `AE`, `RU`, `EG`, `ID`.

---

## Clicks (our Offers tab)

Live docs ship:

```
https://api.monlix.com/api/cmp/redirect/{appid}/{campaignId}/{{userid}}
```

`{appid}` in that path is the same Sites API key (`74c6f10c7a5c5088dcecbf0ee0e44b4b` on their samples and on the iOS snippets). `{campaignId}` is `id`. `{{userid}}` is their mustache for the publisher user — they do not document replacing it with a click id.

The fixture uses a different, older-looking path:

```
https://api.monlix.com/click?appid=8812&campaign=90211
```

Treat `/api/cmp/redirect/...` as current. The driver does not rewrite either form. It appends:

```
{their url}&subid={click_id}
```

or `?subid={click_id}` when the URL has no query yet. `{{userid}}` is left as they sent it — `buildTrackingUrl()` only substitutes `{click_id}`. Same situation as Notik.

If a live fire shows they require a real user in `{{userid}}` for the click to count, that is an interface gap: `buildTrackingUrl()` is only handed the click UUID.

---

## Postback

Whenever a user completes an offer they GET the URL saved on the site, substituting the macros below. **You name the query keys.** The official Postback page is only a table of tokens. HTTP method, response body, retries, IPs, and signature are not on that page — they are implied by the ClaimBits / Vie Faucet / Shiftcode samples.

### Macros they publish (complete list)

| Macro | Description (verbatim, including their typo) | Example |
|---|---|---|
| `{{userId}}` | Your Site/App user id | `54x3857` |
| `{{userIp}}` | IP of the user | `192.168.100.0` |
| `{{countryCode}}` | Country of the user | `US` |
| `{{secretKey}}` | Your site secret sey (validate request) | `2f6d1f4444fd7` |
| `{{taskName}}` | Name of the offer or survey | `Earn money easily` |
| `{{transactionId}}` | Unique transaction id (to avoid duplicates) | `45020ab387c4fc00` |
| `{{rewardCurrency}}` | Name of your virtual currency | `Credits` |
| `{{rewardValue}}` | Conversion value * Virtual currency multiplier | `3400` |
| `{{payout}}` | Conversion value in USD | `1.20` |
| `{{subId}}` | Subid that you have passed to the offerwall | `a237eacc6db367b23f` |
| `{{status}}` | 1 = valid, 2 = invalid (chargeback) | `1` |

There is **no** `{{offerId}}`, `{{campaignId}}`, `{{goalId}}`, `{{hash}}`, or `{{signature}}`. Offer id on a conversion has to come from the click we stored, or we credit an embed with only `taskName`.

### How their own integrations paste the URL

They pick different query names on purpose. The macros stay the same.

**ClaimBits** (secret key named `secret`):

```
https://yourdomain.com/system/gateways/monlix.php?userid={{userId}}&transactionid={{transactionId}}&reward={{rewardValue}}&payout={{payout}}&status={{status}}&userip={{userIp}}&country={{countryCode}}&secret={{secretKey}}
```

Verify: `$secret != $_REQUEST['secret']` → `ERROR: Signature doesn't match`. Success body: `OK`. Dedup on `transactionid`. No `subId`.

**Vie Faucet** (secret key named `secretKey`, camelCase everywhere):

```
https://yourdomain.com/wh/monlix?userId={{userId}}&userIp={{userIp}}&transactionId={{transactionId}}&rewardValue={{rewardValue}}&status={{status}}&secretKey={{secretKey}}
```

Verify: `$secretKey != $_GET['secretKey']`. `status == 2` → reduce balance (chargeback). New tx → `OK`. Duplicate `transactionId` → `DUP`. Optional hold days before credit. No `payout`, no `subId` on this URL — they credit `rewardValue` (virtual currency).

**Shiftcode:**

```
https://yoursite.com/replace_path?userid={{userId}}&reward={{rewardValue}}&status={{status}}&secret={{secretKey}}
```

The settings screenshot (`https://nontalha.sirv.com/processed%20(1).jpeg`) is a dead Sirv account as of 2026-08-18.

### URL to paste into Dashboard → Postback

Exactly this (`postbackQuery()`). Filament copies it.

```
https://{host}/postback/monlix?transaction_id={{transactionId}}&payout={{payout}}&status={{status}}&subid={{subId}}&user_id={{userId}}&offer_name={{taskName}}&ip={{userIp}}&secret={{secretKey}}
```

Query keys are ours because we chose them. Do not rename a key without changing `parsePostback()` / `verifyPostback()`. Do not put `{{rewardValue}}` on a key we would treat as `payout`. Ledger is USD.

### What `parsePostback()` reads

| Incoming key | Their macro | Required for us | Maps to |
|---|---|---|---|
| `transaction_id` | `{{transactionId}}` | yes | `networkTxId` |
| `payout` | `{{payout}}` | yes, numeric | USD. Absolute value; sign from `status`. |
| `status` | `{{status}}` | no, default `'1'` | `'2'` → `reversed` |
| `subid` | `{{subId}}` | no | `clickId`. Missing → `UnknownClick`. |
| `offer_name` | `{{taskName}}` | no | `offerName`. Needed on embed (no offer of ours). |
| `user_id` | `{{userId}}` | no | `endUserId` |
| `ip` | `{{userIp}}` | no | Conversion IP |
| `secret` | `{{secretKey}}` | yes for verify | Not copied onto the DTO |
| `offer_id` | **none** | no | `offerExternalId` if someone adds a key later. Empty on our template. |

Missing `transaction_id` or non-numeric `payout` → `null` → HTTP 422.

### Hash / secret

```
hash_equals(secret_key, request('secret'))
```

Fails closed if `secret_key` is blank. A leftover `signature` query value is ignored. We do not also read `secretKey` — that is Vie Faucet's query name, not ours.

### HTTP (inferred from their samples, not the Postback page)

- Method: **GET**. Vie Faucet is `$_GET`. ClaimBits is `$_REQUEST` (GET or POST). We accept both.
- Body they ask for: ClaimBits `OK`, Vie Faucet `OK` / `DUP`. The generic page says nothing. We always answer **`ok`** (lowercase) with HTTP 200 — same as every other partner, Offery's published contract being the strictest.
- Timeout / retries: **not documented.**
- Duplicates: they tell you to key on `{{transactionId}}`. Unique `(network_id, network_txid)` does.
- Chargeback: `{{status}}` = `2`. Vie Faucet treats that as a debit of `rewardValue`. We negate `payout` and set `status = reversed`. Same `transactionId` as the credit — they do not send a separate original-tx field (unlike Notik's `rewarded_txn_id`).
- Source IP: **not documented.** Empty `postback_ips` rejects every fire. Ask the AM.

### Chargebacks

`status=2` is the only reversal signal they publish. `payout` is documented as "Conversion value in USD" with example `1.20` — they do **not** say the chargeback payout is negative. Vie Faucet uses `abs($reward)` on status 2, which implies the number stays positive. The driver already takes `abs($payout)` and flips the sign from status. Keep that. Do not also treat a negative payout as a second reversal signal unless a live fire shows they send both.

---

## JSON API (`/api/campaigns`) — do not point the driver here

Per-user, device-side catalogue. Payouts are **virtual currency** (`currency: "Points"`), not USD. No token. Optional server-side hop adds IP + UA.

Device:

```
GET https://api.monlix.com/api/campaigns?appid=[[appid]]&userid=[[userid]]&subid=[[subid]]
```

Server (note the extra slash before `?` in their snippet):

```
GET https://api.monlix.com/api/campaigns/?appid=[[appid]]&userid=[[userid]]&subid=[[subid]]&userip=[[userip]]&ua=[[useragent]]
```

Response is a **bare array**, not `{ campaigns: [] }`. Sample is truncated after three objects (trailing comma in the published JSON).

### Row (different from static)

| Field | Sample | vs static |
|---|---|---|
| `id` | number `5151` | Same role. |
| `campaignId` | hex string | **Static does not have this.** |
| `name` | `"Call of Antia (iOS) - CPE - Multi - US"` | Same. |
| `description` | string | Same. |
| `oss` | **array** `["ios"]` | Static is a string. |
| `payout` | **string** `"18076.51"` | Static is a USD number `0.08`. This is Points. |
| `image` | `https://static.vnative.co/images/...` | Static uses `imps.mnlx.me`. |
| `goals[]` | `{ id, name, payout }` flat strings | Static nests `payouts[].payout`. |
| `categories` | lowercase `"engagement"`, `"gaming"`, `"mobile games"` | Static is title-case vendor labels. |
| `multipleTimes` | bool | Same. |
| `featured` | bool | **Static does not have this.** |
| `url` | `/api/cmp/redirect/{hash}/{id}/{{userid}}` | Same shape. |
| `hasGoals` | bool | Same. |
| `createdAt` | ISO datetime | **Static does not have this.** |
| `currency` | `"Points"` | **Static does not have this.** |
| `cr` | number (conversion rate; `0` and `73.33` in the sample) | **Static does not have this.** |
| `countries` | **absent** | Static has it. |

One complete sample object:

```json
{
  "id": 5151,
  "campaignId": "aec7cc661bf69f495f608186f3ea18c5",
  "name": "Call of Antia (iOS) - CPE - Multi - US",
  "description": "Multi. New players only.",
  "oss": ["ios"],
  "payout": "18076.51",
  "image": "https://static.vnative.co/images/61c07794d5a27.png",
  "goals": [
    { "id": "61c07739220c734e27613209", "name": "4_Star_Hero_2", "payout": "14.63" },
    { "id": "61c07739220c734e2761320c", "name": "5_Star_Hero_1", "payout": "121.88" },
    { "id": "61c07739220c734e27613212", "name": "Level_12", "payout": "292.50" },
    { "id": "61c07739220c734e2761321b", "name": "Battle_Pass", "payout": "828.75" },
    { "id": "61c07739220c734e2761320f", "name": "5_Star_Hero_2", "payout": "1706.25" },
    { "id": "61d478482afe6214dc211ac2", "name": "Purchase_1999", "payout": "1950.00" },
    { "id": "61d71a267db9bb5cb809b1d2", "name": "5_Star_Hero_5", "payout": "3412.50" },
    { "id": "61d71a3c3d05b010d3642d12", "name": "5_Star_Hero_8", "payout": "9750.00" }
  ],
  "categories": ["engagement", "gaming", "mobile games"],
  "multipleTimes": false,
  "featured": false,
  "url": "https://api.monlix.com/api/cmp/redirect/be7862a480c4eda3441ffcc9c77d8ef0/5151/{{userid}}",
  "hasGoals": true,
  "createdAt": "2022-03-18T13:37:46.385Z",
  "currency": "Points",
  "cr": 9.21
}
```

---

## Custom Offers API — do not call unless the AM says so

Same row schema as static. Restricted to offers **whitelisted by their offer management team**. Same "no VC math, no removal of completed offers, blocked on redirect" paragraph.

Auth is **two** tokens:

```
GET https://api.monlix.com/api/custom?appid=[[appid]]&token=[[apitoken_provided_by_account_manager]]&authtoken=[[auth_token_generated_by_you]]
```

| Query | What it is |
|---|---|
| `appid` | Sites API key. |
| `token` | Separate token **the account manager gives you**. Not `md5(...)`. |
| `authtoken` | `md5(apiKey + secretKey)` — they print the same recipe as static, but the parameter name is `authtoken` here. |

Sample response is the same `{ total, campaigns }` dump as static (same 17 rows, same ids). Empty whitelist → not documented; the prose says the sample is what you get "if … there are offers that are whitelisted for you".

---

## Survey API — do not call from sync

```
GET https://api.monlix.com/api/surveys?appid=[[appid]]&userid=[[userid]]&subid=[[subid]]
GET https://api.monlix.com/api/surveys/?appid=[[appid]]&userid=[[userid]]&subid=[[subid]]&userip=[[userip]]&ua=[[useragent]]
```

> NOTE: it is advised not to cache survey responses, but to call API for each user, and in fact, for best results, we advise you to refetch API after each click.

Bare array. `currency` is `"USD"`. Payout is a range.

```json
{
  "id": "a7fa1396f5c3424aa13f683c91177dca",
  "minPayout": 0.896,
  "maxPayout": 1.224,
  "minDuration": "10",
  "maxDuration": "19",
  "rank": 1,
  "currency": "USD",
  "link": "https://api.monlix.com/api/r?uid=placeyourusername&cid=…&url=https%3A%2F%2Fredirect.mnlx.me%2F…",
  "name": "Monlix Survey",
  "description": "Complete super easy survey!",
  "logo": "https://monlix.com/logo.png"
}
```

`link` already has `sid=undefined` in their sample when `subid` was not sent. The published JSON ends with `...` — only two rows.

Surveys also appear inside the hosted wall and (presumably) inside `/api/campaigns`. Pulling this endpoint into `fetchOffers()` would duplicate them and fight their "do not cache" rule.

---

## Android SDK (not us)

JitPack. `implementation 'com.github.Monlix:android-sdk:v0.3'`.

Gradle < 7: `maven { url 'https://jitpack.io' }` in `allprojects.repositories`. Gradle > 7: same in `settings.gradle` `dependencyResolutionManagement.repositories`.

Builder: `setAppId`, `setUserId`, `setAge`, `setGender(MALE|FEMALE|OTHER)`, `setOs(ANDROID|IOS)`, `build(context)`. `open(context, "ZONE_ID")` — zone optional. `close(context)`.

---

## iOS SDK (not us)

CocoaPods: `pod 'MonlixOfferwall', '~> 0.0.2'`.

Storyboard / UIKit: `MonlixOfferwall(config: MonlixConfig(appId: "…", userId: "…"))`, `.fullScreen`.

SwiftUI: `UIViewControllerRepresentable` wrapper, present in a `.sheet`.

Objective-C: `#import "MonlixOfferwall-Swift.h"`, then

```
[offerwall initObjCWithAppId:@"…" userId:@"6" subId:nil age:nil gender:@3 zoneId:nil];
```

Gender table printed under that snippet: `0` Male, `1` Female, `2` Other. The sample passes `@3`, which is not in the table.

`subId` and `zoneId` exist on the ObjC init and are absent from the Swift `MonlixConfig` sample.

---

## What we do not handle

- `/api/campaigns` (per-user, Points).
- `/api/custom` (AM token + whitelist).
- `/api/surveys` (do not cache; refetch after every click).
- Android / iOS SDKs.
- ClaimBits / Vie Faucet / Shiftcode scripts.
- `browsers`, `oss` (we read `os`), `hasGoals`, `multipleTimes`, `campaignId`, `featured`, `createdAt`, `currency`, `cr`, goal `id` / `hash` / `geo`.
- `{{rewardValue}}` / `{{rewardCurrency}}` — their wall rate, not ours.
- Their "hide completed on redirect" rule. We hide on **our** approved `(end_user_id, offer_id)`.
- HMAC `signature`. They do not send one.

---

## Driver vs docs

| Topic | Docs | What we do |
|---|---|---|
| Catalogue URL | `GET /api/static?appid&token` | Same. |
| Token | `md5(apiKey + secretKey)` | Same. |
| Envelope | `{ total, campaigns }` | Reads `campaigns`. |
| Goal payout | `goals[].payouts[0].payout` | Same path. |
| Click URL | `/api/cmp/redirect/{appid}/{id}/{{userid}}` | Appends `subid={click_id}`. Leaves `{{userid}}`. Fixture still has `/click?appid&campaign`. |
| Postback URL | You write the query keys; they substitute macros | `postbackQuery()` — see paste URL above. |
| Auth | Plaintext `{{secretKey}}` vs dashboard secret | `hash_equals(secret_key, secret)`. |
| Offer id | **No macro** | Read `offer_id` if present; our template omits it. Click supplies the offer. |
| Status | `1` valid, `2` chargeback | Same. Payout absolute; sign from status. |
| Firing IPs | Not published | Seeded empty. Empty allowlist rejects unless `allow_any_postback_ip`. |

---

## Live-fire checklist

1. Network form: Test connection counts `campaigns`. Token is `md5(api_key + secret_key)`.
2. Sync: USD rows, `os` targeting, nested goals. A Points-looking payout (thousands on a CPI) means someone pointed the client at `/api/campaigns`.
3. Click an offer: outbound URL contains `subid=<uuid>`. Confirm with the AM whether `{{userid}}` must be a real player id.
4. Paste the Filament postback URL as-is. Dropping `secret` or `subid` fails silently on their side and 403 / `unknown_click` on ours.
5. Their postback test: same uuid in `subid`, `transaction_id` set, `status=1`, `payout` numeric, `secret` = dashboard secret, source IP on the allowlist. Expect `accepted` and body `ok`.
6. `unknown_click` means `subid` was missing or not our UUID. Fix the wall/click template. ClaimBits/Vie samples omit `subid` — do not copy them.
7. Chargeback test: `status=2`, same `transactionId` as the credit.
8. Embed: iframe `src` has `{user_id}` = publisher userid and `{sub_id}` = embed click uuid. Repeat step 5.
9. Ask the AM for firing IPs and put them on the row **before** enabling the network.

---

## Open questions (need a live fire or the AM)

1. Firing IPs.
2. Whether `{{userid}}` on `/api/cmp/redirect` must be replaced for the click to pay.
3. Whether chargeback `{{payout}}` is negative, positive, or zero.
4. HTTP method, timeout, retry count.
5. Whether a goal id / campaign id macro exists in the dashboard UI but not on the public docs page.
