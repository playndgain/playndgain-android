# Fraud Detection — Design

**Date:** 2026-09-09
**Status:** Approved in brainstorming, not yet planned
**Supersedes nothing.** Extends `docs/05-fraud-engine.md`; every principle there still holds.

---

## 0. Implementation status — 2026-09-09

| Item | Status |
|---|---|
| §4.3 IPQS quota migration + seeder (`daily_cap` 200, `ttl_seconds` 7d) | **Done** |
| §4.3/§4.6 `ip_risk_score` migration + seeder (threshold 27, `block`, weight 40) | **Done** |
| §5 `emulator_device` rule, seeded row, `blocked_unavailable` in `en` + `ar` | **Done** — a bare `emulator` token was cut from the seeded list; it is a substring and matched QA/WebView build strings, exactly the over-broad case §5 forbids. Kept as a config candidate for validation against real traffic. |
| §6A.3 `FraudContext::$conversionIp` + `ConversionProcessor` wiring | **Done** |
| §6A.4 `conversion_proxy_ip`, `conversion_ip_mismatch` rules + seeded rows | **Done** |
| Rule registration in `FraudServiceProvider`; `SeedersTest` counts 11/12 | **Done** |
| §4.2 Provisioning — GeoIP credentials, `geoip:update`, provider keys | **Not started — owner action, and nothing above detects anything until it is done** |
| §4.5 Score distribution on the Protection page | **Done** — sampled at render in `WallGate::context()` via `IpScoreHistogram`; ten bands, 24h/7d/30d windows, exact at-or-above-threshold count |
| §6 Track 2b — client signals (`emulator_signals`, `device_signals_absent`) | **Not started** |

Verification at time of writing: `pest` **1228 passed / 0 failed**, `pint`
clean, `phpstan` clean for every file touched here. Ten unrelated phpstan errors
remain, all in files this work did not touch: `Payments.php`,
`OfferResource.php` and `ConversionColumns.php`.

**The suite must be run against a database no one else is using.** A second
session was running `migrate:fresh` plus the full suite in a loop against
`playndgain_test` throughout this work, which produces 15–200 `QueryException`
failures scattered across unrelated files, a different set every run. The number
above was taken on a private database (`DB_DATABASE=... vendor/bin/pest`). Any
run that reports schema errors — `Unknown column`, `Table ... doesn't exist`,
`Table 'migrations' already exists` — is that collision, not a regression.

**A note for whoever runs this next:** the seeder changes also require a rebuilt
test database, so run `migrate:fresh` on the test connection before drawing any
conclusion from a red suite.

**`theme.css` changed, so `npm run build` is required**, followed by
`php artisan responsecache:clear` — `BuildFreshnessTest` asserts the compiled
assets are newer than their sources and will fail until it is run.

**Both seeders were changed alongside their migrations.** A guarded migration
alone moves an install that has already been seeded; a fresh install takes the
seeder's value and would otherwise still come up at the old default. The
existing `promote_vpn_proxy_ip_to_block` sets the same precedent.

Because Track 2b has not landed, the pre-click seeded count is **11**, not the
13 §8 anticipates. Post-conversion is already **12**.

---

## 1. Goal, and what this is not

The request was "zero fraud". That target is not adopted here, for a reason that
is structural rather than pessimistic: the platform currently has no
false-positive rate, no false-negative rate, and no per-rule precision anywhere
in the codebase. "Zero fraud" is therefore unfalsifiable — it can be declared
but never demonstrated, and the configuration that satisfies it on paper is the
one that refuses honest users.

The target adopted instead, and agreed with the owner:

> **Refuse the connections we can identify deterministically. Hold everything
> statistical for review. Never refuse a person on a guess.**

Concretely, as decided:

| Traffic | Treatment |
|---|---|
| VPN, proxy, Tor, datacenter, residential proxy | **Refuse** — deterministic once the IP layer has data |
| Emulators | **Refuse** what we can identify; the ceiling is honestly limited (§6) |
| Banned end users | **Refuse** |
| Off-geo clicks (`geo_mismatch`), bots (`ua_anomaly`) | **Refuse** — confirmed by the owner as intended |
| Everything statistical — velocity, caps, spikes, impossible speed | **Flag** — held for review, never refused |

This document covers two tracks. Track 1 is provisioning an engine that already
exists. Track 2 is the only genuinely new detection being built.

