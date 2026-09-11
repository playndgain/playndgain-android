# 05 — Fraud Engine

Admin-tunable rules. Two evaluation points. Every decision logged with a reason.

---

## 1. Design Principles

**Rules live in the database, not in code.** Thresholds change weekly in response to what fraud farms are doing. A threshold change must not require a deploy.

**Nothing blocks silently.** Every block, flag, and score writes a `fraud_event` with the rule key and the inputs the rule saw. When a publisher asks "why didn't my user get credited", the answer is one query away. A fraud system you cannot explain is a fraud system you will eventually turn off.

**Blocking is a business decision, not an engineering one.** Each rule has an `action` — `block`, `flag`, or `score` — set by the admin. The same rule can be advisory on Monday and blocking on Friday. Engineering supplies the signal; the operator decides what it costs.

**False positives cost more than false negatives, early on.** A blocked legitimate user churns the publisher. A fraudulent conversion costs one payout. Start most rules at `flag` and promote to `block` once the data supports it.

---

## 2. Pipeline

```php
interface FraudRule
{
    public function key(): string;
    public function stage(): Stage;                    // PreClick | PostConversion
    public function evaluate(FraudContext $ctx, array $config): RuleResult;
}

final readonly class RuleResult
{
    public function __construct(
        public bool $triggered,
        public string $reason = '',
        public array $context = [],
    ) {}
}

final readonly class Decision
{
    public function __construct(
        public bool $blocked,
        public int $score,             // 0–100
        public ?string $reason,        // internal rule key
        public array $triggered,       // all rules that fired
    ) {}

    public function publicReason(): string
    {
        // Never leak the rule that caught them — it teaches fraudsters what to change.
        return 'unavailable';
    }
}
```

```php
class FraudPipeline
{
    public function evaluate(Stage $stage, FraudContext $ctx): Decision
    {
        $rules   = FraudRule::query()->where('stage', $stage)->where('enabled', true)->get();
        $score   = 0;
        $blocked = false;
        $reason  = null;
        $fired   = [];

        foreach ($rules as $rule) {
            $result = $this->resolve($rule->key)->evaluate($ctx, $rule->config);

            if (! $result->triggered) {
                continue;
            }

            $fired[] = $rule->key;
            $score  += $rule->score_weight;

            FraudEvent::record($rule, $stage, $ctx, $result);

            if ($rule->action === 'block' && ! $blocked) {
                $blocked = true;
                $reason  = $rule->key;
            }
        }

        return new Decision($blocked, min($score, 100), $reason, $fired);
    }
}
```

**Every enabled rule runs**, even after a block is decided. Stopping at the first block loses the full signal picture, and the full picture is what tuning depends on.

---

## 3. Day-1 Rules

Seeded by `FraudRuleSeeder`. `action` and `config` are editable in the admin panel.

### Pre-click

| Key | What it catches | Default config | Default action |
|---|---|---|---|
| `banned_end_user` | User banned by the publisher or auto-banned by us | — | `block` |
| `datacenter_ip` | Click from AWS/GCP/Azure/OVH/Hetzner, or IP2Proxy's own `DCH` proxy-type class where the curated ASN list has no entry | ASN list | `block` |
| `vpn_proxy_ip` | Consumer VPN or unclassified proxy exit node | `{provider: "ip2proxy_lite"}` | `block` |
| `tor_ip` | Tor exit node | — | `block` |
| `residential_proxy_ip` | Rented access to somebody else's home connection — the standard transport for an install farm | — | `block` |
| `ip_risk_score` | Third-party risk score at or above the threshold | `{threshold: 85}` | `flag` |
| `geo_mismatch` | Resolved country not in the offer's targeting | — | `block` |
| `click_velocity` | Too many clicks from one user in a window | `{max: 20, window_minutes: 5}` | `flag` |
| `ip_velocity` | Too many distinct users from one IP | `{max_users: 10, window_hours: 24}` | `flag` |
| `ua_anomaly` | Missing, malformed, or known-bot user agent | bot signature list | `block` |

### Post-conversion

