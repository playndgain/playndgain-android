# OGAds (`ogads`)

![OGAds](../../public/img/networks/ogads.png)

Working notes for OGAds / OGMobi / AppSave: two offer APIs (visitor-targeted public, static Private), HasOffers tracking links, hosted offerwall, and an S2S postback **we write**. Driver: `app/Networks/Drivers/OGAdsDriver.php`. Fixture: `tests/Fixtures/Networks/ogads-offers.json`. Seeder row: `ogads` / [ogads.com](https://ogads.com). Logo: `public/img/networks/ogads.png`.

Fetched 2026-08-18 from the live publisher Offer API + Postback pages (the paste below is that dashboard copy), then every public [docs.ogads.com](https://docs.ogads.com/) guide, [learn.ogads.com](https://learn.ogads.com/), [ogads.com](https://ogads.com/), and the three API hosts opened in the browser. The Offer API and Postback URL editors themselves are login-gated (`members.ogads.com`). Guessing `/tools` and `/tools/offers-api` is a **404**.

The **public** Offer API is **not** a static worldwide catalogue. Every hop on it wants the **visitor's** IP and user agent. A cron that calls it with the worker's address gets the worker's geo/device, not the inventory. Ayet's equivalent is their Offerwall API, which we refuse to sync. Same shape there.

There is a second one. The **Private Offer API** (`/api/offers`, separate token, Mar 2026) *is* a static, paginated, all-geo catalogue with no visitor fields at all — see [Private Offer API](#private-offer-api). It is the endpoint a cron should use, and the driver does not point at it.

They host a wall (custom domain → `ogadswall.com`). Conversions come back on a GET we paste under Tools → Postback URL. Auth on that GET is **nothing they sign**. Macros we do not list are **stripped**. There is no HMAC, no `{transaction_id}`, no `{status}`, no published firing IPs.

HasOffers / TUNE is underneath: `aff_c` links (`jump.ogtrk.net` on the public API, **`jump.offerclk.net`** on the private one), `media.go2speed.org/brand/files/ogmobi/…` for art. They rotate domains and say old ones keep working — `appsave.space` has since stopped resolving, so they are wrong about that.

---

## Sources

| Page | URL / where | What it is |
|---|---|---|
| **Private Offer API** | Supplied PDF, **Mar 25th, 2026**. Not on `docs.ogads.com`. | **The static catalogue.** `/api/offers`, separate token, pagination, targeting rules |
| Offer API — Documentation | Publisher dashboard → Tools → Offers API (tab: Documentation) | Endpoint, Bearer auth, query params, `ctype` flags |
| Offer API — Example Response | Same page (tab: Example Response) | Full JSON envelope + four sample rows |
| Offer API — PHP Example | Same page (tab: PHP Example) | cURL + `Authorization: Bearer` + `success` / `error` |
| Add Your Postback URL | Publisher dashboard → Tools → Postback URL | Macro table. **We write the URL.** Case-sensitive. Unknown macros stripped. |
| Postback URL PHP Example | Same page | `$_GET` handler. Confirms GET. |
| Generate API Key | Button on the Offer API page | Mints / replaces the Bearer token |
| Video guide | Linked from the Offer API page ("See our video guide here") | URL not in the copied HTML. Closest public stand-in is the Learn course. |
| API In Depth | [learn.ogads.com/course/32-api-in-depth](https://learn.ogads.com/course/32-api-in-depth) | Members-only. 7 videos, last updated **06/2026**. PHP + postback controller. |
| Offerwall Setup | [docs.ogads.com/documents/9-offerwall-setup](https://docs.ogads.com/documents/9-offerwall-setup) | Custom wall domain, `aff_sub4`, postback pointer |
| Content Locker Integration | [docs.ogads.com/documents/19-content-locker-integration-options](https://docs.ogads.com/documents/19-content-locker-integration-options) | JS overlay / direct link. Not our wall. |
| Custom Domain | [docs.ogads.com/documents/1-how-to-setup-a-custom-domain](https://docs.ogads.com/documents/1-how-to-setup-a-custom-domain) | Cloudflare Flexible + CNAME. Lockers / tools, not the API. |
| Docs index | [docs.ogads.com](https://docs.ogads.com/) | The rest is payments FAQs |
| Payments Information | [docs.ogads.com/documents/8-payments-information](https://docs.ogads.com/documents/8-payments-information) | Points at [members.ogads.com/payments](https://members.ogads.com/payments) |
| Learn OGAds | [learn.ogads.com](https://learn.ogads.com/) | 27 courses. Only **API In Depth** is integration. |
| Marketing | [ogads.com](https://ogads.com/) | Content locker product. Mentions "direct link, Javascript or using our API" |
| Publisher login | [members.ogads.com/login](https://members.ogads.com/login) | Dashboard. Affiliate ID lives in the account dropdown. |
| Apply / register | [members.ogads.com](https://members.ogads.com/) → Register | Account approval before a key exists |

Live hosts opened 2026-08-18 (Chrome):

| URL | What came back |
|---|---|
| `https://appsave.online/` | Symfony "An Error Occurred: OK" / HTTP 200 body. Not a site. |
| `https://appsave.space/` | Same empty error page. |
| `GET https://appsave.online/api/v2` (no header) | HTML **401 Unauthorized**. Title `Unauthorized`. Not JSON. |
| `GET https://appsave.space/api/v2` (no header) | Same 401 HTML. |
| `GET https://unlockcontent.net/api/v2` (no header) | Same 401 HTML. Old driver host is still an alias. |
| `https://unlockcontent.net/` | Same Symfony "Error Occurred: OK" as the AppSave apex. |
| `https://members.ogads.com/tools` | **404** Page Not Found |
| `https://members.ogads.com/tools/offers-api` | **404** Page Not Found |

Their own warning on the Offer API page: you cannot open the URL in a browser. Confirmed.

---

## What this is for us

| Our piece | Their piece |
|---|---|
| `networks.credentials.api_key` | The **Private Offer API** token. A Tools → Offers API key here is `403 Invalid ability provided.` |
| `networks.credentials.api_domain` | Optional tool domain for the Private API. Defaults to `downloadlocked.com`. |
| `networks.credentials.postback_key` | **Not theirs.** Optional literal we can bake into the pasted URL (`&key=…`). They strip unknown `{macros}`, not literals. |
| `networks.postback_ips` | **Not published.** Reject when empty. Ask the AM before the row is `active`. |
| `postbackQuery()` | Written. Admin URL is `/postback/ogads?click_id={aff_sub4}&…`. |
| `fetchOffers()` / `syncsCatalogue()` | Points at the **visitor** Offer API today — **do not treat that one like Notik/Ayet Static** ([Catalogue problem](#catalogue-problem)). The [Private Offer API](#private-offer-api) is the static equivalent and is what this should call. |
| `verifyPostback()` | Source IP ∈ `postback_ips`. Optional `key=` literal if we set `postback_key`. They sign nothing. |
| `buildTrackingUrl()` | Append `aff_sub4={click_id}` on `link`. Official wall docs use **`aff_sub4`** for the user/click echo. |
| Hosted wall | Custom domain CNAME → `ogadswall.com`, then `?aff_sub4={sub_id}`. |

`{aff_sub4}` is the value they must echo. Put our click UUID there on catalogue clicks. On their hosted wall put `{sub_id}` (embed click) in `aff_sub4`.

---

## Brand and domain rotation

OGAds (OGMobi LLC). Content-locking / incentive CPA. Tracking is HasOffers (`aff_c`, `aff_sub`–`aff_sub5`, `source`).

They print two API hosts on the same dashboard page:

| Where they print it | Host |
|---|---|
| Offer API "Important!" box | `https://appsave.online/api/v2` |
| PHP example `$endpoint` | `https://appsave.space/api/v2` |

Quote from the live page:

> We update our domains from time to time. If you already have the API working on a previous domain, no worries — all of our domains are valid and will continue to work, so no need to switch.

**That promise is already false.** Re-probed 2026-08-26:

| Host | Then (2026-08-18) | Now (2026-08-26) |
|---|---|---|
| `appsave.online` | 401 on `/api/v2` | Still answers |
| `appsave.space` | 401 on `/api/v2` | **NXDOMAIN** — `Could not resolve host` |
| `unlockcontent.net` | 401 on `/api/v2` | Still answers |

So a hardcoded host is a time bomb, and `appsave.space` — the host printed in **their own PHP example** — is the one that died first. The private API's tool domain is an `api_domain` credential for exactly this reason.

Do not "migrate" a working host on a hunch either. Changing it on a live row for no reason is how a Friday sync dies; changing it because the name stopped resolving is not a hunch.

Apex `/` on those hosts is not documentation. It is a broken Symfony page.

---

## Credentials

Stored on `networks.credentials` (encrypted). Admin labels already come from `OGAdsDriver::credentialSchema()`.

| Our key | Their name | Required | Where they show it |
|---|---|---|---|
| `api_key` | Private Offer API token | yes | **Not** the Tools → Offers API key — that one 403s on `/api/offers`' sibling and vice versa. Header only, never on a tracking URL. |
| `api_domain` | (their tool domain) | no | Any locker domain of theirs. Defaults to `downloadlocked.com`. Exists because `appsave.space` stopped resolving. |
| `postback_key` | (ours) | no | Not on their form. If set, add it as a **literal** query value on the pasted postback URL. |

Affiliate ID (`aff_id` on every `link`) is **not** a credential we store. It is already inside each offer's tracking URL. The account dropdown on the dashboard shows it (third-party Pocket/DroidOxy write-ups).

There is **no documented postback secret** and no documented key expiry. Regenerating a token invalidates the old one. Note the two failure modes are different and both are worth recognising on sight: an **unknown** token is `401 Unauthorized`, a **valid token without the ability** is `403 Invalid ability provided.` The second means right account, wrong API — see [The 403 that got us here](#the-403-that-got-us-here).

Talk to the AM / dashboard for: the Private Offer API token, Affiliate ID confirmation, postback firing IPs, and whether reversals are ever sent.

---

## Dashboard model

One publisher account at `members.ogads.com`. Then:

| Tool | Path they print | What it is |
|---|---|---|
| Offers API | Tools → Offers API | Bearer key + this documentation |
| Postback URL | Tools → Postback URL | One global GET template. Save button. |
| Offerwall domains | Tools → Offerwall → Domains | Custom wall hostname |
| Offerwall options | Tools → Offerwall → Options | Wall chrome |
| Custom domains | Tools → Custom Domains | CNAME target for lockers / tools |
| Content lockers | Tools → Content Lockers | Overlay / direct-link lockers |
| Payments | Payments tab / `members.ogads.com/payments` | Invoices, Net 7 / Net 30, PayPal / Payoneer / crypto / wire |

Offerwall and locker domains must be **new**. They refuse a domain that already hosts a landing page.

---

## Hosts

| Host | Role |
|---|---|
| `appsave.online` | Current Offer API (`/api/v2`) |
| `appsave.space` | Same API. Printed in their PHP sample |
| `unlockcontent.net` | Older Offer API alias. Driver default. Apex is broken; `/api/v2` is live |
| `jump.ogtrk.net` | HasOffers click redirect (`/aff_c?aff_id=&offer_id=`) |
| `media.go2speed.org` | Offer icons (`/brand/files/ogmobi/{offerid}/thumbnails_100/…`) |
| `ogadswall.com` | CNAME **target** for a publisher offerwall domain |
| `members.ogads.com` | Publisher dashboard |
| `docs.ogads.com` | Public help center (almost all payments) |
| `learn.ogads.com` | Video courses. API course is members-only |
| `ogads.com` | Marketing |
| `smyl.es` | Short tracking host in our **fixture** (not in the live sample) |

Live sample `link` values are `http://` (not `https://`) on `jump.ogtrk.net`. Leave the scheme they send. Do not rewrite.

---

## Offer API

> The Offer API will return JSON data of all live incentive campaigns. Contact your affiliate manager if you need help, would be happy to help you get set up with the offer API.

"All live" means **all live campaigns that match this visitor**. `ip` and `user_agent` are required. That is targeting, not a courtesy.

```
GET https://appsave.online/api/v2?ip={visitor_ip}&user_agent={visitor_ua}
Authorization: Bearer {api_key}
```

Their printed example (ctype = CPI only):

```
https://appsave.online/api/v2?ip=23.45.21.76&user_agent=Mozilla%2F5.0%20(X11%3B%20Linux%20x86_64)%20AppleWebKit%2F537.36%20(KHTML%2C%20like%20Gecko)%20Chrome%2F77.0.3865.90%20Safari%2F537.36&ctype=1
```

PHP sample uses `https://appsave.space/api/v2` and `http_build_query` of `ip` + `user_agent` (+ optional `ctype`, `max`, …).

Method is **GET**. Auth is a header, never a query key.

```
Authorization: Bearer your-api-key-here
```

`Illuminate` `withToken()` sends that. Correct.

Opening the URL in a browser (no header) is HTML 401, not `{ "success": false }`. A missing header and a bad token are not documented as the same body. Treat any non-JSON / HTTP ≥ 400 as a hard failure. Reading a 401 as an empty catalogue would deactivate every OGAds offer we have.

No pagination is documented. No `page`, no `ctry`, no `device`. Those three are what the current driver sends. They are **not** on this page.

No rate limit is documented. The Learn course is the place that might say otherwise (members-only).

### Query parameters

Copied from the live Documentation table.

| Parameter | Required | Type | Description |
|---|---|---|---|
| `ip` | **REQUIRED** | String | Visitor's IP. |
| `user_agent` | **REQUIRED** | String | Visitor's user agent. |
| `ctype` | Optional | String (bitwise int) | Offer-type filter. Omit or `0` = all types. **Does not have any effect if device is desktop.** |
| `max` | Optional | Integer | Maximum number of offers to return. |
| `min` | Optional | Integer | Minimum number of offers to return. **May cause blocked offers to show** if there are not enough unblocked offers. |
| `aff_sub4` | Optional | String | Extra data "for advanced users." Baked onto the returned `link`s. |
| `aff_sub5` | Optional | String | Same. |

There is no `aff_sub` / `aff_sub2` / `aff_sub3` / `source` on the Offer API table. Those exist on the **tracking link** and come back as postback macros. To stamp our click id at fetch time you would pass `aff_sub4={click_id}` here — but fetch time is not click time, so that only works for a per-request wall, not a sync.

`min` is a foot-gun. Do not send it. We would rather show fewer cards than unblock campaigns they already hid.

### `ctype` bitwise flags

Add the numbers together. Example from the page: `12` = `4` (PIN) + `8` (VID).

| Value | Type | Meaning (industry, not defined further on the page) |
|---|---|---|
| `1` | CPI | Install |
| `2` | CPA | Action / lead |
| `4` | PIN | PIN / carrier submit |
| `8` | VID | Video |

Their example sets `ctype=1` and says that returns CPI only. Not including `ctype`, or setting it to `0`, returns all types (CPI, CPA, PIN, etc).

Desktop user agents: `ctype` is ignored. You get everything.

The offer **row** has no `ctype` / `type` field. If we pull `ctype=0` we cannot tell CPI from PIN later except by parsing `name` / `description`. For a GPT wall, omit `ctype` unless product asks to drop PIN/VID.

### Envelope

Success (live example, `ctype=1`):

```json
{
    "success": true,
    "error": null,
    "offers": [
        { "offerid": 9164 }
    ]
}
```

Failure (from their PHP example, not from a table):

```php
if ($content->success) {
    $offers = $content->offers;
} else {
    throw new Exception($content->error);
}
```

So a documented error is still JSON:

```json
{
    "success": false,
    "error": "human string"
}
```

`error` is `null` on success. Do not treat a JSON 200 with `success: false` as an empty catalogue.

Missing Bearer (live, 2026-08-18): HTML page, title `Unauthorized`, body `401 UNAUTHORIZED`.

### Offer row

Every key that appears on the live Example Response. Types taken from the sample, not from a schema — they do not publish one.

| Their field | Sample | What it is | What we should do |
|---|---|---|---|
| `offerid` | `9164` (int) | Offer id | `OfferDTO.externalId` as string. Skip if missing. |
| `name` | `"Final Fantasy XV - CPE tutorial (Android, Free, INCENT, US, 156MB, 5.0)"` | Internal long name. Device, incent flag, geos, size, OS floor are packed in here. | Fallback title. Do not show this on the wall if `name_short` is set. |
| `name_short` | `"Final Fantasy XV"` | Display name | `OfferDTO.name`. Empty string on some rows (our fixture row 2) — then use `name`. |
| `description` | HTML-ish blob, often with broken `<\/b>` tags | Conversion terms, traffic restrictions, targeting. Can be `""`. | `OfferDTO.description`. Strip tags. |
| `adcopy` | `"Download, install and complete the tutorial to unlock this content."` | User-facing instruction | `OfferDTO.instructions` |
| `picture` | `https://media.go2speed.org/brand/files/ogmobi/9164/thumbnails_100/…` | Icon / GIF | `OfferDTO.iconUrl` |
| `payout` | `"0.39"` (string) | Affiliate payout, USD | `(float)` → `OfferDTO.payout` |
| `country` | `"US"` or `"CZ,FR,DE,IT,NL,RU,ES,UK,US"` | Geos, comma-separated. **`UK` is not ISO-2.** | See [Countries](#countries) |
| `device` | `"Android"` or `"iPhone,Android"` | Devices, comma-separated | `parseDevices()` already maps android / iphone / ipad / desktop |
| `link` | `http://jump.ogtrk.net/aff_c?aff_id=1026&offer_id=9164` | Tracking URL | Store + append `aff_sub4={click_id}` |
| `epc` | `"0.16220"` (string) | Earnings per click | Not on `OfferDTO`. Ignore unless we add a sort later. |

No `events`, no `goals`, no `os`, no `os_min_version`, no `category`, no `ctype` on the row, no daily cap, no multi-reward flag, no preview URL, no transaction id.

OS floor is only inside `name` / `description` (`OS 5.0+`, trailing `5.0` on the long name). The current driver does not parse it. `OfferDTO.osMinVersion` stays null unless we start scraping that string.

`conversionType`: the driver hardcodes `'cpa'`. The feed is mixed CPI / CPA / PIN / VID. Fine as a default; do not invent a per-row type we were not given.

Live sample rows (kept in full — this is the only official field set):

1. **9164** Final Fantasy XV — Android, US, payout `0.39`, EPC `0.16220`, CPE tutorial, INCENT, OS 5.0+, 156MB. Conversion: Install, Open, & Tutorial Complete.
2. **2993** Castle Clash — Android, US, payout `0.34`, EPC `0.14296`.
3. **15696** AliExpress — Android, `CZ,FR,DE,IT,NL,RU,ES,UK,US`, payout `0.22`, EPC `0.14196`. Conversion: Install & Open.
4. **5930** AppMatch Survey — `iPhone,Android`, `AU,CA,UK,US`, payout `0.28`, EPC `0.11598`, empty `description`.

Traffic-restriction lines that show up in `description` (not a filter we can send): Custom Creatives; Incentivized Traffic; Adult Traffic; Push Notification Traffic; Ads Icon Traffic; SMS Traffic; Discovery App Traffic; Instagram/Twitter Traffic. We are an incent GPT wall — that is the point of this network. Adult / push / SMS are their advertiser rules, not ours.

### Official PHP request (Documentation tab)

Condensed from their sample. Same contract.

```php
$api_key = 'your-api-key-here';
$endpoint = 'https://appsave.space/api/v2';
$data = [
    'ip' => $_SERVER['REMOTE_ADDR'],
    'user_agent' => $_SERVER['HTTP_USER_AGENT'],
    // optional: ctype, max, min, aff_sub4, aff_sub5
];
$url = $endpoint . '?' . http_build_query($data);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $api_key,
    ],
]);
$content = curl_exec($ch);
$content = json_decode($content, null, 512, JSON_THROW_ON_ERROR);

if ($content->success) {
    $offers = $content->offers;
} else {
    throw new Exception($content->error);
}
```

They decode as an object (`json_decode(..., null, …)`), not an array. `offers` is a list. `offerid` is numeric.

---

## Countries

On the public API, `country` is a comma-separated string. (The [Private Offer API](#private-offer-api) sends `countries` as an **array** instead — `parseCountries()` takes either.) `parseCountries()` splits on `,` / whitespace / `|` and **keeps only `[A-Z]{2}`**.

Live sample includes **`UK`**. That is not ISO-2. `preg_match('/^[A-Z]{2}$/')` drops it. AliExpress and AppMatch Survey would lose the United Kingdom.

Map before parse:

| They send | Store |
|---|---|
| `UK` | `GB` |
| `GB` | `GB` (if they ever send it) |

Fixture uses `us, ca, gb` (already ISO-2, lower case — `parseCountries` uppercases). Live sample uses `UK`. Both will happen.

Empty / missing `country` → `[]` → worldwide. Do not invent US.

---

## Private Offer API

A **second, different API**. Not a newer version of the Offer API above — a separate product with its own token, its own host shape, its own envelope, and no visitor fields. Source: *OGAds Private Offer API*, dated **Mar 25th, 2026** (supplied document, not on `docs.ogads.com`).

This is the static, all-geo catalogue the section below says does not exist. It does. It is just not the endpoint the driver points at.

```
GET https://{tool-domain}/api/offers
Accept: application/json
Authorization: Bearer {private-api-token}
```

> "The API allows you to pull all non-expired offers from our system."

- **Any tool domain works.** Their example is `https://downloadlocked.com/api/offers`. Same rotation story as the public API — the host is a locker domain, not a fixed API hostname. It cannot be a `private const ENDPOINT` the way `appsave.online/api/v2` is; the domain belongs in credentials.
- **`Accept: application/json` is required** to get JSON back. Without it you are on a locker domain asking a locker for a page. `Http::acceptJson()`.
- **The token is not the public Offer API key.** "This API token is different to the one used for the public Offer API." Two credentials on the network row. Where the private one is minted is **not** in the document — assume the AM issues it until proven otherwise.
- **Auth failure is `403 Forbidden`**, not the public API's HTML `401 UNAUTHORIZED`. A driver that special-cases 401 will read a 403 as a generic failure. Both must be hard failures either way.
- **No `ip`, no `user_agent`, no `ctype`, no `max`/`min`.** The only documented query key is `page`.

### Pagination

Real pagination, unlike the public endpoint.

```
GET https://downloadlocked.com/api/offers?page=1
```

| | |
|---|---|
| Page size | 1000 offers, fixed (`meta.per_page`) |
| Loop until | `meta.current_page >= meta.last_page` |
| Their illustration | `total: 18963`, `last_page: 19` |
| **Our account, live 2026-08-26** | **`total: 619`, `last_page: 1` — one GET** |
| Rate limit | Undocumented. Ask before assuming a 19-hop sweep is welcome. |

Build the loop on `meta.last_page`, not on either number. `OGAdsDriver::MAX_PAGES` caps it at 50 as a runaway guard.

`meta` on every response:

```json
"meta": {
  "current_page": 8,
  "from": 7001,
  "last_page": 19,
  "per_page": 1000,
  "to": 8000,
  "total": 18963
}
```

Offers live under `data`, not `offers`. The sample envelope carries only `data` and `meta` — **no `success` gate is documented**, so the public API's `success === true` check has nothing to bind to here. HTTP status plus "is `data` an array" is the whole check. That is an inference from one sample, not a promise: if a live pull ever returns a `success` key, treat `false` as a hard failure the way the public driver does.

The old docblock — *"One hop. They do not paginate."* — was true of `/api/v2` and false here. `fetchOffers()` now loops.

### Offer row (private)

Sample row, field by field:

| Field | Sample | Notes |
|---|---|---|
| `id` | `30103` | Not `offerid`. Integer. → `externalId` |
| `created_at` | `2019-11-14T22:06:55.000000Z` | ISO-8601. Nothing consumes it. |
| `name` | `Win a Christmas Gift of your choice! - NZ, Incent, Email Submit` | **Must be shortened.** See below. |
| `description` | `…\r\n-\r\nEnter your Christmas wish…` | CRLF, no HTML in the sample. `plaintext()` still applies. |
| `instructions` | `Enter your Christmas wish. Fill in your details & confirm.` | Not `adcopy`. Contains raw `&`. |
| `thumbnail` | `media.go2speed.org/brand/files/ogmobi/…` | Not `picture`. Same TUNE CDN. → `iconUrl` |
| `tracking_url` | `jump.offerclk.net/aff_c?offer_id=…&aff_id=…&aff_sub=PrivateOfferAPI&…&aff_sub4=&aff_sub5=` | Host is **`jump.offerclk.net`** live, not the `jump.ogtrk.net` the public API prints. `aff_sub` is pre-filled `PrivateOfferAPI` — their source tag, leave it. **`aff_sub4=` ships empty.** See below. |
| `capped` | `false` | Boolean. |
| `paused` | `true` | Boolean. **`true` in their own sample.** |
| `payout` | `"0.70000"` | **String**, 5dp. `(float)` it. |
| `payout_type` | `"cpa_flat"` | **Three values live:** `cpa_flat` 571, `cpa_percentage` 46, `cpc` 2. Both `cpa*` → `conversionType: 'cpa'`. `ConversionType` has no `cpc` case; the CPC rows are refused for a different reason (below), and anything non-`cpa*` falls to `'other'`. |
| `epc` / `my_epc` | `"0.00000"` | Strings. Network EPC vs ours. Nothing consumes them. |
| `expiration_date` | `2023-11-14 17:06:00` | Space-separated, no timezone. **Already past in the sample.** |
| `conversion_cap` / `click_cap` | `0` | `0` = no cap. The period is **not stated** — do not map to `dailyCap` until the AM confirms it is daily. |
| `cap_percentages` | `{total, clicks, conversions}`, all `null` | How full the caps are. Useful for suppressing near-capped offers later; nothing reads it today. |
| `categories` | `["Email Submits"]` | Array of display names. → `categories` |
| `countries` | `["NZ"]` | **Array**, uppercase ISO-2. `parseCountries()` already accepts arrays. |
| `devices` | `["Android", "Desktop", "iPhone", "iPad"]` | **Array.** Live also sends **`Windows`** and **`Mac OS`** (277 rows each), which `parseDevices()` maps to nothing. Harmless *as measured*: all 277 also carry `Desktop`, and `Desktop` never appears without them — 0 exceptions in 619 rows. |
| `offer_targeting_rules` | 2 rules in their sample | **3 rows in 619 live**, all `req_device_os_version`. See below. |

`UK` does not appear live: 249 distinct country codes across 619 rows, every one a valid ISO-2. The `COUNTRY_ALIASES` map stays anyway — it costs nothing and still guards the public API path.

### Name shortening is mandatory

> "Offer names must be shortened to include only the text before the first `" - "` (space, hyphen, space)."

```
Win a Christmas Gift of your choice! - NZ, Incent, Email Submit
→ Win a Christmas Gift of your choice!
```

This is a requirement of the document, not a nicety — the tail is internal targeting metadata (geo, incent flag, vertical) that publishers should not be shown.

There is **no `name_short`** on this endpoint. The driver's old `name_short ?? name ?? 'Untitled offer'` chain would have fallen straight through to the raw long name, so the shortening would never have happened. It is `Str::before($name, ' - ')` now — that returns the full string when the delimiter is absent, and the space-hyphen-space delimiter keeps hyphenated brand names (`Coca-Cola`) intact where `explode('-')` would not.

**583 of 619 live names carry the tail**, and 196 carry two or more segments — `£100 Playstation Store Voucher - UK - {CPA} RS` cuts at the first and keeps its own text.

### Targeting rules

An offer may carry any number of `offer_targeting_rules`:

| Key | Meaning |
|---|---|
| `type` | Which visitor attribute to test |
| `allowed` | Whitelist when true (regex match → allowed), blacklist when false. Their doc prints `1`; the live feed sends JSON `true`. Do not compare with `===  1`. |
| `regex` | The expression, JSON-escaped (`^([8-9]\|[1-9]\\d)`) |

Ten documented `type` values:

| `type` | Visitor attribute | Example |
|---|---|---|
| `req_browser_name` | Browser name | Chrome |
| `req_connection_type` | Connection | cellular |
| `req_device_family` | Device | iPod |
| `req_device_os` | Operating system | iOS |
| `req_device_os_version` | OS version | 16.3 |
| `req_geo_city` | City | Chicago |
| `req_geo_state` | State / region | IL |
| `req_ip_address` | Visitor IP | — |
| `req_mobile_carrier` | Mobile carrier | Verizon |
| `req_service_provider` | Service provider | AT&T Wireless |

> "These rules must be checked before showing the offer to a visitor. Any clicks from visitors that don't match the rules will be wasted."
>
> "If you cannot check the targeting rules - just do not sync offers that have them."

**Take their escape hatch: skip any offer with a non-empty `offer_targeting_rules`.**

`OfferDTO` has no field that can hold `req_geo_state` `^(NJ)$` or a carrier blacklist. `osMinVersion` is the only adjacent field, and deriving `"8"` from `^([8-9]|[1-9]\d)` is guessing at a regex, not reading a value. Everything else — city, ISP, carrier, connection type, browser — has nowhere to go and nothing at wall-render time to evaluate it against.

**The cost is 3 offers out of 619** (0.5%), all of them `req_device_os_version`. The feasibility question this section originally flagged is answered: skipping rule-bearing offers leaves the catalogue intact.

Self-check for whoever revisits this: **the single offer in their printed sample carries two rules**, so the filter applied to *that paste* yields **zero** offers. A fixture built from it and asserting a non-empty yield is asserting the wrong thing.

Storing and evaluating these properly is a real feature — per-request rule evaluation against the visitor at wall render, which is the same class of thing as the per-request fetch we already refuse for Ayet. Until that exists, skipping is the honest option and it is the one they endorse.

### What we refuse to sync

Measured on the live pull, 2026-08-26: **619 rows in, 578 out.** `OGAdsDriver::syncable()` is the gate.

| Refused | Live count | Why |
|---|---|---|
| `offer_targeting_rules` non-empty | 3 | Nowhere to store them, nothing to evaluate them against. Their own doc says skip. |
| No `aff_sub4=` in `tracking_url` | 2 | Signed-JWT links. No slot for a click id — see below. |
| `payout <= 0` | 35 | A wall slot that pays the publisher nothing is a wasted user. |
| `paused` or `capped` | 0 | They ship the flags; nothing is flagged today. |
| `expiration_date` in the past | 0 | Guard only. `0000-00-00` is "no expiry", not an expired one — live rows use far-future sentinels (2036–2038). |

**Zero payouts are a `cpa_percentage` problem.** 34 of the 46 revshare rows price at `0.00000`, plus 1 `cpa_flat`. Their `epc` is non-zero on those same rows (`0.07584`, `0.07501`, …), so `payout` is not the earnings signal for revshare — it is left unset. Do not read `payout` on a `cpa_percentage` row as an authoritative dollar figure; the ones that *are* populated (`1.80`, `1.20`) look like USD estimates, and `RS` in the name means the realized amount varies. Filtering on `payout <= 0` sidesteps the question without asserting the unit.

Their document says the endpoint returns "all non-expired offers", yet their printed sample is `paused: true` with an `expiration_date` of **2023-11-14**. Live data sides with the sentence — 0 paused, 0 capped, 0 expired — so the sample is stale illustration. The guards stay anyway; they cost one boolean each.

All of these are per-sync decisions and all of them self-heal: `SyncNetworkOffersJob::upsert()` sets `status = Active` on every row it touches unless an admin has `Blocked` it, so an offer skipped today comes back `Active` on the first sync after it unpauses or gets a payout. The skip buries nothing permanently — that only holds because `upsert()` resets `status`, so do not "optimise" that line away.

**Watch the interaction with the collapse guard.** `SyncNetworkOffersJob::catalogueCollapsed()` refuses any run that lands under 20% of the previous `offers_count`, alerting instead of deactivating. The first real sync went the other way — `offers_count` rose 25 → 577 — but the first sync after *adding* a skip rule is the one that could trip it. Count what survives before enabling on a live row.

First live sync on network 24, 2026-08-26: `ok`, 577 seen, 564 created, **11 deactivated** — the stragglers from the `/api/v2` era, whose `offerid` namespace does not overlap this endpoint's `id`. No offer on the row still points at a non-`offerclk` host. Two admin-`Blocked` offers stayed blocked, which is `upsert()` doing its job.

### Offers that disappear

> "Offers that are not returned in the response are no longer available, so ensure any previously synced offers that are not in the response this time are deleted or flagged as unavailable."

**Already satisfied.** `SyncNetworkOffersJob` stamps `last_seen_at` on every upsert, then flips every `Active` offer on that network whose `last_seen_at` predates the run to `Inactive`. It deactivates, never deletes — clicks and conversions reference those rows for the lifetime of the platform, which is the stronger reading of "flagged as unavailable".

Two behaviours that matter for a 19-page loop:

1. A throw on page 7 propagates out of `fetchOffers()`, and the job's `catch` records the failed run and rethrows **before** reaching the deactivation query. A partial pull cannot deactivate the pages it never fetched. That is correct by construction — do not swallow a mid-loop page failure to "salvage" the pages already read.
2. `catalogueCollapsed()` covers the silent version of the same problem: HTTP 200 with an empty or truncated `data`.

### The 403 that got us here

Admin → Test connection reported `OGAds returned HTTP 403.` The 403 was not a bad key. Probed live, same stored token throughout:

| Request | Result |
|---|---|
| `/api/v2` + **bogus** token | HTTP **401** HTML `Unauthorized` |
| `/api/v2` + **no** token | HTTP **401** HTML `Unauthorized` |
| `/api/v2` + **our** token | HTTP **403** HTML `Not Authorized` |
| `/api/v2` + our token + `Accept: application/json` | HTTP **403** `{"message":"Invalid ability provided."}` |
| **`/api/offers`** + our token + `Accept: application/json` | HTTP **200**, 619 offers |

A bogus token gets 401; ours gets 403. The token **authenticates** and lacks the ability — that is Sanctum's wording, and it means the key in `api_key` was a **Private Offer API** token all along, pointed at the public endpoint by a driver that predates this document.

Two independent defects fell out of that probe. The second is a fact about their infrastructure, not our code: **`appsave.space` no longer resolves** (`Could not resolve host`), despite the "all of our domains are valid and will continue to work" quoted under [Brand and domain rotation](#brand-and-domain-rotation). `appsave.online` and `unlockcontent.net` still answer.

### Driver delta — done

`OGAdsDriver` now calls this endpoint. Kept as the map from what the driver used to do:

| Was (`/api/v2`) | Now (`/api/offers`) |
|---|---|
| `private const ENDPOINT = 'https://appsave.online/api/v2'` | `api_domain` credential, default `downloadlocked.com` |
| `api_key` = public Offer API key | Same key **name**, private token (renaming would orphan the stored value) |
| `ip` + `user_agent` probe constants | Deleted. No persona. |
| `success === true` gate | No `success` key. Status + `is_array($data)`. |
| `offers` | `data` |
| One hop | `page` → `meta.last_page`, capped at `MAX_PAGES` |
| `offerid` | `id` |
| `name_short ?? name` | `Str::before($name, ' - ')` |
| `adcopy` | `instructions` |
| `picture` | `thumbnail` |
| `link` | `tracking_url` |
| `country` comma string | `countries` array |
| `device` comma string | `devices` array |
| — | `categories` array |
| `conversionType: 'cpa'` hardcoded | `cpa*` → `cpa`, else `other` |
| `"OGAds returned HTTP 403."` | Status **plus** `message` — the string that names the actual fix |
| No `Accept` header | `Accept: application/json` |
| No visibility flags | `syncable()`: rules / subid slot / payout / paused / capped / expired |

**`withSubIdMacro()` fills the slot instead of appending.** Every `tracking_url` here already ships `aff_sub4` **empty**:

```
https://jump.offerclk.net/aff_c?offer_id=31111&aff_id=463539&aff_sub=PrivateOfferAPI&aff_sub2=&aff_sub3=&aff_sub4=&aff_sub5=
```

Appending would produce `…&aff_sub4=&aff_sub5=&aff_sub4={click_id}` — the same key twice, one of them empty. HasOffers is widely assumed to take the last occurrence, but that is assumption, not documentation, and a first-wins parser anywhere in the chain would silently strip every click id we own. It now rewrites the existing `aff_sub4=` value; all 578 synced links carry exactly one.

**The two JWT links are why the filter is on link shape, not `payout_type`.** Both refused rows are `downloadlocked.com/c/<jwt>`, with the real `jump.offerclk.net` URL and its empty `aff_sub4` sealed inside a signed HS512 payload (`"aud":"cpc"`). A query parameter bolted onto the outside cannot reach it, so every conversion would arrive as `unknown_click`. Both happen to be the only `cpc` rows today, but the sealed payload is a property of the `/c/` link, not of CPC pricing — filter on the missing subid slot, and a wrapped `cpa_flat` offer is caught too.

The postback side is unchanged: same dashboard, same macro table, same `{aff_sub4}` → `click_id`. Nothing in the private API document touches conversions.

### Ask the AM about this endpoint

Still open:

- Which tool domain is ours long-term — is `downloadlocked.com` stable for this account, or do we get our own? (`appsave.space` going NXDOMAIN is the reason this is not a constant.)
- Rate limit, and how often we may re-sync.
- Is `conversion_cap` / `click_cap` daily or lifetime? Nothing maps to `dailyCap` until this is answered.
- `cpa_percentage` rows priced at `0.00000` with a non-zero `epc` — is `payout` simply unset for revshare, or is it a rate?
- Do the `/c/<jwt>` links accept an outer subid parameter, or is a JWT-wrapped offer permanently unattributable for us?

Answered by the live pull, kept for the record:

- ~~Does the private feed send `UK`?~~ No — 249 country codes, all ISO-2.
- ~~Are paused / capped / expired offers returned?~~ Not today. 0 of 619.
- ~~What fraction carry `offer_targeting_rules`?~~ 3 of 619. Skipping them costs nothing.

---

## Catalogue problem

**Historical — and resolved.** The driver no longer calls `/api/v2`; it uses the [Private Offer API](#private-offer-api). This section stays because the trap is a property of their public endpoint, not of our old code, and it is what anyone repointing the driver back at `/api/v2` would walk into.

`fetchOffers()` used to send:

```
GET https://unlockcontent.net/api/v2?ctry=&device=&page=N
Authorization: Bearer …
```

then paginates until `offers` is `[]`.

Live docs:

- require `ip` + `user_agent`
- do not mention `page`, `ctry`, `device`
- one response is the whole list (`max` / `min` only)

Implications:

1. A sync from the worker IP stores **that IP's** inventory. A US Android user then sees (or does not see) offers targeted at a datacenter in whatever region the queue runs.
2. `testConnection()` sends `page=1` and no `ip` / `user_agent`. Against the live contract that is an incomplete request. Against a missing header it is HTML 401. Either way the admin button lies.
3. Empty `offers` because the worker is desktop / datacenter is **not** "the network has no campaigns." Treating it as an empty catalogue deactivates every stored OGAds offer (`SyncNetworkOffersJob`).
4. ~~There is no documented static / all-geo endpoint.~~ **Superseded.** Ayet solved this with Static API, Monlix with `/api/static` — and OGAds has the [Private Offer API](#private-offer-api). Everything in points 1–3 is a problem with `/api/v2` only, and the fix is to stop calling `/api/v2` on a cron.

Options that fit this codebase (do not pick in this file — pick when the driver is rewritten):

| Approach | Fits `OfferFeed`? | Notes |
|---|---|---|
| Keep cron sync with a fake residential IP + mobile UA | Yes | Lies. Misses geos. Caps/blocks are for that persona. |
| Multi-hop: one call per major geo × device | Yes, badly | Rate / complexity. Still incomplete. `min` must stay off. |
| Per-request fetch at wall render | No | Breaks the Redis `app:country:device` payload. Same class of thing as Ayet Offerwall API, which we refuse. |
| Hosted wall only (`syncsCatalogue() = false`) | Yes | Their offerwall + `aff_sub4={sub_id}`. Empty `fetchOffers()` must not run. |
| **Private Offer API (`/api/offers`)** | **Yes** | **The static feed. Needs its own token and a rewrite of `fetchOffers()`. This is the one to pick.** |

Until one of those is chosen, **do not enable the network on a cron that can deactivate offers**.

`syncsCatalogue()` is `true` via `AbstractDriver`. That is only safe after the fetch actually returns a worldwide (or at least multi-geo) set.

---

## Tracking links

Sample:

```
http://jump.ogtrk.net/aff_c?aff_id=1026&offer_id=9164
```

HasOffers `aff_c`. `aff_id` is the publisher. `offer_id` is `offerid`.

Slots they will echo (postback macro table + Offer API extras):

| Query on `link` | Comes back as | Use |
|---|---|---|
| `aff_sub` | `{aff_sub}` | Free. Kintura presets put `{cid}` here. AdsBridge says avoid it. |
| `aff_sub2` | `{aff_sub2}` | Free |
| `aff_sub3` | `{aff_sub3}` | Free |
| `aff_sub4` | `{aff_sub4}` | **Ours.** Official offerwall docs: pass the user in `aff_sub4`. Driver already reserves this for `{click_id}`. |
| `aff_sub5` | `{aff_sub5}` | Free. Offer API can stamp it at fetch time. |
| `source` | `{source}` | HasOffers source. Not on the Offer API table. |

Current driver:

```
{link}&aff_sub4={click_id}
```

then `buildTrackingUrl()` replaces `{click_id}`. That matches the official wall slot.

If `link` already has `aff_sub4` (because we passed it into the Offer API), appending another `aff_sub4` is ambiguous. For a cron sync, do **not** send `aff_sub4` on the Offer API hop; append the macro on the stored URL instead.

Do not put the API key on `link`.

---

## Hosted offerwall

Public docs: [Offerwall Setup](https://docs.ogads.com/documents/9-offerwall-setup). Written by Ben, updated ~9 months before this fetch.

1. Buy a **new** domain (InternetBS / Namecheap).
2. Cloudflare free plan. SSL/TLS = **Flexible**.
3. DNS: CNAME `@` → `ogadswall.com`. Delete leftover records.
4. Dashboard → Tools → Offerwall → Domains → Add Your Offerwall Domain.
5. Customize at Tools → Offerwall → Options.
6. Send users with `aff_sub4`:

```
https://exampledomain.com?aff_sub4=user@email.com
```

Their example uses an email. We must use the embed click UUID (`{sub_id}`), not an email and not `{user_id}` alone. The postback has no `{user_id}` macro. The only stable echo is `aff_sub*`.

7. Postbacks go to Tools → Postback URL (same global URL as catalogue clicks).

Partner wall URL an admin would paste into **Hosts its own wall**:

```
https://{their-wall-domain}?aff_sub4={sub_id}
```

`syncsCatalogue()` must be **false** if this is the only integration. An empty `fetchOffers()` would wipe hand-attached / previously synced rows.

Third-party Pocket/DroidOxy pages claim "OGAds does not have an offerwall" and route through OfferwallInc. That is stale relative to `docs.ogads.com` document 9. We follow their own docs.

---

## Content locker (not our product)

[Content Locker Integration Options](https://docs.ogads.com/documents/19-content-locker-integration-options). Overlay (`og_load()`) or a direct link. Custom domains via Tools → Custom Domains (separate CNAME, not `ogadswall.com`). We do not embed lockers.

Marketing site still sells lockers as the flagship. The Offer API is the GPT/API product.

---

## Postback

They GET a URL **we write**. Tools → Postback URL. Example they print:

```
https://website.com/postback.php?id={offer_id}&payout={payout}&ip={session_ip}
```

Attention box, verbatim:

> The parameters are case sensitive, any other macros will be stripped from the URL.

`{Offer_Id}` dies. `{transaction_id}` dies. `{status}` dies. `{USER_ID}` dies. A literal `key=s3cret` stays, because it is not a `{macro}`.

HTTP method is not named on the form. Their PHP example reads `$_GET`. Treat it as **GET**.

No retry policy, no required response body, no timeout, no chargeback URL, no IP list.

### Macros they will substitute

Copied from the live table. This is the complete set. Nothing else.

| Macro | Details |
|---|---|
| `{offer_id}` | ID of offer |
| `{offer_name}` | Name of offer |
| `{affiliate_id}` | ID of affiliate |
| `{source}` | Source value specified in the tracking link |
| `{session_ip}` | IP address that started the tracking session |
| `{payout}` | Amount paid to affiliate for conversion |
| `{date}` | Current date of conversion formatted as `YYYY-MM-DD` |
| `{time}` | Current time of conversion formatted as `HH:MM:SS` |
| `{datetime}` | Datetime conversion reported (`YYYY-MM-DD HH:MM:SS`) |
| `{session_timestamp}` | Time conversion happened (format **not** specified — unix vs datetime unknown) |
| `{aff_sub}` | Affiliate sub specified in the tracking link |
| `{aff_sub2}` | Affiliate sub 2 |
| `{aff_sub3}` | Affiliate sub 3 |
| `{aff_sub4}` | Affiliate sub 4 |
| `{aff_sub5}` | Affiliate sub 5 |
| `{ran}` | Randomly generated number |

### Macros they do **not** list

These are what a HasOffers-native network often has, and what the **current driver reads**. On this dashboard they are stripped if we put them in braces.

| Missing macro | Why it hurts |
|---|---|
| `{transaction_id}` | No network-issued idempotency key. Driver already falls back to `click + offer`. |
| `{status}` / `{event_id}` / chargeback flag | **No reversal signal in the docs.** Driver assumes `status=2`. That assumption is unverified and, if we paste `{status}`, it will be stripped so `status` is always missing and every fire looks credited. |
| `{user_id}` | Player id is whatever we stuffed into `aff_sub*`. |
| hash / signature | They sign nothing. |
| `{currency}` | Treat `payout` as USD. |
| `{goal_id}` | No multi-reward. |

`{ran}` is random. Never use it as `networkTxId`.

### URL we should paste

`OGAdsDriver` does not implement `postbackQuery()`, so `AbstractDriver::postbackUrl()` is:

```
https://{host}/postback/ogads
```

That is not enough. A correct template (our query keys on the left, their macros on the right):

```
https://{host}/postback/ogads?click_id={aff_sub4}&payout={payout}&offer_id={offer_id}&offer_name={offer_name}&ip={session_ip}&datetime={datetime}&session_timestamp={session_timestamp}&aff_sub={aff_sub}&aff_sub2={aff_sub2}&aff_sub3={aff_sub3}&aff_sub5={aff_sub5}&source={source}&affiliate_id={affiliate_id}
```

If `postback_key` is set, append a **literal**:

```
&key={the stored secret}
```

Do not write `&key={key}`. `{key}` is not in their table and will be stripped, so `verifyPostback()` would see an empty `key` and reject every fire.

Minimum the current `parsePostback()` needs to stop returning null: `aff_sub4` **or** whatever query key we map click id to, plus a numeric `payout`.

Pick one click slot and keep it. Official + current driver = **`aff_sub4`**.

### What `parsePostback()` reads today vs what they send

| Driver reads | Live macro / query | Match? |
|---|---|---|
| `aff_sub4` | `{aff_sub4}` | Yes, if we put `aff_sub4={aff_sub4}` or rename via `postbackQuery` |
| `payout` | `{payout}` | Yes |
| `offerid` | `{offer_id}` | **No.** Macro is `offer_id`. Query key is whatever we write. Driver looks for `offerid`. |
| `offername` | `{offer_name}` | **No.** Same. |
| `transaction_id` | (stripped) | **No.** Always null. Fallback `clickId:offerId` is the real key — and `offerId` is null if we still read `offerid`. |
| `session_ip` | `{session_ip}` | Yes, if present on the URL |
| `status` | (stripped) | **No.** Reversal branch never runs from a live fire. |
| `key` | literal we add | Only if we add it |

Until `postbackQuery()` + `parsePostback()` agree on `offer_id` / `offer_name` / no `status`, a live fire credits with a weak `networkTxId` (`uuid:`) and never reverses.

### Official PHP receiver

They insert `datetime`, `offer_id`, `payout`, `ip` (from `{session_ip}`), `aff_sub4`, `aff_sub5`. GET only. No signature check. No status. Sample URL in the comment:

```
https://example.com?postback.php?datetime={datetime}&offer_id={offer_id}&payout={payout}&ip={session_ip}&aff_sub4={aff_sub4}&aff_sub5={aff_sub5}
```

(Their comment has a broken `?` / `.php?` — ignore the typo, keep the macros.)

### Ingress we already have

`PostbackController` still:

1. Logs the raw request.
2. Resolves `ogads`.
3. Checks `networks.postback_ips` — **403 if empty or miss**.
4. `verifyPostback()` — optional `key`.
5. `parsePostback()` — 422 if click + numeric payout missing.

Firing IPs are not on any public page. Ask the AM. A partial list is worse than empty (empty fails every fire and gets noticed; a short list silently drops the ones from the missing address).

HasOffers / TUNE typically egresses from a small AWS set, but **do not invent addresses**.

---

## Verify

They sign nothing.

| Gate | Who |
|---|---|
| Source IP allowlist | Us. Required. Unpublished. |
| Optional `key=` literal | Us. `postback_key`. |
| HMAC / `{HASH}` | Does not exist. |

`verifyPostback()` returns `true` when `postback_key` is blank. That is only safe **after** `postback_ips` is filled. `PostbackController` already rejects an empty allowlist.

---

## Driver vs live docs

`OGAdsDriver` catalogues off the [Private Offer API](#private-offer-api). Remaining product limits, not code bugs:

| Live contract | What the driver does |
|---|---|
| `{domain}/api/offers?page=N` | `api_domain` credential, default `downloadlocked.com`. Loops to `meta.last_page`. |
| No visitor fields | None sent. The feed is the same for everyone. |
| Private token ≠ public key | `api_key` holds the private one. A mismatch is 403 `Invalid ability provided.`, surfaced verbatim by `testConnection()`. |
| Rows we cannot serve honestly | `syncable()` refuses them — 578 of 619 survive. |
| `{offer_id}` / `{offer_name}` / `{aff_sub4}` | `postbackQuery()` writes those keys. |
| No `{transaction_id}` / `{status}` | Credit only. `networkTxId` is `datetime:offer_id:click_id`. |
| `UK` | Mapped to `GB` before `parseCountries()`. Not sent on this feed, kept as a guard. |
| No firing IPs | Seeded allowlist is empty (rejects until pasted). |

The public `/api/v2` is no longer called from anywhere in this codebase. Its per-visitor problem is documented below because the trap outlived the code — anyone who "fixes" the driver back onto `/api/v2` reintroduces it.

---

## Recommended mapping (when the driver is touched)

### `credentialSchema()`

Keep `api_key` + optional `postback_key`. Add nothing until they invent a secret.

### `postbackQuery()`

```php
[
    'click_id' => '{aff_sub4}',
    'payout' => '{payout}',
    'offer_id' => '{offer_id}',
    'offer_name' => '{offer_name}',
    'ip' => '{session_ip}',
    'datetime' => '{datetime}',
    'session_timestamp' => '{session_timestamp}',
    'affiliate_id' => '{affiliate_id}',
    'source' => '{source}',
    'aff_sub' => '{aff_sub}',
    'aff_sub2' => '{aff_sub2}',
    'aff_sub3' => '{aff_sub3}',
    'aff_sub5' => '{aff_sub5}',
]
```

`parsePostback()` must then read **those query keys**, not `offerid` / `offername` / `aff_sub4`.

`networkTxId`: `{datetime}` + `{offer_id}` + `{aff_sub4}` (or `click_id` + `offer_id` as today). Same conversion re-fired with the same timestamp is the duplicate we reject. `{ran}` is not a key.

`status`: stay `'credited'` until they document a reversal. A guessed `status=2` that never arrives is fine; a guessed `status=2` that we then start sending as a macro is not — it will be stripped.

### `fetchOffers()`

Preferred: rewrite against the [Private Offer API](#private-offer-api) — `GET {tool-domain}/api/offers?page=N`, `Accept: application/json`, its own Bearer token, loop to `meta.last_page`, shorten names at `" - "`, skip offers carrying `offer_targeting_rules`. The driver-delta table in that section is the field-by-field diff.

If it stays on `/api/v2`: do not send `page` / `ctry` / `device` unless an AM says the old query still works on `unlockcontent.net`. Do send `ip` + `user_agent`. Do not send `min`. Decide the catalogue strategy before enabling sync.

### `buildTrackingUrl()`

Keep `aff_sub4={click_id}` on catalogue `link`s.

---

## Learn — API In Depth (members-only)

[learn.ogads.com/course/32-api-in-depth](https://learn.ogads.com/course/32-api-in-depth). Brandon. Last updated 06/2026. 7 videos / ~42 minutes. Login wall on Watch.

Curriculum printed on the public course page:

| # | Title | Length |
|---|---|---|
| 1 | Introduction | 01:14 |
| 2 | Using cURL & Fetching Offers | 05:40 |
| 3 | Installing Composer & Dependencies | 06:00 |
| 4 | Fetching Offers With Guzzle | 07:19 |
| 5 | Slim & Setting Up A Postback Controller | 10:46 |
| 6 | Displaying Offers With Twig | 07:57 |
| 7 | Building With Claude | 03:37 |

Worth watching on a logged-in account before rewriting the driver. It is the only official long-form Offer API + postback walkthrough. The dashboard page they already pasted is the spec; the course is the demo.

Other Learn courses (27 total, paginated 12 per page) are traffic / niches / TikTok / YouTube. Not integration.

---

## Payments (not integration)

Public docs are almost entirely payout FAQs. Useful only so we do not go looking for API notes there.

- Net 7 or Net 30 depending on the account.
- $50 minimum. Early payment is a separate request; they may refuse under the minimum.
- Methods: PayPal, Payoneer, crypto, wire, direct deposit, e-cheque. Payoneer is a dedicated 4-minute guide.
- Invoices live at `members.ogads.com/payments`. "Paid" on the invoice ≠ funds received.
- Crypto delays: wallet, cutoff, business hours.
- Affiliate managers on the marketing site: Bobby (`bobby_ogads`), Dee (`dee_ogads`), Zach (`Zach_ogads`).

None of this belongs in the driver.

---

## What we do not handle

- Content locker JS / `og_load()` / locker direct links.
- Custom locker domains (Cloudflare Flexible + per-account CNAME).
- Their hosted wall chrome (Tools → Offerwall → Options) unless we choose `hosts_wall`.
- PIN / VID as first-class `conversionType` values (no row field).
- `epc` sorting.
- OS floor scraping from `name` / `description`.
- `min` (blocked offers leaking back).
- Reversals, until they document one.
- `{ran}` as any kind of id.
- OfferwallInc / Pocket mediation. Their own wall docs supersede that.

---

## Live-fire checklist

1. Put the **Private Offer API** token in `api_key` — not a Tools → Offers API key. A public key here is `403 Invalid ability provided.`, and `testConnection()` now prints that string.
2. Ask AM for postback egress IPs. Put the **full** list on the network row. Do not enable with an empty allowlist.
3. Test connection. Expect `Connected.` and a count matching `meta.total` (619 on this account, 2026-08-26). A 403 with a message is a wrong-token problem; a 401 is a wrong-key problem.
4. Dry-run `fetchOffers()` before `active`: names carry no `" - "` tail, every tracking URL has exactly one `aff_sub4={click_id}`, no zero payouts, and the yield is close to `meta.total` minus the [refused rows](#what-we-refuse-to-sync). A yield far below that means a filter is too wide — find out which before the collapse guard does.
5. Paste `postbackUrl()` (once `postbackQuery()` exists) into Tools → Postback URL. Save. Confirm the dashboard still shows the braces unencoded (`%7Baff_sub4%7D` means they will send the literal string).
6. Click an offer: outbound URL has `aff_sub4=<uuid>`.
7. Their fire: GET, `click_id` (or `aff_sub4`) = that uuid, numeric `payout`, `offer_id` set, source IP on the allowlist. Expect `accepted`.
8. Repeat fire: same `networkTxId` → duplicate, still HTTP 200.
9. `unknown_click` means `aff_sub4` was missing or not our UUID. Fix the link / wall template, not `ConversionProcessor`.
10. Ask AM whether they ever send a chargeback. If yes, get the exact query they will use — it is not in this table.
11. Optional: watch API In Depth logged in, in case they added a postback signature after 06/2026. The static feed question is settled — it is the Private Offer API.

---

## Ask the AM

- Postback firing IPs (required).
- How do we get a **Private Offer API** token? (The static / all-country feed. It exists — see [Private Offer API](#private-offer-api). The rest of that section has its own AM list.)
- Do `page` / `ctry` / `device` on `unlockcontent.net` still do anything?
- Chargebacks: do they fire a second GET, and with which fields?
- `{session_timestamp}` format.
- Current canonical API host (`appsave.online` vs `appsave.space` vs `unlockcontent.net`).
- Whether `aff_sub` is reserved (AdsBridge vs Kintura disagree; official wall uses `aff_sub4`).