---

## 2. What already exists — do not rebuild any of this

The fraud engine is mature. An implementer picking this up should read
`docs/05-fraud-engine.md` first and treat the following as done:

- `FraudPipeline` with two stages (`PreClick`, `PostConversion`), database-held
  rules, per-rule `action` (`block` / `flag` / `score`) and `score_weight`,
  admin-tunable without deploy.
- A render gate (`WallGate::protectionBlock()`) running `RenderGateRule`
  implementations before the wall paints.
- 20 seeded rules across both stages.
- `ChainedIpIntelligence` — local databases, then paid escalation, with three
  cache layers, per-provider timeout, circuit breaker, daily cap, and a consent
  gate before any address leaves the platform.
- `AutoBan` with 7-day expiry and publisher scoping.
- Admin surfaces: Fraud Rules, Fraud Events, Review Queue, Protection, Health.
- `FraudEvent` recording every trigger with the inputs the rule saw.

**Nothing in the pipeline architecture is being changed.** Both new rules in
Track 2 are ordinary `Rule` implementations with seeded rows.

---

## 3. The finding that reorders everything

Five rules — `vpn_proxy_ip`, `datacenter_ip`, `tor_ip`, `residential_proxy_ip`,
`ip_risk_score` — are wired into both the render gate and the click path, and
four are seeded at `block`. **They currently detect nothing.**

Evidence, verified in this working copy on 2026-09-09:

- `storage/app/geoip` does not exist. None of `GeoLite2-Country.mmdb`,
  `GeoLite2-ASN.mmdb`, `IP2PROXY-LITE-PX11.BIN` is present.
- `MAXMIND_LICENSE_KEY` and `IP2LOCATION_DOWNLOAD_TOKEN` are empty in `.env`.
- Both `ip_providers` rows seed `enabled = false` with no `api_key`
  (`IpProviderSeeder`), so escalation never runs.

`MaxMindIpIntelligence::proxy()` fails open by design — a missing database
returns `['is_proxy' => false, 'proxy_type' => null, ...]` rather than throwing,
because an offerwall that stops serving when a data file is stale is worse than
one that serves. That design is correct and is not being changed. Its
consequence when *no* file has ever been installed is that every IP-family rule
evaluates to `pass()`.

**This is a provisioning gap, not a code defect.** No rule logic needs fixing.

> **First action for the owner, before any code:** open the admin **Health**
> page and read the *GeoIP databases* check. It reports presence and age per
> database. If production also reports missing, §4 is the entire fix for the
> VPN/proxy half of this plan. The finding above was verified locally only.

---

## 4. Track 1 — Provision the IP layer

### 4.1 The cascade already matches the requested behaviour

The owner specified: local data first; if it detects, stop and spend nothing;
otherwise ProxyCheck.io; if ProxyCheck cannot classify it, IPQualityScore as the
last step; cache every provider response; conserve the 200/day IPQS quota.

`ChainedIpIntelligence` already implements exactly this. Confirmed line by line:

| Requirement | Implementation |
|---|---|
| Local first | `build()` calls `$this->local->inspect($ip)` unconditionally |
| Conclusive local hit spends nothing | `if (! self::inconclusive($verdict)) return $verdict;` — returns before the provider loop |
| ProxyCheck next | `IpProvider::active()` orders by `priority`; `proxycheck` is 10 |
| IPQS only if ProxyCheck could not classify | the loop `break`s as soon as the merged verdict stops being inconclusive; `ipqualityscore` is priority 20 |
| Per-provider response cache | `ip:provider:{key}:{ip}`, TTL from `ip_providers.ttl_seconds` |
| Merged verdict cache | `ip:v2:{ip}`, 1h (300s when degraded) |
| Quota not wasted | `IpProvider::available()` excludes a capped or breaker-open provider **before** the HTTP call — a spent cap costs zero requests |

No code change is required for the cascade itself.

### 4.2 Provisioning runbook

1. Create free MaxMind and IP2Location accounts; set `MAXMIND_LICENSE_KEY` and
   `IP2LOCATION_DOWNLOAD_TOKEN`.
2. Optionally set `PLAYNDGAIN_GEOIP_PATH` (defaults to `storage/app/geoip`).
   On a multi-node deploy this must be a path every node can read.