| Key | What it catches | Default config | Default action |
|---|---|---|---|
| `duplicate_conversion` | Same `network_txid` — handled by the DB constraint, this rule only records it | — | `block` |
| `repeat_offer_completion` | Same user completing a non-multi-reward offer twice | — | `block` |
| `impossible_speed` | Conversion arriving implausibly fast after the click | `{min_seconds: 15}` | `flag` |
| `no_click_match` | Conversion with no resolvable click | — | `flag` |
| `conversion_geo_mismatch` | Conversion IP country ≠ click IP country | — | `flag` |
| `app_daily_cap` | App exceeded its configured daily conversion cap | from `apps.daily_conversion_cap` | `block` |
| `user_daily_cap` | One end user over the daily conversion limit | `{max: 25}` | `flag` |
| `user_daily_payout_cap` | One end user over a daily USD ceiling | `{max_usd: 50}` | `flag` |
| `publisher_spike` | Publisher's conversion rate far above their own 7-day baseline | `{multiplier: 5, min_conversions: 50}` | `flag` |
| `auto_ban_threshold` | End user accumulated too many blocks | `{blocks: 5, window_hours: 24}` | `block` + ban |

### On `impossible_speed`

15 seconds is deliberately low. Some legitimate offers — a page visit, an email submit — genuinely convert in twenty seconds. Set it high and you block real users on your best-converting offers. This rule is `flag`, and the operator raises it per offer type once they see their own data.

### On embedded partner walls and the click-shaped rules

An embedded partner wall (docs/03 §2) has no offer of ours, so opening the tab writes an `offer_clicks` row with a null `offer_id`, and that row is reused on every later open by the same user in the same mode. Two post-conversion rules read a click as though it were a fresh one, and neither is true of an embed row.

`publisher_spike` divides conversions by raw `offer_clicks`. The embed denominator is one row per user forever while the numerator grows per conversion, so for an embed-heavy publisher the computed rate can pass 1.0 and keep climbing — flagging honest conversions into the review queue. The same rows also reach `daily_stats.clicks` through `stats:aggregate`, which filters `is_test` but not `offer_id`, so the publisher dashboard counts a tab open as a click.

`impossible_speed` measures `conversion.created_at − click.created_at`. On a reused embed row that delta is days or months, so the rule passes every embed conversion after the first session. It is silently inert there, not lenient by configuration.

Both are latent: no seeded network carries a `wall_url`, so no embed row exists until an admin enables a partner. Neither is fixed here on purpose — filtering the spike denominator without the numerator makes the rule strictly worse, and rebalancing a rule that holds real money deserves its own spec. The choice is to exclude embed rows from both halves of `publisher_spike` or to accept the distortion, and it has to be made before embed traffic runs at volume.

### On blocking a VPN at render

`vpn_proxy_ip` blocks now, and it blocks at wall render (docs/03 §2), not only at click. §2 above says never to leak the rule that caught someone — "click velocity exceeded" teaches a farm to slow down, and there is no version of that sentence worth showing. This rule family is the deliberate exception: the screen names the category, "a VPN or proxy," and where a fix exists it states it in one sentence, "disable it and reload." Never the rule key, the provider, the ASN, or the score.

That exception is safe here for a reason specific to who is on the other end of a VPN connection. A fraud farm running through a VPN already knows it is running through a VPN — the sentence teaches it nothing it didn't already know. A privacy-conscious real person does not know that their VPN is why the wall is empty, and telling them converts a churned publisher into a working one. The information is asymmetric in our favour, which is exactly the condition `publicReason()`'s blanket `unavailable` exists to protect when it does not hold.

That is also why blocking at render is a different refusal from blocking at click, not merely an earlier one. A user stopped at render is told what to change, and the fix is one toggle — they reload and are a served user thirty seconds later. A user only flagged at click converts, gets paid, and is charged back once the conversion is reviewed — the worse outcome for the publisher and for us. The original `flag` default reasoned that a consumer VPN is a privacy-conscious real person a meaningful fraction of the time, and that reasoning was not wrong; it no longer wins, because the actionable screen changes what a block costs that user. It did not exist when `flag` was chosen.

---

## 4. IP Intelligence

**Local databases, not per-click API calls.** A paid API on the click path adds latency to every click and a bill proportional to your traffic — including the fraudulent traffic you are trying not to pay for.

| Need | Source | File | Update |
|---|---|---|---|
| Country | MaxMind GeoLite2-Country | `GeoLite2-Country.mmdb` | `geoip:update`, weekly |
| ASN → datacenter | GeoLite2-ASN + a curated hosting-ASN list | `GeoLite2-ASN.mmdb` | `geoip:update`, weekly |
| Proxy type, usage type, threat | IP2Proxy LITE PX11 | `IP2PROXY-LITE-PX11.BIN` | `geoip:update`, weekly |

