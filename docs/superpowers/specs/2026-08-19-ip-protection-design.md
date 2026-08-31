# IP protection: provider chain, render gate, and a control page

**Date:** 2026-08-19
**Status:** Approved design, not yet implemented
**Touches:** `app/Fraud/`, `app/Fraud/Rules/`, `app/Services/Wall/WallGate.php`, `app/Http/Controllers/Wall/WallController.php`, `app/Filament/Admin/Pages/`, `app/Providers/FraudServiceProvider.php`, `database/seeders/`, `lang/en/wall.php`, `lang/ar/wall.php`, two migrations

---

## 1. What this adds

Three things, in dependency order:

1. **The local databases are read properly.** `MaxMindIpIntelligence` collapses an eleven-field IP2Proxy PX11 record to a single boolean. Proxy type, usage type, threat class and last-seen age are already on disk and already paid for, and none of them reach a rule.
2. **Third-party providers become pluggable.** ProxyCheck.io and IPQualityScore ship as adapters behind the `IpIntelligence` interface that already exists, escalated to when the local databases are inconclusive, configured from the admin panel rather than from a deploy.
3. **Protection blocks at wall render, not only at click.** A user whose IP fails the enabled protection sees a screen telling them why, instead of a wall full of offers none of which will pay.

And one surface to operate all of it: an admin **Protection** page.

### What already exists

This is not a greenfield subsystem. The seam is in place and every consumer already depends on the abstraction:

| Piece | State |
|---|---|
| `IpIntelligence` interface, `IpVerdict` value object, shared `ip:{ip}` cache | Built |
| `MaxMindIpIntelligence` (GeoLite2-Country, GeoLite2-ASN, IP2Proxy PX11) | Built, databases provisioned, `2026.8.16` |
| `geoip:update`, weekly, with staging directory and reader verification | Built |
| `NullIpIntelligence`, `LocalIpIntelligence` (dev-only decorator) | Built |
| `datacenter_ip` (block, 40), `vpn_proxy_ip` (flag, 20) rules | Built |
| `FraudPipeline`, `fraud_rules` table, admin Fraud Rules tuning page | Built |

The single binding lives in `FraudServiceProvider::register()`. Everything downstream — `WallGate`, `DatacenterIpRule`, `VpnProxyIpRule` — is constructor-injected with the interface. **Adding a provider chain changes one binding and no call site.** That property is the reason this design is small, and it must survive implementation.

---

## 2. Decisions taken, and why

| Decision | Chosen | Rejected alternative |
|---|---|---|
| Where providers are called | On cache miss, wherever `inspect()` is first reached — which is wall render | Click-only escalation via a decorator. Rejected: the render call warms the cache the click needs, and a second escalation path is a second thing to keep correct |
| Cache miss behaviour | Blocking, hard timeout, fail-open to the local verdict | Async enrichment. Rejected by the operator: the first click from a new IP would be judged on local data alone |
| Provider verdict caching | Per IP, versioned key, per-provider TTL | One global TTL. Rejected: local country needs one hour, a paid proxy verdict does not |
| Render-gate policy source | The `fraud_rules` rows that already exist | A second set of toggles on the Protection page. Rejected: two places configuring one signal is how they drift |
| Block screen | Coarse, actionable reason for IP-family blocks only | `publicReason()`'s blanket `unavailable`. Narrowed deliberately — see §6 |
| Day-1 providers | ProxyCheck.io and IPQualityScore | One adapter. Rejected: a single adapter always ends up shaped like its own API |
| Consumer VPN | `block` at render, with an actionable screen | Keeping the seeded `flag`. Reversed on the operator's call — see §7 |

---

## 3. The chain

```
IpIntelligence (interface, unchanged)
│
└── ChainedIpIntelligence                      ← the new binding
    ├── MaxMindIpIntelligence                  ← local, always runs, free
    └── escalation, ordered by ip_providers.priority
        ├── ProxyCheckProvider
        └── IpQualityScoreProvider
```