3. Run `php artisan geoip:update`. Each database downloads independently; a
   failed one fails only itself, and a file that will not open under the
   production reader is discarded rather than replacing the last good copy.
4. Schedule `geoip:update` weekly.
5. Confirm the Health page *GeoIP databases* check is green.
6. On the admin **Protection** page, paste API keys for `proxycheck` and
   `ipqualityscore` and enable both rows. Key fields are write-only
   (`encrypted` cast, never rendered back as plaintext). For IPQS this must be
   the **Proxy Detection private API key** from API Settings — an account
   password or an email/phone key is rejected.
7. Verify end to end: a known VPN address must be refused at wall render with
   the `blocked_proxy` notice.

### 4.3 Configuration changes

Three values differ from what the owner specified. All three need **guarded
migrations**, not seeder edits: `FraudRuleSeeder` and `IpProviderSeeder`
deliberately insert-only and leave existing rows alone so they can never reset
an operator's tuning. Follow the pattern in
`2026_08_19_000003_promote_vpn_proxy_ip_to_block.php` — match on the current
value so an operator who has already moved a setting is not overruled.

| Row | Column | From | To | Why |
|---|---|---|---|---|
| `ip_providers.ipqualityscore` | `daily_cap` | 1000 | **200** | Seeded at 1000 for want of a better number; the account's real tier is 200/day |
| `ip_providers.ipqualityscore` | `ttl_seconds` | 86400 | **604800** | See §4.4 |
| `fraud_rules.ip_risk_score` | `config.threshold` | 85 | **27** | Owner's decision |
| `fraud_rules.ip_risk_score` | `action` | `flag` | **`block`** | Owner's decision |

Each migration's `down()` reverses only if the current value is the one it set.

Both `ipqualityscore` changes (`daily_cap` and `ttl_seconds`) belong in **one
migration file**, and both `ip_risk_score` changes (`config.threshold` and
`action`) in another. Splitting a single row's related edits across files means a
rollback can half-revert it — a 200-cap row left on a 7-day TTL, or a threshold
of 27 left at `block` after the threshold reverts to 85.

`IpRiskScoreRule::DEFAULT_THRESHOLD` stays at 85. It is the fallback for a
missing config key, not the operating value, and the `Rule` contract is explicit
that thresholds come from `$config` so they can change without a deploy.

### 4.4 Extending the IPQS cache — the cheapest quota win available

The 200/day cap is 200 **distinct new addresses** per day; a repeat visitor
already costs nothing, because `ask()` checks `ip:provider:ipqualityscore:{ip}`
before resolving the driver.

At the seeded 24-hour TTL, the same address is re-billed every day for an answer
that did not change — a vendor fraud score moves on weekly timescales, not
hourly ones. Raising `ttl_seconds` to 7 days converts a 200/day cap into roughly
1,400 distinct addresses of standing coverage instead of 200. Same spend, ~7×
the reach.

This is a column value, not code. The merged `ip:v2:{ip}` verdict still expires
hourly, so a stale country is never served; only the paid answer is held longer,
which is precisely the split the three-layer cache was built for.

**Capacity note the owner must price.** Escalation fires on *inconclusive* local
verdicts, and a clean local answer counts as inconclusive by design (it is
exactly what a good residential proxy produces). So ordinary traffic escalates
at close to one lookup per distinct address. ProxyCheck's 1000/day free tier
absorbs the volume and IPQS sees only what ProxyCheck could not classify — the
ordering is already optimal — but at any real render volume both caps are spent
before the day ends, after which verdicts go `degraded` (5-minute TTL, retried
rather than inherited) and detection falls back to the local files alone.

**"No VPN users" at volume is a paid-feed line item.** The number that decides
the tier is expected daily *distinct* IP addresses, which nothing in the app
measures yet. §4.5 is where that number becomes visible.

### 4.5 Score distribution on the Protection page — new build

`IpRiskScoreRule`'s docblock states "The Protection page shows the
distribution." It does not. The page has a single-IP lookup tester that renders
one `risk` value; there is no distribution view anywhere.

At a threshold of 27 that absence matters, because 27 is far below the ~75 IPQS
itself treats as suspicious, and nothing currently tells the operator what share
of their own traffic sits above it.

Build:

- A histogram of observed IPQS risk scores, bucketed in tens, over a selectable
  window (24h / 7d / 30d), rendered on the Protection page.
