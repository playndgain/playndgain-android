# MyChips (`mychips`)

Working notes for MyChips / MAF: hosted offerwall, S2S postbacks, and the (missing) catalogue. Driver: `app/Networks/Drivers/MyChipsDriver.php`. No fixture — there is no catalogue to record.

Fetched 2026-08-18 from [docs.mychips.io](https://docs.mychips.io/) — every sidebar page opened, [llms.txt](https://docs.mychips.io/llms.txt) + [llms-full.txt](https://docs.mychips.io/llms-full.txt) dumped, GitBook tables and dashboard screenshots read from the live DOM. The public `/api` page is **empty**. There is no publisher offer catalogue.

They host the tiles. We frame them (or deep-link them). Conversions come back on a GET we write in their Settings. Auth on that GET is **IP allowlist only**. There is no HMAC, no secret query param, no signature header.

This is a `hosts_wall` partner, not a catalogue network. `syncsCatalogue()` must stay **false**. An empty `fetchOffers()` would deactivate every offer we ever attached by hand.

Their pages:

| Page | URL | What it is |
|---|---|---|
| Introduction | [docs.mychips.io](https://docs.mychips.io/) / [introduction](https://docs.mychips.io/introduction) | One paragraph. Loyalty / monetization SDK. |
| Getting Started | [getting-started](https://docs.mychips.io/getting-started) | Platform picker. Links the four setup pages + Android / iOS / Unity / Flutter / iFrame. |
| Create a Publisher Account | [create-a-publisher-account](https://docs.mychips.io/getting-started/create-a-publisher-account) | Sign up on the MAF (MyChips) dashboard. |
| Create your App/Site | [create-your-app-site](https://docs.mychips.io/getting-started/create-your-app-site) | Sites → Add a New Site. Name, store/web URL, platform, optional logo. |
| Create an AdUnit | [create-an-adunit](https://docs.mychips.io/getting-started/create-an-adunit) | `content_id`. Margin, sandbox, whitelabel, virtual currency. |
| Test in Sandbox | [test-in-sanbox-mode](https://docs.mychips.io/getting-started/test-in-sanbox-mode) | Preview + "MAF Test Offer". Typo in the slug is theirs. |
| Reward Handling | [reward-handling](https://docs.mychips.io/reward-handling) | Index. Client SDK vs S2S. |
| Page 1 | [reward-handling/page-1](https://docs.mychips.io/reward-handling/page-1) | Stub. Two methods, no macros. |
| Webhook S2S Postback | [webhook-s2s-postback](https://docs.mychips.io/reward-handling/webhook-s2s-postback) | How we write the URL. GET. Reward rules. Dashboard screenshots. |
| Validating the Webhook S2S | [validating-the-webhook-s2s](https://docs.mychips.io/reward-handling/validating-the-webhook-s2s) | The only auth: firing IPs + X-Forwarded-For notes. |
| Rejected S2S | [rejected-s2s-webhook-postback](https://docs.mychips.io/reward-handling/rejected-s2s-webhook-postback) | Extra URL. `rejected_reason_id` 0–14. Severity only. |
| IAP S2S | [iap-s2s-webhook-postback](https://docs.mychips.io/reward-handling/iap-s2s-webhook-postback) | Extra URL, type **Revenue**. Purchase amount macros. |
| IAA S2S | [iaa-s2s-webhook-postback](https://docs.mychips.io/reward-handling/iaa-s2s-webhook-postback) | Extra URL, type **Ad Revenue**. Fires from **34.140.72.20**. |
| Deduction S2S | [deduction-s2s-webhook-postback](https://docs.mychips.io/reward-handling/deduction-s2s-webhook-postback) | Extra URL, type **Detuction** (their spelling). Chargebacks. |
| Billing | [billing](https://docs.mychips.io/billing) | Payments → Edit Profile. Company info. Not integration. |
| Unity / Android / RN / RN Expo / iOS / Flutter | under each slug | Official SDKs. Same wall, same postback. Not our product. |
| iFrame | [iframe](https://docs.mychips.io/iframe) | Web integration. Param table. Reward = S2S only. |
| WebView & Direct Link | [webview-and-direct-link](https://docs.mychips.io/webview-and-direct-link) | Same `sdk.mychips.io/content` URL + `webview=1`. Not recommended. |
| Revenue API | [revenue-api](https://docs.mychips.io/revenue-api) | GraphQL reporting. **Not a catalogue.** |
| Sample Revenue Metrics | [sample-revenue-metrics-api-requests](https://docs.mychips.io/revenue-api/sample-revenue-metrics-api-requests) | Same query, second host. |
| API | [api](https://docs.mychips.io/api) | Sidebar stub. **No body.** Captured empty 2026-08-18. |
| License | [license](https://docs.mychips.io/license) | MIT on the SDKs. Copyright 2025 MyChips (Maf.ad). |
| llms.txt | [llms.txt](https://docs.mychips.io/llms.txt) | Full page list. Append `.md` to any URL. |
| Publisher dashboard | [dashboard.maf.ad](https://dashboard.maf.ad/) / [login](https://dashboard.maf.ad/Account/Login) | Sites, AdUnits, Settings (API key + postback URL + macros), Payments. |
| Marketing | [maf.ad](https://maf.ad/) | MAF. Flagship product is MyChips. CPI / CPC / CPA / CPE. |
| iOS SDK source | [github.com/myappfree/mychips-ios-sdk](https://github.com/myappfree/mychips-ios-sdk) | SPM repo named in the iOS install page. |

---

## What this is for us

AdVault already frames a partner wall via `networks.wall_url` + `PartnerWall` (`{sub_id}`, `{user_id}`, `{app_id}`, `{country}`, `{device}`, `{lang}`). MyChips is that shape.

| Our piece | Their piece |
|---|---|
| `hosts_wall` + `wall_url` | `https://sdk.mychips.io/content?content_id=…&user_id={user_id}&click_id={sub_id}&aff_sub1={sub_id}&webview=1` |
| `networks.credentials.adunit_id` | Ad Unit ID / `content_id` |
| `networks.credentials.api_key` | Settings → API KEY. Needed for Revenue API and official SDKs. **Not** on the iframe URL (and must not be — `wall_url` is visible to every end user). |
| `networks.postback_ips` | The 13 MAF firing addresses below. Reject when empty. |
| `postbackQuery()` | We write the GET. They substitute macros. Dropping a macro from the pasted URL fails silently. |
| `fetchOffers()` / `syncsCatalogue()` | **Do not call anything.** There is no static catalogue. |
| `verifyPostback()` | Source IP ∈ `postback_ips`. No hash. |
| `buildTrackingUrl()` | Unused if we only embed their wall. Their SDK / iframe owns the click. |

`{sub_id}` is the value they must echo. Put it on **both** `click_id` and `aff_sub1`. Their iframe docs say keep `click_id` the same across events; `aff_sub1`–`aff_sub5` "can be passed and returned in the postback." Two slots survive a dashboard editor who forgets one macro.

---

## Credentials

Stored on `networks.credentials` (encrypted). Admin labels will come from `credentialSchema()` when the driver exists.

| Our key | Their name | Required | Where they show it |
|---|---|---|---|
| `adunit_id` | Ad Unit ID / `content_id` / `{adunit_id}` | yes | Sites → AdUnit. Also printed inside the iframe snippet (`content_id=`). UUID-shaped. Sample in docs: `28617c7e-0178-4b89-b258-74bcf171e`. |
| `api_key` | API KEY | yes for Revenue API / official SDK; **not** for iframe | Settings header. 2023 screenshot shows a 32-char hex. Copy icon next to it. |

There is **no documented postback secret**. `verifyPostback()` cannot compare a token they never send. The only real check is `networks.postback_ips`.

There is no documented expiry on the API key.

Do not put `api_key` on `wall_url`. The helper text on the admin field already forbids secrets there.

Talk to the AM / dashboard for: API key, first AdUnit approval (new publishers start in Sandbox), and a confirmation that the live Settings → Macros list still matches the table below.

---

## Dashboard model

MAF / MyChips. One publisher account. Then:

1. **Site** (Sites → Add a New Site)
   - Site Name
   - App Store or Website URL — domain only for web (`example.com`). Must match the platform the wall is hosted on.
   - Platform
   - Logo optional: JPG / PNG / GIF, max 5 MB, resized to 120×120
2. **AdUnit** (site → + New Adunit) — this is `content_id`

### AdUnit — General

| Field | What they say |
|---|---|
| Name | Unique in the dashboard. |
| Margin | **Percentage of total reward the publisher keeps.** 30% margin → 70% to the user, 30% to the publisher. This is how `{user_payout}` / `{user_payout_in_vc}` get computed on their side. |
| Sandbox | Mock wall + test postbacks. **No revenue counted or granted.** Every AdUnit from a new publisher starts in Sandbox and needs **manual approval**. After the first approval, later AdUnits can be created live without another review. |

### AdUnit — Whitelabel

| Field | What they say |
|---|---|
| Whitelabel | On = customize the wall. |
| Color | Accent from a picker. |
| Cover | 2000×560, JPG / PNG / GIF, max 5 MB. |

### AdUnit — Virtual currency

| Field | What they say |
|---|---|
| Virtual Currency Icon | 250×250, JPG / PNG / GIF / SVG, max 5 MB. |
| Virtual Currency Name | What the user sees on cards. |
| Virtual Currency Ratio | Coins the user must collect to redeem **$1**. "The redeem rate of your platform." They use this to fill `{user_payout_in_vc}`. |
| Self Managed Currency | Currency managed inside MyChips. **Disables Virtual Currency.** Official SDKs then expect `CheckReward` / `OnRewardReceived` instead of (or in addition to) S2S. If we already do S2S they say skip that snippet. |
| Show Decimal | e.g. 7.99 on cards. |
| Round Up Virtual Currency | 1.7 → 2. |
| Enable Chips | Secondary virtual currency **handled by their offerwall**. Not our coins. |

### Sandbox test

Preview button on the AdUnit. Offer named **"MAF Test Offer"** grants a reward on every click. In Sandbox, `{user_payout}` is **0**.

---

## Hosts

| Host | Role |
|---|---|
| `dashboard.maf.ad` | Publisher dashboard / "Universal Developer Portal" |
| `sdk.mychips.io` | Offerwall page (`/content`) |
| `v1.mychips.io` | Web SDK bundle (`/SDK/latest/my-chips-sdk.bundle.js`) |
| `api.mychips.io` | Click / conversion **redirects**. Path contains `redirect`. Open in an **external** browser, not inside the iframe / WebView. |
| `mychips://` | Custom URL scheme. Offerwall → native (reward sync). WebView should override and ignore / handle. |
| `public-api.myappfree.com` | Revenue GraphQL (main Revenue API page) |
| `universal-api.myappfree.com` | Same query, sample-requests page. Two hosts in the same docs. Confirm which is live before calling. |
| `europe-west1-maven.pkg.dev/mychips-b31fe/mychips-android-sdk` | Android Maven |
| `github.com/myappfree/mychips-ios-sdk` | iOS SPM |

---

## Hosted wall (iframe / WebView / direct link)

Base:

```
https://sdk.mychips.io/content
```

Official SDKs wrap the same origin. Web is the path we will use.

### Web SDK bootstrap (iframe page)

```html
<script src="https://v1.mychips.io/SDK/latest/my-chips-sdk.bundle.js"></script>
<script>myChipsSDK('init', {userId: '{{user_id}}'})</script>
```

Then the iframe. `allow="ch-ua-platform; ch-ua-platform-version"` is on every sample.

**iOS sample**

```
https://sdk.mychips.io/content?content_id={adunit_id}&user_id={user_id}&idfa={idfa}&gender={gender}&age={age}
```

**Android sample**

```
https://sdk.mychips.io/content?content_id={adunit_id}&user_id={user_id}&gaid={gaid}&gender={gender}&age={age}
```

Worked values they print (not ours):

```
content_id=28617c7e-0178-4b89-b258-74bcf171e
user_id=5d41402abc4b2a76b9719d911017c592
idfa=6D92078A-8246-4BA4-AE5B-76104861E7DC
gaid=38400000-8cf0-11bd-b23e-10b96e40000d
gender=m
age=30
```

Where to copy the snippet: AdUnit → Edit → Integration. The green box is the ready iframe. `content_id` in that box **is** the Ad Unit ID.

**iFrame reward path is S2S only.** No client `CheckReward` on web.

### WebView / direct link (not recommended)

They want the official SDK. WebView is for HTML / low-code only. Same base URL, plus **`webview=1` (mandatory)**.

```
https://sdk.mychips.io/content?content_id={adunit_id}&user_id={user_id}&webview=1
```

Docs samples have a typo: `{your_adunit_id]` — missing `}`. The worked examples use a real id and are fine.

### Wall query parameters

Merged from the iframe table and the WebView page. They do not print one table.

| Param | Mandatory | Example | Notes |
|---|---|---|---|
| `content_id` | yes | `28617c7e-0178-4b89-b258-74bcf171e` | Ad Unit ID. |
| `user_id` | yes (iframe table); auto-generated if omitted | `U9221` | Stable publisher user id. Hash / UUID / numeric all accepted on WebView. If omitted they mint one — we never want that; attribution dies. |
| `webview` | **yes on WebView / direct link** | `1` | Bool. Not on the iframe samples. If we frame them, send it. |
| `gaid` | no, **highly recommended** on Android | `38400000-8cf0-11bd-b23e-10b96e40000d` | eCPM. |
| `idfa` | no, **highly recommended** on iOS | `6D92078A-8246-4BA4-AE5B-76104861E7DC` | eCPM. |
| `click_id` | no (iframe only) | `6a204bd89f3` | "A unique clickid. Keep the same value at each events." Our `{sub_id}` belongs here. |
| `gender` | no | iframe: `m` / `f` / `n`. WebView: `m` / `f` / `o`. SDK: Male / Female / Other | Three pages, three third values. Do not invent a mapping until we send it. |
| `age` | no | `45` | Integer **0–100**. |
| `os_version` | no (WebView only) | | OS version string. |
| `aff_sub1` … `aff_sub5` | no (iframe) | | "Custom parameters that can be passed and **returned in the postback**." Second home for `{sub_id}`. |

`PartnerWall` does not currently substitute `gaid` / `idfa` / `age` / `gender` / `os_version`. Those stay off the template until we add macros or a driver-specific builder. Missing device IDs cost eCPM, not tracking, if `user_id` + `click_id` are set.

### Suggested `wall_url`

```
https://sdk.mychips.io/content?content_id={ADUNIT}&user_id={user_id}&click_id={sub_id}&aff_sub1={sub_id}&webview=1
```

Replace `{ADUNIT}` with the real Ad Unit ID (static, not a `PartnerWall` macro). `{sub_id}` is the embed click we already persist. `{user_id}` is the publisher's end-user id.

If their frame-ancestors refuses us, `wall_new_tab` is already on the admin form.

### WebView rules they print (for a native wrapper, not our iframe)

| Rule | Why |
|---|---|
| `content_id` + `user_id` + `webview=1` | No identity, no reward. |
| `gaid` / `idfa` | eCPM. |
| JavaScript on | Wall will not render. |
| DOM storage on | Session / localStorage. |
| Cache off (`LOAD_NO_CACHE`) | Stale wall. |
| URLs starting `https://api.mychips.io` **and** path containing `redirect` → **external browser** | Conversion tracking. |
| Handle `mychips://` | Reward sync bridge. Override and ignore or handle natively. |
| Custom error page | No blank WebView. |
| Back: close when URL contains `home` | Their wording. |
| File upload (`onShowFileChooser`) | Some offers upload. |
| **Do not preload** | Breaks impression tracking / eCPM. |

Code samples exist for Android Java, Android Kotlin, Flutter (`webview_flutter`), iOS Swift. Flutter sample parses `mychips://` query `amount` + `currency` for a snackbar. That is client UX, not our credit path.

---

## Postbacks

**We write the URL.** Settings → Postback URL (left). Macros listed on the right. **Add Parameter +** appends `&name={macro}`. **Save Postback URL**.

Valid shape they print:

```
https://yourdomain.com/?user_id={user_id}&payout={payout}...
```

Ours (once the driver key exists):

```
https://{host}/postback/mychips?click_id={click_id}&payout={payout}&user_id={user_id}&event_name={event_name}&campaign_id={campaign_id}&postback_id={postback_id}&user_payout={user_payout}&aff_sub1={aff_sub1}&conversion_country={conversion_country}&conversion_time={unix_timestamp}
```

Paste **exactly** those query names. Their examples use `clickid={click_id}` as the *key* and `{click_id}` as the *macro*. The key is ours to choose; `parsePostback()` will read `click_id`, not `clickid`.

### Rules they print (Webhook S2S)

1. **HTTP GET only.** POST is not documented. Accept GET.
2. Parse every query param.
3. Allowlist every MAF firing IP (next section).
4. **CPI bidding.** Publisher is paid on **install only**. Later events arrive with `payout = 0` and `user_payout = $X`. **Do not reject those.** Treating `payout=0` as invalid drops the rest of a multi-event offer and, if we also skip the user credit, underpays the user on events they completed.
5. **Reward math.**
   - **Do not** credit from `{user_payout_in_vc}`. Informational only.
   - Credit from **`payout`** (recommended) or **`user_payout`**.
   - Conversion into our coins is **our** job.

Our money path already wants USD from the network and applies share / rate on our side. **`payout` is the field.** Confirm with the AM whether `payout` is publisher revenue (after their margin) or advertiser gross. The AdUnit margin text talks about splitting "total reward" between publisher and user; `{user_payout}` is the user slice, `{payout}` is the one they tell us to prefer.

### What is not documented

- Expected HTTP status / body. No "return `OK`" / "return `1`".
- Retries. No count, no window.
- HMAC / shared secret / header signature.
- Idempotency key. Closest candidates: `{postback_id}` (deduction example), else `{click_id}` + `{event_name}` + `{unix_timestamp}`.
- Chargeback on the **main** URL. Reversals are a **separate** Deduction postback (positive `{payout}`; we negate).
- Whether a rejected event also hits the main URL.

Until they say otherwise: answer **200** with a short body, treat `{postback_id}` as the unique id when present, and do not invent a hash.

### Sandbox

`{user_payout}` is **0**. Wall is mock. Postbacks still fire. No revenue.

---

## Macros

The live list lives on **Dashboard → Settings → Publisher's Macros**. The webhook page only says "look to the right." The 2023 screenshots on that page (`postback2-1-1024x683.png`, `postback-list2-1024x683.png` on maf.ad) show a panel that **cuts off at `{session_id}`**. Current prose adds macros the screenshot never reached.

Two columns below: **seen in a current doc sentence or example**, vs **seen only on the 2023 screenshot**. Re-copy the live panel before shipping the driver.

| Macro | Seen | Meaning |
|---|---|---|
| `{click_id}` | docs + screenshot | Click id. "The Click Id." Echo of what we put on the wall URL. |
| `{user_id}` | docs examples | Publisher user id. |
| `{payout}` | docs + screenshot | **Recommended credit / revenue field.** Deduction sends this as a **positive** number. |
| `{user_payout}` | docs | User slice. **0** in Sandbox and on rejected events. CPI post-install events still have `$X` here while `payout=0`. |
| `{user_payout_in_vc}` | docs | Virtual-currency amount. **Do not credit from this.** |
| `{payout_in_currency}` | 2023 screenshot | Unlabelled on the screenshot. Do not guess vs `{payout}`. |
| `{event_name}` | docs + screenshot | Event / goal name. |
| `{campaign_id}` | 2023 screenshot | Campaign id. Closest thing to an offer id. |
| `{campaign_name}` | 2023 screenshot | Campaign name. |
| `{install_id}` | 2023 screenshot | Install id. |
| `{session_id}` | 2023 screenshot (last visible row) | Session. List continues below the fold. |
| `{aff_site}` | 2023 screenshot | "The affiliate site." |
| `{aff_sub1}` … `{aff_sub5}` | docs + screenshot | Custom. Returned on the postback if we sent them on the wall / SDK. |
| `{user_agent}` | 2023 screenshot | UA. |
| `{unix_timestamp}` | docs + screenshot | Unix time. IAP/IAA examples bind it as `conversion_time={unix_timestamp}`. |
| `{conversion_country}` | IAP / IAA examples | Country of the conversion. |
| `{postback_id}` | deduction example | Unique id per fire. Best idempotency candidate. |
| `{rejected_reason_id}` | rejected page | Integer 0–14. |
| `{event_value}` | IAP / IAA | Purchase amount or ad amount. |
| `{event_value_usd}` | IAP / IAA | USD string, two decimals. |
| `{event_value_currency}` | IAP / IAA | ISO currency (e.g. `USD`). |

`clickid` in their example URLs is a **query key they chose**, not a macro. The macro is `{click_id}`.

---

## Validating the webhook (IPs)

"To validate a Webhook, simply verify the IP it's coming from in the HTTP header."

Firing addresses, copied in their order (2026-08-18):

```
168.63.37.145
20.54.96.37
13.70.194.104
34.146.139.91
34.54.234.115
34.54.248.253
34.64.93.62
34.47.93.43
34.84.180.208
48.209.163.104
4.207.193.125
48.209.162.122
34.140.72.20
```

**IAA (ad revenue) postbacks come only from `34.140.72.20`.** That IP is also in the main list. Missing it drops IAA only; missing the rest drops conversions.

Paste this list into `networks.postback_ips` before the network is switched on. Empty list = reject every fire (same fail-closed as HangMyAds).

### X-Forwarded-For

They warn the header is spoofable. Trust it only from **our** proxy. Position of the real client IP:

| Front | Where the true client sits |
|---|---|
| AWS ELB/ALB | **First** in the list |
| GCP | **Second-to-last** |
| Azure | **Last** |

They ship a PHP snippet for AWS (`explode` `HTTP_X_FORWARDED_FOR`, take `[0]`, else `REMOTE_ADDR`). We already have a proxy-aware client IP — do not add a second parser. Confirm which hop our load balancer writes before relying on this list.

---

## Additional postbacks

Settings → **Additional Postback (optional)** (bottom left). Dropdown + URL + **Add+**.

| Dropdown label (theirs) | Page | When |
|---|---|---|
| Rejected | Rejected S2S | Fraud / policy flag at event time |
| Revenue | IAP S2S | In-app purchase completed. **May also fire the main conversion postback.** Reward separately or treat as a richer completion. |
| Ad Revenue | IAA S2S | In-app ad viewed. IP **34.140.72.20**. |
| Detuction | Deduction S2S | Retroactive chargeback. Their spelling. |

Same GET, same standard macros. Extra fields below.

### Rejected

```
https://yourdomain.org/postback?user_id={user_id}&...&payout=0&user_payout=0&rejected_reason_id={rejected_reason_id}
```

`payout` and `user_payout` are **always 0**.

`rejected_reason_id` is 0–14. They published **severity only** — no reason text, no name. Their own enforcement (reference, not a requirement):

- High, first time → ban
- Several Medium inside 7 days → ban
- Low → risk score / content quality

| ID | Severity |
|---|---|
| 0 | Low |
| 1 | High |
| 2 | Medium |
| 3 | Medium |
| 4 | Low |
| 5 | Medium |
| 6 | Low |
| 7 | Low |
| 8 | Medium |
| 9 | Low |
| 10 | High |
| 11 | Medium |
| 12 | High |
| 13 | Medium |
| 14 | High |

Ask the AM for the missing reason strings before we surface them in admin.

### IAP (Revenue)

Extra macros: `{event_value}` (purchase amount), `{event_value_usd}` (USD string, 2 dp), `{event_value_currency}` (ISO).

```
https://yourdomain.org/postback?clickid={click_id}&event_name={event_name}&aff_sub1={aff_sub1}&aff_sub2={aff_sub2}&conversion_country={conversion_country}&user_id={user_id}&event_value={event_value}&event_value_currency={event_value_currency}&event_value_usd={event_value_usd}&conversion_time={unix_timestamp}
```

### IAA (Ad Revenue)

Same extra macros; `{event_value}` is "Advertisement amount." Same example URL as IAP. Fires from **34.140.72.20**.

The IAA page heading says "IAP-Specific Parameters" on the IAA table. Copy-paste error on their side.

### Deduction

One GET **per deducted conversion**, not per click.

Example: install $0.50 + level 5 $1.00 + level 10 $2.00, then that `click_id` is deducted → **three** deduction postbacks, each with that event's `{payout}`.

`{payout}` is **positive**. If we need a negative amount they say write `payout=-{payout}` in the **URL template**. Our parser should negate on the deduction endpoint rather than asking them to send a minus.

```
https://yourdomain.org/postback?clickid={click_id}&event_name={event_name}&payout={payout}&user_payout={user_payout}&user_id={user_id}&postback_id={postback_id}
```

Real-time fraud is supposed to be a Rejected postback. Deduction is for patterns they only see **after** install events. Related install and later events are clawed back one fire at a time.

Anti-fraud "blocks fraudulent conversions in real time" — see Rejected. Deduction is the late path.

---

## Catalogue / fetching

**There is no publisher offer API.**

- Sidebar **API** page: title only.
- Revenue API: impressions / clicks / revenue by site + ad unit. No offer rows, no tracking URLs.
- Native SDK `adView.load()`: "Calls the API to fetch campaigns" **inside the SDK**. No URL, no auth recipe, no JSON schema for publishers. Rewards on those objects are **virtual currency**, not USD.

Do not scrape `sdk.mychips.io/content` for tiles. That is their wall, impression-sensitive, and they forbid preload.

If we ever need our own cards: ask the AM whether a static catalogue exists off-docs. Until then this network is embed + postback, same family as a `hosts_wall` partner with `syncsCatalogue() === false`.

### Native campaign objects (SDK only)

Useful if an unpublished API ever shows up with the same names. Android and iOS print the same model.

**MCCampaign**

| Field | Type | Meaning |
|---|---|---|
| `id` | string | Unique campaign id |
| `name` | string | Display name |
| `type` | string | See constants. String, not enum — unknown types must not crash. |
| `creatives` | object | Images |
| `links` | object | Tracking / detail URLs |
| `totalConvertedValue` | number | Total reward in **their** virtual currency, including promo |
| `remainingConvertedValue` | number | What the user can still earn |
| `promoRatio` | number | `1.0` = none, `2.0` = double |
| `progress` | object or null | Null / nil if the user has not started |

**MCCreatives:** `thumbnail` (square, nullable), `cover` (banner, nullable).

**MCLinks:** `trackingUrl` (click), `trackingPixelUrl` (impression, SDK fires this), `detailUrl` (opened on tap).

**MCProgress:** `status`, `eventsCompleted`, `totalEvents`, `valueEarned` (VC), `progressValue` (0–100).

**MCMeta:** `version` (response format), `count`.

**`MCCampaignType` values:** `MultiReward`, `Play2Earn`, `SingleReward`.

**`MCCampaignStatus` values:** `clicked`, `started`, `installed`, `completed`, `expired`, `closed`.

Default Android view ids (custom renderer): `mc_iv_thumbnail`, `mc_tv_name`, `mc_tv_reward`, `mc_iv_currency`, `mc_tv_badge_promo`, `mc_tv_badge_progress`.

Figma they point at: [MyChips SDK Default Layouts](https://www.figma.com/board/7ctPofi9irp7PV4TwT3a7l/MyChips-SDK-Default-Layouts).

Native `setMaxCampaigns(10)` is "show all (default)" — catalogue behind the SDK is not huge, or they cap at 10.

---

## Revenue API (reporting only)

Not `fetchOffers()`. Keep it off the sync job.

```
POST https://public-api.myappfree.com/graphql/index.html
Content-Type: application/json
x-api-key: {api_key}
```

Sample-requests page uses `https://universal-api.myappfree.com/graphql/index.html` with the same body. Two hosts. Probe both with a real key before wiring anything.

Auth: header `x-api-key`. From the dashboard.

Variables: `dateFrom`, `dateTo` as `YYYY-MM-DD HH:MM:SS`. Required. `DateFrom` must be ≤ `DateTo`.

```graphql
query RevenueMetrics($fromDate: String!, $toDate: String!) {
  revenueMetrics(where: { dateFrom: $fromDate, dateTo: $toDate }) {
    total
    items {
      siteId
      siteName
      adunitId
      adunitName
      impressions
      clicks
      revenue
      ecpm
      arpdau
      dau
    }
  }
}
```

**200 example**

```json
{
  "data": {
    "revenueMetrics": {
      "total": 1,
      "items": [
        {
          "siteId": 123,
          "siteName": "Example Site",
          "adunitId": 456,
          "adunitName": "Top Banner",
          "impressions": 10000,
          "clicks": 500,
          "revenue": 1500.50,
          "ecpm": 15.05,
          "arpdau": 3.25,
          "dau": 500
        }
      ]
    }
  }
}
```

`adunitId` is an **integer** in the sample (`456`). Wall `content_id` is a **UUID string**. Do not assume they are the same identifier.

| Field | Type | Meaning |
|---|---|---|
| `total` | number | Item count |
| `siteId` | integer | Site |
| `siteName` | string | Site name |
| `adunitId` | integer | Ad unit (reporting id) |
| `adunitName` | string | Ad unit name |
| `impressions` | integer | |
| `clicks` | integer | |
| `revenue` | number | |
| `ecpm` | number | |
| `arpdau` | number | Average revenue per DAU |
| `dau` | integer | |

Errors they print (GraphQL `errors[]` + `data.revenueMetrics: null`):

| Message | Cause |
|---|---|
| `Property DateFrom must be earlier than or equal to DateTo. Please correct the date range.` | Flipped range |
| `Property DateTo should be in the format yyyy-MM-dd HH:mm:ss. Please correct it.` | Bad timestamp |
| `The current user is not authorized to access this resource.` (`AUTH_NOT_AUTHORIZED`) | Bad / missing `x-api-key` |

No rate limit printed.

---

## Official SDKs (not our product)

Same wall, same S2S. Listed so we do not re-learn it if a publisher asks.

| Platform | Current version (docs) | Date | Install |
|---|---|---|---|
| Unity | 1.2.6 | 2025-10-29 | Unity package file on the docs page. Min Unity **2020.3**. Prefab via Window → MyChips Settings → Add Prefab. |
| Android | 1.2.0 | 2026-04-24 | Maven `io.mychips:offerwall:+`. Min API **27**. Native campaigns need SDK **≥ 1.2.1** (their own pages disagree with the 1.2.0 release note). |
| iOS | (install page, native ≥ 1.2.1) | | SPM `https://github.com/myappfree/mychips-ios-sdk`. Xcode 16+, iOS **14+**. `import MyChipsSdk`. |
| React Native | 0.8.7 | 2025-11-20 | `mychips-react-sdk`. Needs `@react-native-async-storage/async-storage`, `@react-native-community/netinfo`, `react-native-webview`. RN &lt; 0.74: patch Boost + podspec min iOS. |
| RN Expo | (same reward page as RN) | | Separate install page. |
| Flutter | | | `flutter pub add my_chips_flutter_sdk`. Import path in the docs is truncated (`my_chips_flutter_sdk.dar`). |
| Web | | | `v1.mychips.io/SDK/latest/my-chips-sdk.bundle.js` |

**Init** is always API key + optional user id (auto-generated if missing) + mandatory advertising id (GAID / IDFA) + optional age / gender / `aff_sub1`–`aff_sub5`.

Show wall:

| Platform | Call |
|---|---|
| Unity | `MCOfferwallObject.Instance.ShowOfferwall()` after Ad Unit ID on the prefab |
| Android | `MCOfferwallController(this).Show("AD_UNIT_ID")` + `MCOfferwallActivity` in the manifest |
| iOS | `MCWebViewController(adunitId:onClose:)` |
| RN | `<MCOfferwallView adunitId={ADUNITID} />` |
| Flutter | `OfferwallPage(adunitId:, showTopBar: false)` — top bar off by default |

**Client reward** (`CheckReward` / `getReward` / `OnRewardReceived` / `RewardDTO.GetRewardInVirtualCurrency()` / `reward.virtualCurrencyReward`): **only if Self-Managed Currency**. Skip when S2S is on. Check on app start and resume. No reward and no error → callback is silent.

Unity 1.2.6 added `SetAge` / `SetGender`. Android 1.2.0 added native customizations (scroll, in-app details, icons, colors, loading, click, lifecycle). RN 0.8.7 lets the host trigger offerwall back navigation.

Unity FAQ: duplicate-dependency fight with UniWebView.

License: MIT (2025 MyChips / Maf.ad) on `mychips-react-sdk`, `mychips-react-native-sdk`, `mychips-android-sdk`, `mychips-ios-sdk`, `mychips-flutter-sdk`, `mychips-unity-sdk`.

---

## Driver sketch (when we build it)

Architecture: `hosts_wall` partner. No catalogue.

**Backend**

- `key()`: `mychips`
- `syncsCatalogue()`: `false`
- `fetchOffers()`: empty / unused
- `testConnection()`: do not hit Revenue API just to say hello (writes nothing useful for offers). A cheap 200 on `sdk.mychips.io/content?content_id=…` without leaking the API key is enough, or skip like Direct.
- `credentialSchema()`: `adunit_id`, `api_key`
- `postbackQuery()`: at least `click_id`, `payout`, `user_id`, `event_name`, `campaign_id`, `postback_id`, `user_payout`, `aff_sub1`. Add deduction / rejected as the same path with flags, or separate additional URLs that still hit `/postback/mychips`.
- `parsePostback()`: require something we can idempotize (`postback_id` or `click_id`+`event_name`) and a parseable `payout`. Accept `payout=0` (CPI follow-up, rejected, sandbox).
- `verifyPostback()`: IP in `postback_ips`. No hash.
- Deduction: negate `payout`. Do not wait for them to send `payout=-{payout}`.
- Rejected: `payout=0` + `rejected_reason_id` → do not credit.

**Frontend / wall**

- Admin: `hosts_wall` + `wall_url` template above + `postback_ips` filled from the list.
- `wall_new_tab` if they refuse to be framed.

**Database**

- No new tables. Existing `networks.wall_url`, `networks.postback_ips`, encrypted credentials.

**Testing**

- Partner wall substitution: `{sub_id}` lands on `click_id` and `aff_sub1`.
- Postback GET with `payout=0` + `user_payout>0` is accepted (CPI).
- Postback from a non-listed IP is rejected.
- Deduction with positive `payout` reverses that amount.
- Sync job never touches this network's offers.

---

## Gaps / ask the AM

1. Live **Macros** panel — screenshot is from 2023 and cropped. Re-copy every row.
2. Is `{payout}` publisher revenue after margin, or gross?
3. Rejected reason **names** for ids 0–14.
4. Expected GET response and retry policy.
5. Idempotency field (`postback_id` vs something else).
6. Does a rejected event also hit the main URL?
7. Confirm IAA-only IP `34.140.72.20` and whether the other 12 still fire in 2026.
8. Any unpublished static catalogue (the empty `/api` page).
9. Which GraphQL host is canonical (`public-api` vs `universal-api`).
10. First AdUnit still needs manual Sandbox approval — lead time.

---

## Doc bugs to ignore

Theirs, not ours. Left here so we do not "fix" them in the driver.

- Getting Started slug: `test-in-sanbox-mode`.
- Deduction dropdown: **Detuction**.
- IAA page: table titled "IAP-Specific Parameters."
- WebView URL samples: `{your_adunit_id]`.
- Flutter import: `my_chips_flutter_sdk.dar`.
- Reward Handling "Page 1": unfinished sentences.
- `/api`: empty.
- Gender: `n` vs `o` vs `Other`.
- Android version: release note 1.2.0 vs native pages requiring 1.2.1.
- Revenue `adunitId` integer vs wall `content_id` UUID.