### Escalation condition

A paid lookup is spent only when the local databases cannot answer. Concretely, the chain escalates when **any** of these holds:

- `country === null` — the address is unplaced, and an unplaced address is fail-closed downstream, so it costs us inventory rather than money
- `isProxy === false && isDatacenter === false` and the address is routable — the local answer is "clean", which is exactly the answer a good residential proxy produces
- `proxyType === 'PUB'` — the vaguest PX11 class; a paid provider distinguishes a compromised host from a shared exit

It does **not** escalate on a conclusive local hit. If PX11 already says `TOR`, a second opinion costs money and changes nothing.

### Merge rule

The local verdict is the base. A paid verdict overwrites a field only where the base left it `null` or `false`, and sets `source` to the provider that answered. A provider is never allowed to *clear* a local positive: if PX11 says datacenter and IPQS says clean, the verdict stays datacenter. Fraud signals are additive, and a paid provider disagreeing with a hosting-ASN match is a provider being wrong.

`riskScore` is the exception — it has no local value at all, since the LITE BIN has no `FRAUD_SCORE`, so the first provider to return one wins and later providers do not overwrite it.

### Failure and cost controls

Three of them, all per provider row, all visible on the page:

- **Timeout.** `timeout_ms`, default 600. Exceeded means fail-open to whatever the chain has so far.
- **Circuit breaker.** `circuit_failures` consecutive failures (default 5) opens the breaker for `circuit_cooldown_minutes` (default 10). While open, the provider is skipped entirely — no socket, no wait. The page shows the state and the reason.
- **Daily cap.** `daily_cap` requests per UTC day, counted in Redis. At the cap the provider is skipped and the page goes amber. The cap is a spend ceiling, not a target.

**Degraded verdicts cache for 5 minutes, not for the provider TTL.** This is the subtle one. If a provider times out and we cache the local-only verdict under the normal 24-hour key, that IP is never asked again for a day — one transient timeout blinds the paid layer on that address for the whole TTL. A verdict produced while a provider was unavailable is marked degraded and given a short TTL so the next request retries.

### Two cache layers, because one TTL cannot serve both halves

A single entry per IP holding the merged verdict cannot satisfy the two TTL requirements that already exist. Local country must expire in an hour — mobile carrier IPs rotate, and a day of caching misattributes them, which is why `MaxMindIpIntelligence::TTL` is what it is. A paid verdict must live far longer — proxy status and ASN change on weekly timescales, and a one-hour TTL re-bills every address every hour. One entry with one TTL breaks whichever half it does not serve.

So there are two layers:

| Layer | Key | TTL | Holds |
|---|---|---|---|
| Merged verdict | `ip:v2:{ip}` | 3600 (300 when degraded) | What `inspect()` returns |
| Raw provider verdict | `ip_provider:{key}:{ip}` | `ip_providers.ttl_seconds`, default 86400 | One provider's answer, unmerged |

On a merged-verdict miss the chain re-reads the local databases — cheap, a memory-mapped file — and re-merges from the provider layer. **An HTTP call is spent only when the provider layer also misses.** Hourly re-merging costs nothing; hourly re-billing was the thing to avoid, and it is avoided here rather than in the merged TTL.

### Escalation never precedes the consent gate

`inspect()` runs at step 5. Consent is checked at step 7. Today that gap is harmless, because step 5 reads a local file. Under this design it becomes an HTTP request shipping the end user's IP address to a third party — for a user who has not consented, and possibly for one who has declined.

That is precisely what the gate exists to prevent. `ClickController` already states the principle: storing IP, user agent and publisher user ID for a user who has not consented "is exactly the processing the gate exists to prevent", and it is the one refusal in the wall that writes nothing at all.

The chain's own ordering resolves it. The local lookup produces the country before any escalation decision is made, so:

1. Local databases run. Country in hand, no third party involved.
2. If that country is in `consent_required_countries`, the chain stops. The verdict is local-only, marked `degraded`, cached for five minutes.
3. Otherwise escalation proceeds as described above.