**Where the sample must be taken, and why not the obvious place.** Two candidate
sources are both biased, and the bias runs in the direction that matters:

- `fraud_events` for `ip_risk_score` carries `score` in its context JSON, but a
  rule that *passes* writes no event. That source sees only scores **above**
  threshold.
- A column on `offer_clicks` sees only users who reached a click. A render
  refused by the gate writes no `offer_clicks` row at all, so that source sees
  only scores **below** threshold.

Either one alone produces a histogram that is empty on exactly the side the
operator opened the page to read.

**Sample at render instead.** `WallGate::context()` resolves an `IpVerdict` for
every wall impression, before the gate decides anything — it is the only point
that sees refused and served users alike. Recommended implementation:

- A small `ip_score_daily` table: `(date, bucket, count)`, `bucket` being the
  score's tens band, unique on `(date, bucket)`.
- Incremented once per render where `$verdict->riskScore !== null`. Buffer the
  increments in cache and flush on a schedule rather than upserting on every
  impression — follow whatever cadence `stats:aggregate` already runs at, and
  reuse it if that is the cleaner seam.
- No per-address storage, so no retention question and no new personal data.

Fallback if the planner rejects a new table: union the two biased sources above
and **state the seam on the page**, because a reader who does not know the
histogram is stitched from two half-populated sources will misread it.

Note for whichever path is chosen: the click path deliberately builds a
*verdict-less* `FraudContext` (`WallContext::fraudContext()`) so that an IP which
changed between render and click gets a fresh lookup. Do not undo that to make a
column easier to fill — it is a correctness property, not an oversight.

- A marker line at the configured threshold, and a stated count of "renders that
  would be refused at the current setting" for the window.

**This is the only measurement work in this plan**, and it exists solely because
the owner is running a threshold well below vendor guidance and needs to see the
damage the same day rather than infer it from publisher complaints.

### 4.6 Risk accepted, recorded here deliberately

`ip_risk_score` is a `RenderGateRule` returning notice `blocked_risk`, so a score
over threshold refuses **the entire wall render**, not a single offer. It is also
the only rule in the set with no local signal behind it — it acts purely on a
vendor's number.

At threshold 27, honest users on mobile carrier IPs, CGNAT and recycled
residential addresses will see an empty wall. This was raised with the owner
with those specifics and the setting was chosen anyway, together with §4.5 to
make the cost visible. It is one admin toggle from off and one config edit from
a higher number. Recorded so that a future reader does not mistake it for an
oversight.

---

## 5. Track 2a — Emulator signatures, server-side

Nothing in the codebase detects an emulator today. `DeviceDetector` is substring
matching over the User-Agent that resolves four device buckets, an OS version
and a browser name; it will classify BlueStacks as an ordinary Android phone.

**The cheapest layer first.** A stock Android emulator announces itself in its
User-Agent build string: `Android SDK built for x86`, `sdk_gphone`,
`generic_x86`, `vbox86`, plus the identifiable build strings of BlueStacks, Nox,
LDPlayer and MEmu.

### Rule: `emulator_device`

| | |
|---|---|
| Stage | `PreClick` |
| Implements | `RenderGateRule` — the UA is available at render, so this refuses before the wall paints |
| Action (seeded) | `block` |
| `score_weight` | 45 |
| Config | `{"signatures": [...]}` — same shape as `ua_anomaly`'s `bot_signatures`, so the list is tunable without a deploy |

Matching is case-insensitive substring, against `FraudContext::$userAgent`. A
blank UA is `pass()` — `ua_anomaly` already owns that case, and two rules
blocking on one input is one event too many for the same fact.

### The signature list is the risk, and it has no evidence behind it yet

`WallGate::protectionBlock()` loads rules `where action = Block`, so the instant
this row seeds at `block` it is live at render on **every impression**. Every
other control in this plan gains its accuracy from provisioning a vendor
database; this one gains it from a substring list written from memory. One
over-broad entry empties the wall for a whole device class, and nothing in the
plan would surface it.

Two requirements, both binding:

1. **Seed only unambiguous strings.** `sdk_gphone`, `Android SDK built for x86`,
   `vbox86`, and the explicit BlueStacks / Nox / LDPlayer / MEmu build markers.
   **Do not seed bare `generic_x86` or any similarly generic token** — it is
   plausible inside a real OEM build string, and a false positive here is
   indistinguishable from the wall being broken. Generic tokens go in the
   config, disabled, as a documented candidate list for step 2.