The files are licensed downloads, not repository content. `geoip:update` fetches
them into `PLAYNDGAIN_GEOIP_PATH` (default `storage/app/geoip`) using two free-tier
credentials, `MAXMIND_LICENSE_KEY` and `IP2LOCATION_DOWNLOAD_TOKEN`. Each source
is independent: an unset credential is a warning, a failed download fails only
its own database, and a download that does not open under the production reader
is discarded rather than allowed to replace the last good file. Whatever is
absent resolves to `IpVerdict::unknown()`, so the wall keeps serving — the admin
Health page is where their presence and age are visible.

`MaxMindIpIntelligence` reads the whole PX11 record in one `lookup(ALL)` call — proxy type, usage type and threat class travel with the lookup now, not only the yes/no `isProxy` flag the field used to be collapsed to. `FRAUD_SCORE` is deliberately never read: the LITE tier doesn't carry it and answers with a sentence asking you to upgrade the data file, not a number. ProxyCheck and IPQualityScore can fill that field; IPASIS's IP-only endpoint returns classifications without a score.

### The chain

A local answer is free; a paid one is not. `ChainedIpIntelligence` — the `IpIntelligence` binding — asks the local databases first and pays for a second opinion only when the local verdict is inconclusive:

- the address is unplaced (`country === null`)
- the local answer is clean (`isProxy === false && isDatacenter === false`) — exactly the answer a good residential proxy produces
- the local proxy class is `PUB`, IP2Proxy's vaguest one, which doesn't separate a compromised host from a shared exit

A conclusive local hit never escalates — if PX11 already says `TOR`, a second opinion costs money and changes nothing. When escalation does run, a provider answer fills gaps a local verdict left `null` or `false`; it never clears a local positive, because a provider disagreeing with a hosting-ASN match is the provider being wrong, not the ASN list. `proxyType` carries one exception to that rule: a local `PUB` is replaced by a provider's more specific class, because `PUB` isn't a positive worth protecting — it's the class escalation exists to resolve.

**Three cache layers, because one TTL can't serve two needs.** Local country must expire hourly — mobile carrier IPs rotate, and a day of caching misattributes them. A paid verdict must live far longer — proxy status and ASN move on weekly timescales, and an hourly TTL would re-bill every address every hour.

| Layer | Key | TTL | Holds |
|---|---|---|---|
| Merged verdict | `ip:v2:{ip}` | 3600s (300s degraded) | What `inspect()` returns |
| Local verdict | `ip:local:v2:{ip}` | 3600s | The local databases' own answer |
| Raw provider verdict | `ip:provider:{key}:{ip}` | per-provider `ttl_seconds`, default 86400s | One provider's answer, unmerged |

On a merged-verdict miss the chain re-reads the local databases — cheap, a memory-mapped file — and re-merges from the raw provider layer if one is still warm. **An HTTP call is spent only when the provider layer also misses.** Hourly re-merging costs nothing; hourly re-billing is the thing three layers exist to avoid.

A verdict built while a provider should have run but didn't — timed out, breaker open, cap spent, consent withheld — is marked `degraded` and cached for five minutes instead of an hour, so the next request retries instead of inheriting a transient failure for the full TTL.

**Three brakes, one row per provider in `ip_providers`, all visible on the admin Protection page:**

| Brake | Column | Default | At the limit |
|---|---|---|---|
| Timeout | `timeout_ms` | 600ms | Fails open to whatever the chain has so far |
| Circuit breaker | `circuit_failures` / `circuit_cooldown_minutes` | 5 / 10min | Opens on N consecutive failures; skipped entirely while open — no socket, no wait |
| Daily cap | `daily_cap` | null (uncapped) | Skipped once spent; counted in Redis (`ip_provider:{key}:{date}`), never in a column |

Each row also carries `priority` (ascending — first conclusive answer wins), `api_key` (`encrypted` cast, never rendered back into the form as plaintext), and `fail_open`. Rows are seeded disabled and unconfigured: `proxycheck` (ProxyCheck.io, priority 10), `ipasis` (IPASIS, priority 15), then `ipqualityscore` (IPQualityScore, priority 20). IPASIS provides a second classification before the scarcer scored IPQualityScore lookup. There is no way to create a row from the admin panel: a row whose key has no adapter class behind it does nothing, silently, which is worse than not having it.

### Escalation never precedes consent