The chain cannot check the `Consent` row itself — `inspect()` receives an IP and nothing else, and it cannot be given an app and an end user without every rule that calls it having to supply them. So the interface gains one optional parameter:

```php
public function inspect(string $ip, bool $consented = false): IpVerdict;
```

`Null`, `Local` and `MaxMind` ignore it. `ChainedIpIntelligence` treats it as permission to escalate in a consent-required country. The default is `false`, so **every existing call site is safe by omission** — a rule that has not been taught about consent cannot leak an IP.

`WallGate::context()` calls `inspect($ip)` plainly; the country comes back, consent is resolved against it, and a caller holding a granted consent calls `inspect($ip, consented: true)` to escalate. Outside a consent country the second call is a cache hit and costs nothing.

An unplaced address (`country === null`) **does** escalate. It is the case escalation is most valuable for — an unplaced user is served nothing but worldwide inventory — and it matches the position the platform already takes: `WallGate::consentRequired(null)` returns `false`, so an address the databases cannot place is already not treated as consent-required anywhere else. Taking a stricter line here and a looser one two methods away would be incoherent, not safer.

**The render gate follows the same rule.** A user held at step 5 by this check is not blocked and no `fraud_event` is written for them — an event row carries the IP and the end user ID, and writing one pre-consent is the same processing by another name. They reach the consent screen at step 7, which is where a user with no consent decision belongs. Protection resumes the moment they answer.

The cost is that an unconsented EU user gets local-only protection for one screen. That is the correct trade: the consent gate is a legal boundary and the protection layer is a commercial one.

### Cache key

The merged key versions to `ip:v2:{ip}`.

`IpVerdict::remember()` guards deserialisation with `instanceof self`, and its docblock explains that a stale entry deserialises to `__PHP_Incomplete_Class`. That is true when the *class* is missing. It is not true for a *shape* change to a class that still exists: an entry written before the new properties existed deserialises into a perfectly valid `IpVerdict` whose new typed properties are uninitialised, and the first read of one throws an `Error` on every wall request from that address until the TTL expires. Versioning the key sidesteps it. The docblock gets corrected in the same change.

---

## 4. `IpVerdict`, extended

Verified live against the provisioned `IP2PROXY-LITE-PX11.BIN` (`2026.8.16`): `proxyType`, `usageType`, `threat`, `lastSeen`, `isp`, `domain`, `asn`, `as` all populate for listed addresses. `fraudScore` does **not** — it returns `"This parameter is unavailable in selected .BIN data file"`, since risk scoring needs PX12 or the paid API. That field is what the third-party providers are for.

Unlisted addresses return `"-"` for every field. `"-"` maps to `null`, never to a string.

| New field | Type | Source | Notes |
|---|---|---|---|
| `proxyType` | `?ProxyType` (enum) | PX11 `PROXY_TYPE`, providers | `VPN`, `TOR`, `DCH`, `PUB`, `WEB`, `SES`, `RES`, `CPN` |
| `usageType` | `?string` | PX11 `USAGE_TYPE` | `DCH`, `CDN`, `ISP`, `MOB`, `COM`, `ORG`, `EDU`, `GOV`, `LIB`, `SES`, `RSV` |
| `threat` | `?string` | PX11 `THREAT` | `SPAM`, `BOTNET`, `SCANNER` |
| `riskScore` | `?int` | Providers only | 0–100. Null means nobody scored it, which is not the same as zero |
| `source` | `string` | Chain | `local`, `proxycheck`, `ipqualityscore`. Recorded on every fraud event |
| `degraded` | `bool` | Chain | A provider that should have run did not. Drives the short cache TTL |

`ProxyType` becomes an enum in `app/Enums/` — the values are a closed set, they are compared in three rules, and a string comparison against `'RES'` in three files is how a typo becomes a rule that silently never fires.

---

## 5. The render gate

`WallGate::context()` already calls `inspect()` at step 5 to resolve the country. The verdict is therefore already in hand at render, and gating on it costs one array read.