2. **Validate before widening.** Once real traffic exists, run each candidate
   token against `offer_clicks.user_agent` and add it only if it matches nothing
   that also converted legitimately. The rule's `config` is admin-editable
   precisely so this is a panel edit, not a deploy.

Until step 2 has run, the claim this rule can support is "catches stock
emulator configurations", not "catches every farm that did not change a
default".

**Notice key: a new, deliberately non-diagnostic one.** `docs/05 §3` permits
naming the category only where the information is asymmetric in our favour: a
VPN user does not know their VPN is the problem, and telling them converts a
churned user into a served one. That argument does not transfer. Nobody reaches
an offerwall on an emulator by accident, so there is no honest user for a
specific message to help — a named screen would only teach a farm which signal
caught it.

The existing generic key `blocked` cannot be reused: `RendersWallPages` resolves
a notice to `wall.notice.{key}.title|body`, and `blocked`'s body is offer-shaped
("Please go back and try a different one"), which is wrong for a refusal of the
whole render. `blocked_datacenter` and `blocked_risk` are the correct register
but both name the connection.

So: add **`blocked_unavailable`** to `lang/en/wall.php` and `lang/ar/wall.php`,
in the wall-shaped register of its neighbours and naming nothing — no device, no
rule, no signal. Both locales in the same change; a missing `ar` string renders
the key itself to an Arabic-speaking user.

**Cost: none.** No new request, no client code, no consent surface, no bundle
weight.

---

## 6. Track 2b — Client signals carried on the click

The strong emulator signals need JavaScript: the WebGL renderer string
(`SwiftShader`, `Android Emulator`, `llvmpipe`, `VirtualBox` all appear
verbatim), `hardwareConcurrency`, `deviceMemory`, touch support contradicting a
mobile UA, `devicePixelRatio`, and `navigator.webdriver`.

### 6.1 Three constraints this design is shaped by

1. **The no-JS invariant.** `resources/js/wall.js` opens by declaring that with
   JS off the wall renders, every offer opens, and every offer's terms are one
   navigation away — "the invariant this file may not break." Signal collection
   is an enhancement on top of markup that already works, or it is not shipped.
2. **The wall is stateless by specification** — no session, no cookie, no CSRF
   (`routes/wall.php`). Any transport that needs per-user server state is
   working against the architecture.
3. **A hard JS budget.** The wall has its own bundle because the target is a
   mid-range Android over 3G; `tests/Feature/Landing/BundleTest.php` asserts the
   emitted chunk graph. Budget for this feature: **under 1 KB gzipped**, hand-
   written. No fingerprinting library, at any size.

### 6.2 Transport — a token on the click URL

Chosen over the alternatives:

| Option | Verdict |
|---|---|
| **Token appended to the offer link's URL** | **Chosen.** No new endpoint, no server state, no extra request. The evidence arrives inside the existing `PreClick` stage, bound to the click it describes. |
| Beacon POST to a new `/wall/signals` route at render | Rejected. One extra request per render, and per-user server state on a wall that is stateless by design. Precedent exists (the consent POST) but the cost is real and the benefit is nil. |
| `fetch` then redirect, on click | Rejected outright. Latency on the money path. |

Mechanics: on load, JS collects the fixed signal set once, encodes it as a
compact base64url payload, and appends it as a single query parameter (`ds`) to
each offer link's `href`. With JS off, no parameter is appended and every link
still works — the enhancement pattern the file already uses.

### 6.3 Why an unsigned, client-controlled value is safe to act on

A farm can forge a clean token. **This is worth exactly nothing, because the
signals are incriminating-only:** they may raise suspicion, never clear it. A
forged clean token and an absent token are treated identically. No signing
scheme is needed, and none should be added — it would imply a trust the value
can never carry.

Server-side handling, mandatory:

- Hard length cap on `ds` before decoding; over-length is discarded, not parsed.
- Strict shape validation after decode; anything unexpected is discarded.
- Never `eval`'d, never interpolated, never logged raw beyond the bounded set
  stored in the `FraudEvent` context.

### 6.4 The absent-signal policy

No JS means no token — and that is a real user with JS disabled as often as it
is a farm. Therefore:

- **Emulator-positive signals → `block`.**
- **Token absent or unparseable → `score` only. Never block, never flag.**

A JS-disabled real user is unaffected. A farm that strips JS to evade this layer
is still standing in front of Track 2a and all five IP rules.

### 6.5 Rules

| Key | Stage | Action | Weight | Fires on |
|---|---|---|---|---|
| `emulator_signals` | `PreClick` | `block` | 45 | WebGL renderer matching a configured emulator/software-rasteriser list, `navigator.webdriver` true, or a mobile device reporting no touch support |
| `device_signals_absent` | `PreClick` | `score` | 5 | No `ds` parameter, or one that failed validation |

Both take their match lists from `$config`. Neither implements `RenderGateRule`:
signals do not exist at render time, only at click.

**The touch sub-signal must read `FraudContext::$device`, not the raw UA.**
`DeviceDetector`'s own docblock records that iPadOS 13+ reports as `Macintosh`
and is deliberately bucketed as Desktop, and Android desktop-mode sends a
desktop UA from a device that does have touch. Comparing "UA says mobile" to
"no touch" misfires on both. Compare the resolved `DeviceType` bucket instead,
and treat `DeviceType::Other` as no signal rather than as a mismatch.

`device_signals_absent` writes a `fraud_event` on a large share of clicks if
seeded enabled. Seed it **disabled**, with a note that an operator enables it
once they want the signal in `offer_clicks.risk_score`. A score-only rule that
triples the fraud event table on day one is a log problem wearing a rule's
clothes.

### 6.6 Consent

Reading a WebGL renderer string is more invasive than shipping an IP to a third
party, which the platform already gates. Collection is therefore gated on the
same consent state: in a consent-required country with no granted consent, the
wall does not render the collection script at all, and `emulator_signals`
degrades to `pass()` rather than firing on absence.

The gate is server-side — the Blade layer decides whether to emit the collector,
because a client-side consent check is not a consent gate.

### 6.7 What this layer does not do

No persistent identifier is derived, stored, or correlated across sessions,
apps, or publishers. The signals are read once, travel with one click, and are
retained only inside that click's `FraudEvent` context. This is emulator
detection, not device fingerprinting, and the distinction is a design constraint
rather than a description.

---

## 6A. Track 3 — The shared-link hole

Raised by the owner: a user passes our checks from a real browser on a real IP,
then takes the offer link and opens it somewhere else — another browser, a VPN,
an emulator, or fifty devices belonging to fifty other people. The checks ran
once, against someone who was clean.

This is two separate holes, and they need opposite treatments.

### 6A.1 Sharing *our* click URL — already closed

`/click/{offer}?appid=…&userid=…&sig=…` runs the full gate on **every** hit:
`WallGate::app()`, `isServable()`, consent, `isBlockedForPublisher()`, then the
whole `PreClick` pipeline, and only then the 302. A shared copy of this URL
opened over a VPN is refused exactly like a fresh one, because it *is* a fresh
one — a new `offer_clicks` row with a new `click_id`, evaluated from scratch.

Residual risk is one person farming a single `userid` across several clean
devices. `click_velocity` and `ip_velocity` (flag) and `user_daily_cap` /
`user_daily_payout_cap` (flag) bound it, and `auto_ban_threshold` ends it.

**No interstitial route is proposed, and this is deliberate.** An extra hop
before the network redirect would re-run checks that have already run
microseconds earlier, on the same request, from the same IP. It would add a
route, a token, a storage decision and a failure mode, and detect nothing the
existing gate does not already detect.

### 6A.2 Sharing the *network* tracking URL — the actual hole

After the 302 the user is on the network's domain, and the address bar holds the
network's tracking URL with our `click_id` inside it. That URL is copyable,
shareable, and it reaches the advertiser without passing through us at all.

**A gate route of ours cannot close this.** Once the redirect has happened we are
out of the request path; anyone holding that URL completes the offer with the
advertiser directly, and the network reports a conversion against a `click_id`
that was validated once, from a clean IP, by a different person. No amount of
checking before the redirect inspects the person who actually completes.

The only place the completer is visible is **the conversion postback**, and the
platform currently runs no IP inspection there. All five IP-family rules are
`PreClick`. `conversion_geo_mismatch` compares country only, and flags.