`inspect()` takes a second, optional parameter: `inspect(string $ip, bool $consented = false): IpVerdict`. It defaults to `false`, so every existing call site is safe by omission — a caller that hasn't been taught about consent can't leak an address. `Null`, `Local` and `MaxMind` ignore it; only `ChainedIpIntelligence` reads it, as permission to escalate in a country where consent is required.

`WallGate::context()` calls `inspect($ip)` plainly, before consent is resolved — the country it returns is what resolves consent, so the ordering can't run the other way. If that country requires consent and none is granted yet, the chain stops at the local answer and marks the verdict degraded rather than shipping the address to a third party ahead of an answer. Once a granted consent is in hand, `WallGate::escalate()` asks again with `consented: true`, and the chain proceeds exactly as it would outside a consent country.

An unplaced address (`country === null`) escalates regardless of consent. It's the case escalation is worth the most — an unplaced user is served nothing but worldwide inventory — and it matches the position the platform already takes: `WallGate::consentRequired(null)` is `false`, so an address the databases can't place isn't treated as consent-required anywhere else either.

`ConversionProcessor` resolves the same `IpIntelligence` binding on the conversion postback path, with `consented` left at its default `false` — so a conversion in a consent-required country still degrades to the local-only verdict rather than escalating. That's deliberate, not an oversight: a conversion is the one address about to move real money, the daily cap already bounds what escalation can cost, and `conversion_geo_mismatch` (§3) was otherwise inert for exactly the unplaceable addresses most likely to be fraudulent — an unplaced conversion IP had nothing to compare the click's country against. Escalating here is what makes that comparison possible.

---

## 5. Auto-Ban

When an end user accumulates `blocks` blocking events inside `window_hours`:

```php
BannedEndUser::create([
    'publisher_id' => $ctx->publisherId,
    'app_id'       => $ctx->appId,
    'end_user_id'  => $ctx->endUserId,
    'banned_by'    => 'system',
    'reason'       => "auto: {$count} blocked events in {$hours}h",
    'expires_at'   => now()->addDays(7),
]);
```

**Auto-bans expire after 7 days.** Permanent bans are a human decision. A permanent automatic ban on a shared mobile IP eventually bans a real user forever, and nobody will ever find out why.

Bans are scoped to the publisher who owns the traffic. One publisher's ban does not affect another's — end user IDs are not globally unique and `user_1` on two different apps is two different people.

---

## 6. Admin Surface

**Fraud Rules** — table of every rule with an inline toggle, an action select, and a JSON config editor. Each row shows *triggered in the last 24h / 7d* so the operator can see which rules are actually earning their keep.

**Fraud Events** — filterable log: rule, publisher, app, end user, action taken, and the full context JSON. Filters for publisher, rule key, date range, action.

**Linked accounts** — pairs of publisher logins or wall userids that shared an IP, a device-signal hash, or a panel session. Wall rows never block, flag, or write a fraud event; the table is the alert. Panel rows also email ops once per new pair. Session history (hashed UA only) lives on the publisher view.

**Review Queue** — conversions sitting at `pending` because a `flag` rule fired. Each row shows the conversion, the rules that fired, the click, the IP verdict, and the user's history with that app. Two buttons: **Approve** (writes the ledger entry, fires the postback) and **Reject** (records the reason, no money moves). Both write to `audit_logs`.

**Banned Users** — searchable, with unban.

**Publisher Fraud Score** — a derived per-publisher panel showing block rate, flag rate, conversion rate versus platform median, and top triggered rules. This is what an admin looks at before approving a withdrawal.

---

## 7. Publisher Surface

Publishers see their own numbers and their own controls, never our rule internals:

- Blocked conversion count on the dashboard, with a generic category (`duplicate`, `policy`, `quality`) rather than the rule key.
- Their own blacklists — offer, country, device, network.
- Their own end-user bans, with manual ban and unban.

Exposing rule keys and thresholds to publishers teaches the fraudulent ones exactly what to tune. The categories are honest without being a manual.

---

## 8. Tests

Every rule gets two tests: one input that triggers it, one that does not. Beyond that:

- Pipeline: a `block` rule and a `flag` rule both firing → blocked, and **both** events recorded.
- Score accumulation across three rules → capped at 100.
- Disabled rule never runs.
- `Decision::publicReason()` never contains a rule key.
- Auto-ban fires at exactly the threshold, not one under, and sets a 7-day expiry.
- A ban for publisher A does not block the same `end_user_id` on publisher B's app.
- `FraudEvent` context JSON contains the inputs needed to explain the decision without re-running it.