`WallContext` gains a readonly `IpVerdict $verdict`. `country` stays as its own field because the test-mode `?country=XX` override can replace it while the rest of the verdict stays real.

`WallController`, **after** the consent gate rather than before it — a user with no consent decision belongs on the consent screen, and a protection block written ahead of it would record their IP and end user ID in a `fraud_event` before they had answered:

```php
$context = $this->gate->context($request, $app);   // inspect() already ran here

// ... consent gate, unchanged ...
// ... banned end user, unchanged ...

if ($blocked = $this->gate->protectionBlock($context)) {
    return $this->notice("blocked_{$blocked}", $context, Response::HTTP_FORBIDDEN);
}
```

The gate therefore sits between steps 8 and 9 — the last refusal before the offers table is touched, which is also the correct place for the most expensive of them.

`notice()` is already "the one screen for every dead end — same markup, different sentence". A new lang key is the whole screen.

### Policy comes from `fraud_rules`, not from new config

A rule whose only input is the `IpVerdict` implements a marker interface, `RenderGateRule`. `protectionBlock()` walks the enabled `fraud_rules` rows whose key resolves to such a rule and whose `action` is `block`, evaluates them against the verdict, and returns the first category that fires.

This means **no new configuration exists**. The Fraud Rules page stays the single tuning instrument, and a rule promoted from `flag` to `block` starts gating render in the same click.

Render-gate-eligible rules, and the notice each maps to:

| Rule | Notice key |
|---|---|
| `datacenter_ip` | `blocked_datacenter` |
| `tor_ip`, `residential_proxy_ip`, `vpn_proxy_ip` | `blocked_proxy` |
| `ip_risk_score` | `blocked_risk` |

The mapping lives on the marker interface as `RenderGateRule::noticeKey(RuleResult $result): ?string`, so a rule carries its own screen the way it already carries its own key and stage. Several rules sharing one key is intentional: the screen is a category, not a verdict, and §6 is why.

**It takes the rule's own result, and it may return null.** Null means the rule fired but this particular trigger does not warrant refusing a render — the `fraud_event` is still written, only the refusal is withheld. The rule decides rather than the gate, because only the rule knows *how* it matched.

`vpn_proxy_ip` is the reason. It narrows to the explicit consumer classes, but keeps a fallback for a proxy the databases flag without classifying — the behaviour it had before the classes existed, and the catch that survives a stale or terse database. That fallback is a fair click-time signal and a bad render-time one: `ProxyCheckProvider` sets `isProxy` from the vendor's own field independently of `type`, and `Business` is deliberately left unmapped, so a `type: Business, proxy: yes` response arrives as `isProxy: true` with a null class. Gating on that would refuse the wall to an office worker on a corporate ISP — undoing §7's Business fix by a second route — and would show someone whose router was compromised a screen telling them to switch off a VPN they do not have. So `vpn_proxy_ip` returns null for an unclassified match and its key for an explicit one.

Everything else stays click-time. Running `click_velocity` or `ip_velocity` at render would count tab opens as clicks and write a `fraud_event` per impression, and `docs/03` is explicit that steps 1–8 are cheap and must stay cheap.

### Consequence to expect

`datacenter_ip` is seeded `block`. After this change it blocks at render rather than at click. `vpn_proxy_ip` is promoted from `flag` to `block` (§7) and starts refusing render to every VPN user. Both are the intent, and both are called out here so neither is discovered in production. The VPN change is the larger one by traffic volume — it is the only rule here that regularly fires on real people.

### Fraud events at render

A render block writes one `fraud_event`, because "nothing blocks silently" is the first design principle in `docs/05`. The volume is acceptable precisely because it is blocked traffic: an address that fails the gate is served the notice and, being cached, costs one event per TTL rather than one per impression.

One exception, from §3: a user in a consent-required country with no granted consent is neither escalated, nor gated, nor recorded. They go to the consent screen.