**20 of the 21 network drivers already populate `ip` on `PostbackDTO`**, so the
completer's address is available on essentially the whole network set. That is
what makes this fixable.

### 6A.3 `FraudContext::$conversionIp` — a required prerequisite

`ConversionProcessor::evaluateFraudRules()` builds its context with
`ip: $dto->ip ?? $click->ip`. A post-conversion rule reading `$context->ip`
therefore cannot tell the completer's address from a fallback copy of the
clicker's, and would silently re-inspect the click IP whenever a network sends
no address — re-blocking a click that already passed, on a signal that says
nothing about the conversion.

Add a nullable `conversionIp` to `FraudContext`, populated **only** from
`$dto->ip` and never from the click. Both rules below return `pass()` when it is
null. That is the correct inert behaviour: no address means no evidence, not
suspicion.

### 6A.4 Rules

| Key | Stage | Action | Weight | Fires on |
|---|---|---|---|---|
| `conversion_proxy_ip` | `PostConversion` | `block` | 45 | The conversion's own IP is a VPN, proxy, Tor exit, datacenter or residential proxy |
| `conversion_ip_mismatch` | `PostConversion` | `flag` | 20 | The conversion IP and the click IP belong to different ASNs |

**`conversion_proxy_ip`** is the direct answer to the shared-link attack: it puts
whoever completed the offer through the same IP gate the clicker passed. It
reuses `IpIntelligence::inspect()`, so it inherits the local-first cascade, the
caches and the daily caps — a conversion IP already seen at click costs nothing.
It resolves the proxy classes the four `PreClick` rules cover, in one rule
rather than four, because the post-conversion screen is a single yes/no about an
address rather than a set of separately-tunable render decisions.

Consent: `ConversionProcessor` already resolves `IpIntelligence` with `consented`
left at its default `false`, so in a consent-required country this degrades to
the local-only verdict rather than escalating. That is the existing documented
position (`docs/05 §4`) and is not changed here.

**`conversion_ip_mismatch`** catches what `conversion_proxy_ip` cannot: a link
shared between two ordinary residential connections, where neither address is a
proxy. If the clicker and the completer are different people, their addresses
almost never share an ASN.

It is `flag`, not `block`, and the comparison is **ASN, not the address**. A
phone walking from wifi onto a carrier changes IP legitimately and constantly,
and dual-stack IPv4/IPv6 changes it within one session. ASN survives both.
Blocking on this would refuse real mobile users at scale, which is the one thing
the agreed posture forbids. It also degrades to `pass()` when either side has no
resolvable ASN — which, until §4 is provisioned, is always.

### 6A.5 What this still does not stop

A shared tracking URL completed from a clean residential IP on the same ASN as
the clicker — a flatmate, an office, a family — passes both rules. That case is
bounded by `repeat_offer_completion` (block, for non-multi-reward offers),
`user_daily_cap` and `user_daily_payout_cap`, not eliminated by them.

The hole cannot be fully closed from our side: the network owns the tracking URL
and honours the `click_id` inside it regardless of who presents it.

---

## 7. Honest limits

- **VPN / proxy / Tor / datacenter: near-deterministic**, once §4 is provisioned
  and a feed tier matching real volume is paid for. This is the part of the goal
  that is genuinely achievable.
- **Emulators: the lazy majority, plus raised cost for the rest.** A farm that
  spoofs its WebGL renderer string and runs a stock device UA passes both 2a and
  2b. Client-side emulator detection cannot be made deterministic, and this plan
  does not claim it can.
- **Nothing here addresses publisher-side fraud** — a publisher farming their own
  wall. `publisher_spike` flags it and the withdrawal view shows reversal rate,
  account age and lifetime figures for a human to read, but there is no computed
  publisher risk score; `PublisherResource.php:86` says so in a comment. Out of
  scope by agreement, noted so it is not mistaken for covered.
- **Two rules are documented-broken and untouched here.** `docs/05 §3` records
  that `publisher_spike`'s denominator and `impossible_speed`'s elapsed-time
  measurement are both distorted by reused embedded-partner-wall click rows, and
  that the fix must land before embed traffic runs at volume. No seeded network
  carries a `wall_url`, so neither is live. Out of scope here; still owed.

---

## 8. Tests

Per `docs/05 §8`, every rule gets one input that triggers and one that does not.
Beyond that:

**Track 1**
- `ip_risk_score` at `config.threshold = 27` triggers on a verdict scoring 27 and
  passes at 26 — the boundary, in both directions.
- A conclusive local verdict makes zero provider calls (assert the HTTP fake
  recorded nothing).
- A ProxyCheck answer that resolves the verdict stops the loop before IPQS is
  asked.
- A spent IPQS daily cap opens no socket and marks the verdict `degraded`.
- The provider cache is honoured across a merged-verdict expiry — an expired
  `ip:v2:` with a warm `ip:provider:` costs no HTTP call.
- Each migration is idempotent and does not overrule an operator who already
  moved the value.

**Track 2a**
- Each seeded signature string blocks; an ordinary Android UA does not.
- A blank UA passes (owned by `ua_anomaly`).
- The refusal renders `blocked_unavailable` and the response body contains no
  rule key — the existing `publicReason()` assertion extended to this path.
- `wall.notice.blocked_unavailable.title` and `.body` resolve in both `en` and
  `ar` — neither renders the raw key.

**Track 2b**
- A token carrying a `SwiftShader` renderer blocks; one from a real device does
  not.
- An absent token never blocks and never flags.
- An over-length `ds` is discarded without being decoded.
- A malformed / non-base64 / wrong-shape token is discarded and treated as
  absent.
- A forged clean token does not clear a rule that another signal triggered.
- In a consent-required country without consent, the collector is not emitted
  and the rule passes.
- **The no-JS invariant holds**: the wall renders and every offer link works with
  the collector absent.

**Track 3**
- `conversion_proxy_ip` blocks when `$dto->ip` is a proxy, and passes when
  `$dto->ip` is null even though `$click->ip` is a proxy — the fallback must not
  be mistaken for evidence.
- `conversion_ip_mismatch` triggers on two different ASNs, passes on the same
  ASN, and passes when either ASN is null.
- A conversion from a different IP on the same ASN as the click does not trigger
  either rule.
- `FraudContext::with()` round-trips `conversionIp` (it is set from
  `get_object_vars`, so a missing constructor argument is a silent null).

**Seeder**
- `tests/Feature/SeedersTest.php` asserts exactly 10 pre-click and 10
  post-conversion rules. Three pre-click rules are added (`emulator_device`,
  `emulator_signals`, `device_signals_absent`) and two post-conversion
  (`conversion_proxy_ip`, `conversion_ip_mismatch`), so that assertion becomes
  **13 pre-click / 12 post-conversion**. Update it in the same change that adds
  the rules, not afterwards.

---

## 9. Sequencing

1. **Owner reads the Health page.** Everything below is conditional on what it
   says about production.
2. **Track 1 provisioning** (§4.2) — no code. Highest value in the plan; it is
   what actually delivers "no VPN or proxy users".
3. **Track 1 migrations and the distribution view together** (§4.3 + §4.5) —
   **one change, not two.** The threshold drop to 27 is the reason the view
   exists; shipping the threshold first means running a setting well below
   vendor guidance with no way to see what it costs. Sequenced apart, this step
   will be deferred.
4. **Track 3** (§6A) — `FraudContext::$conversionIp`, then the two
   post-conversion rules. Placed here, above Track 2, because it closes a hole
   that is open today and needs no client code; and because
   `conversion_proxy_ip` depends on §4 being provisioned to do anything at all.
5. **Track 2a** (§5) — one rule, one seeded row, no client code. Seed the
   conservative signature list only; the widening step waits for real traffic.
6. **Track 2b** (§6) — the only part with a client surface, a consent
   interaction and a bundle-budget constraint. Last on purpose.

Steps 2–4 are the ones that change outcomes this week. Step 6 is a week of work
for the smaller share of the benefit, and should not be started before 2–4 are
verified in production.

---

## 10. Open decisions for the planner

1. **Feed tier.** Expected daily distinct IP addresses is unknown and unmeasured.
   Free tiers (1000 ProxyCheck / 200 IPQS) will not cover meaningful volume.
   §4.5's distribution view is the first thing that will make the real number
   visible; the tier decision should wait for it rather than precede it.
2. **Histogram data source.** §4.5 offers two; option 1 (a nullable column on
   `offer_clicks`) is recommended.
3. **The `ds` parameter's exact encoding and field set.** Specified in shape
   here, not byte for byte; the 1 KB gzipped budget is the binding constraint.