The click path is unchanged. `FraudPipeline` at `PreClick` runs as it does today; its IP rules call `inspect()`, which is a cache hit for an address that just rendered a wall. If the IP changed between render and click — a network switch, a VPN toggled on after passing the gate — the lookup misses and the new address is evaluated fresh. That is the correct behaviour and it needs no special handling.

---

## 6. The block screen, and a deliberate exception

`Decision::publicReason()` returns `'unavailable'` on purpose: "never leak the rule that caught them — it teaches a fraud farm exactly what to change." That principle stands for every rule except this family, and the asymmetry is reasoned, not convenient.

**IP-family blocks show a coarse, actionable reason.** A fraud farm running through a VPN already knows it is running through a VPN; telling them so teaches them nothing. A legitimate privacy-conscious user does *not* know that their VPN is why the wall is empty, and telling them converts a churned publisher into a working one. The information is asymmetric in our favour.

**Every other rule keeps `unavailable`.** "Click velocity exceeded" teaches a farm to slow down. There is no version of that sentence worth showing.

Rules for the copy:

- The category, never the rule key. "A VPN or proxy", not `vpn_proxy_ip`.
- Never the provider, the ASN, the organisation, or the risk score.
- One sentence of instruction where instruction exists ("disable it and reload"), nothing where it does not (a datacenter IP has no user-side fix).

New keys in `lang/en/wall.php` and `lang/ar/wall.php` under `notice`: `blocked_proxy`, `blocked_datacenter`, `blocked_risk`.

---

## 7. New rules

| Key | Fires on | Default action | Weight | Config |
|---|---|---|---|---|
| `tor_ip` | `proxyType === TOR` | `block` | 45 | — |
| `residential_proxy_ip` | `proxyType === RES` | `block` | 40 | — |
| `ip_risk_score` | `riskScore >= threshold` | `flag` | 25 | `{threshold: 85}` |

### `vpn_proxy_ip` is promoted to `block`

`vpn_proxy_ip` narrows to consumer VPN — `proxyType` in `VPN`, `WEB`, `PUB`, `SES`, `CPN` — and its action changes from `flag` to **`block`** at weight 40, matching the other IP-family blocks. It therefore gates wall render like the rest of them, and a user on a VPN sees the `blocked_proxy` screen telling them to disable it and reload.

This reverses a default the platform currently argues for. The original reasoning — a consumer VPN is a privacy-conscious real person a meaningful fraction of the time, and a false positive churns a publisher — is not wrong; it is outweighed. Blocking at render is not the same refusal as blocking at click: the user is told exactly what to do, the fix takes them one toggle, and they come back and convert. A flagged VPN conversion, by contrast, is paid out and then charged back. The actionable screen from §6 is what makes the promotion affordable, and it did not exist when the `flag` default was set.

**The seeder will not do this on an existing database.** `FraudRuleSeeder` "inserts missing rules and leaves existing rows alone, so it never resets a threshold an operator tuned". `vpn_proxy_ip` already exists as `flag`. A migration flips it explicitly:

```php
FraudRule::query()
    ->where('key', 'vpn_proxy_ip')
    ->where('action', FraudAction::Flag)   // only if untouched since seeding
    ->update(['action' => FraudAction::Block, 'score_weight' => 40]);
```

Guarded on the current value so an operator who already tuned it keeps their setting.

**Four places state the old rationale and are rewritten in the same change**, or the code documents a behaviour it no longer has:

| Where | Says |
|---|---|
| `VpnProxyIpRule` class docblock | "Flags rather than blocks... a false positive costs more than a false negative this early" |
| `DatacenterIpRule` class docblock | "This blocks while `vpn_proxy_ip` only flags, and the asymmetry is the point" |
| `docs/05-fraud-engine.md` §3 table and its "On `datacenter_ip` being `block` while `vpn_proxy_ip` is `flag`" subsection | The whole subsection goes |
| `FraudRuleSeeder` `vpn_proxy_ip` description | "so this flags rather than blocks" |

**Watch the false-positive rate after deploy.** The Fraud Rules page already shows 24-hour and 7-day fire counts, and this rule firing on a large share of traffic is the signal that the promotion cost more than it saved. Reverting is one dropdown.

`datacenter_ip` gains `proxyType === DCH` as a second trigger alongside its existing hosting-ASN match. The two disagree often: the ASN list is curated by hand and covers the large providers, while PX11 flags a datacenter address whose ASN nobody thought to add. Neither subsumes the other, and the rule already reports which condition matched through its `matched` context field — that gains a third value, `proxy_type`.

**Why `residential_proxy_ip` blocks while `vpn_proxy_ip` flags.** Today they are the same rule at weight 20. A residential proxy is not a privacy tool; it is rented access to somebody else's home connection, and it is the standard transport for install farms precisely because it defeats both the hosting-ASN list and a country check. A consumer VPN is a real person a meaningful fraction of the time. Charging them the same score is the current design's largest gap.

`ip_risk_score` starts at `flag`. It depends entirely on a third-party score, and a rule that blocks on a number nobody has calibrated yet is a rule that blocks revenue. Promote it once the Protection page shows a fortnight of distribution.

All four register in `FraudServiceProvider::RULES` and seed in `FraudRuleSeeder::RULES`, in the same order — the two lists are a diffable pair on purpose.

---

## 8. `ip_providers`

A table rather than settings rows: these are records with per-row state (counters, breaker, last error), and a key/value store models that badly.

| Column | Type | Notes |
|---|---|---|
| `key` | string, unique | `proxycheck`, `ipqualityscore` |
| `name` | string | Display only |
| `enabled` | bool | Default false |
| `priority` | unsigned tinyint | Ascending; first conclusive answer wins |
| `api_key` | text, `encrypted` cast | Never rendered back into the form as plaintext |
| `timeout_ms` | unsigned smallint | Default 600 |
| `daily_cap` | unsigned int, nullable | Null means uncapped, which is a choice an operator has to make deliberately |
| `ttl_seconds` | unsigned int | Default 86400 |
| `fail_open` | bool | Default true |
| `circuit_failures` / `circuit_cooldown_minutes` | unsigned tinyint | Defaults 5 / 10 |
| `circuit_opened_at` | timestamp, nullable | Null means closed |
| `last_error` / `last_error_at` | text / timestamp, nullable | Surfaced on the page |

Daily counters live in Redis (`ip_provider:{key}:{Y-m-d}`), not in a column. They are written on every escalated lookup and a table write on the click path is exactly the cost this whole design avoids.

Rows are **seeded**, and the resource sets `canCreate(): false` — the same reasoning `FraudRuleResource` already documents: a row whose key has no class behind it does nothing, silently, which is worse than not having it.

`AuditLog::record()` on every change, matching how the Fraud Rules and Settings pages already behave. An API key change records that it changed, never the value.

---

## 9. The Protection page

`app/Filament/Admin/Pages/Protection.php`, navigation group **Risk**, sorted above Fraud Rules. Four sections.

**9.1 Providers.** One row per provider: enabled, priority, API key, timeout, daily cap, TTL, fail-open. Alongside each, live state — requests today against cap, errors today, breaker state and cooldown remaining, last error and when. A provider that is enabled, configured and quiet looks different at a glance from one that is enabled and failing, which is the only reason this section is not just a form.

**9.2 Local databases.** Per file — `GeoLite2-Country.mmdb`, `GeoLite2-ASN.mmdb`, `IP2PROXY-LITE-PX11.BIN` — present, size, database version, age. Plus an **Update now** action dispatching `geoip:update`. Today this is one green-or-red line on the Health page with no action attached; the Health check stays where it is, because Health answers "is anything broken" and must keep answering it in one place.

**9.3 Live IP test.** An IP field (with a "use my IP" fill), a Test action, and a result table: one row per enabled provider plus the local databases, showing country, ASN, organisation, proxy type, usage type, threat, risk score, response time, and error if any. Below it, which rules would fire and what the render gate would decide.

The test calls providers **directly and uncached**, and **does not write** the shared `ip:v2:` cache. Two reasons: a test must show what the provider says right now rather than what it said an hour ago, and an operator testing a suspicious address must not thereby install a verdict that then governs real traffic.

This section is how a new API key is verified without waiting for traffic, and it is the fastest way to answer "why was this user blocked".

**9.4 IP rules.** The render-gate-eligible rules inline — action, weight, enabled, whether each currently gates a render, and 24-hour and 7-day fire counts. **Read-only**, with a link to the Fraud Rules resource for editing.

Editing here was in the original design and was dropped deliberately during implementation. Two editable surfaces over the same rows means two write paths and two audit trails, and `FraudRuleResource` already does that job with an audit entry on every toggle. What this section is actually for is the counts: `FraudRuleResource`'s own rationale is that "a rule that never fires is dead weight; a rule firing on 40% of traffic is miscalibrated", and those numbers are what an operator needs in view while reading provider health and testing an address. Without them the section is a read-only copy of another page and does not earn its place; with them it answers "and what would we do about it" beside the three questions above it. The count query lives once, on `FraudRuleResource`, and is called from here.

---

## 10. Testing

Feature tests, following the existing `tests/Feature/Fraud/` and `tests/Feature/Wall/` patterns.

| Test | Asserts |
|---|---|
| Chain does not escalate on a conclusive local verdict | Zero HTTP calls when PX11 returns `TOR` |
| Chain escalates on `country === null` and on a clean local verdict | Provider called once, verdict merged |
| Provider never clears a local positive | PX11 datacenter + provider clean stays `isDatacenter: true` |
| Timeout fails open | Local verdict returned, `degraded: true`, 5-minute TTL |
| Breaker opens after N failures and skips without a socket | Call count stops incrementing |
| Daily cap skips the provider | Local verdict returned, page state amber |
| Render gate blocks and shows the right notice | 403, `blocked_proxy` copy, one `fraud_event` written |
| Render gate ignores non-`RenderGateRule` rules | `click_velocity` at `block` does not gate render |
| Click after a blocked render | Cache hit, no second provider call |
| Cache key version | An entry under `ip:{ip}` is ignored rather than deserialised |
| Merged-verdict expiry does not re-bill | Merged key expired, provider key warm: verdict rebuilt with zero HTTP calls |
| Unconsented user in a consent country | No provider call, no `fraud_event`, no render block, consent screen shown |
| Same user after consent granted | Escalation proceeds, render gate applies |
| Each new rule | Fires on its `proxyType`, passes on the others |
| VPN render block | `proxyType: VPN` gets 403 and the `blocked_proxy` copy, not the offer list |
| Promotion migration | Flips a `flag` row to `block`; leaves a row an operator already set to `score` alone |
| Test tool | Does not write the shared cache key |

Provider adapters are tested against recorded responses via `Http::fake()`, not against the live APIs.

---

## 11. Non-goals for v1

Named so they are decisions rather than omissions:

- **No per-app or per-publisher provider override.** Global configuration only. A publisher-level "block VPN traffic" toggle is a plausible v2, and it wants its own thought about who pays for the lookups.
- **No Tor bulk exit list ingest.** PX11 carries a `TOR` proxy type and covers most of it. A spot check found `185.220.101.1` — a dedicated Tor exit range — unlisted in the current LITE database, so a fourth `geoip:update` source pulling the Tor Project's bulk list is worth doing. It is not worth doing before the chain exists.
- **No async enrichment.** Blocking with a timeout was chosen deliberately. If provider latency turns out to hurt render, the async path is a decorator and this design does not preclude it.
- **No client-side signals.** Timezone against IP country, WebRTC leakage, headless-browser detection. All real, all a new data path from the wall page into `FraudContext`, all their own spec.
- **No change to `publicReason()` for non-IP rules.** The narrowing in §6 is exactly as wide as it is written.
