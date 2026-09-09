# IP Protection Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Read the IP intelligence already on disk properly, escalate inconclusive addresses to ProxyCheck.io and IPQualityScore, block VPN/proxy/datacenter traffic at wall render with an actionable screen, and give the admin one page to operate all of it.

**Architecture:** A `ChainedIpIntelligence` binds behind the `IpIntelligence` interface that already exists, so no consumer changes. It runs the local databases first, escalates to configured providers only when the local answer is inconclusive, and never escalates for a user in a consent-required country who has not consented. Protection blocks at wall render by reading the `fraud_rules` rows the admin already tunes — no second configuration.

**Tech Stack:** Laravel 13, PHP 8.4, Filament 5, Pest 5, Redis (cache + counters), MaxMind GeoLite2, IP2Proxy PX11.

**Spec:** `docs/superpowers/specs/2026-08-19-ip-protection-design.md`

> **Amended after review — the spec is the authority, not this file.** Two contracts below were
> changed during implementation and this document was deliberately left as the historical record of
> what was planned. Where they disagree, follow the spec.
>
> - `RenderGateRule::noticeKey()` is an **instance** method taking the rule's `RuleResult` and
>   returning `?string`. Null means the rule fired but this trigger does not warrant refusing a
>   render; the `fraud_event` is still written. The code blocks below still show the original
>   `static noticeKey(): string` and a `$rule::noticeKey()` call site. See spec §5.
> - Task 8's IP rules section is **read-only** with fire counts, not editable in place. See spec §9.4.
> - The ProxyCheck type table has been corrected in place, because leaving it would let a re-run
>   reproduce a bug that blocked every corporate ISP user.

## Global Constraints

- **PHP 8.4, `declare` not used in this codebase.** Match surrounding style: constructor property promotion, `readonly` where the class is a value object, `final readonly class` for value objects.
- **Every class gets a docblock explaining *why*, not what.** Read three neighbouring files before writing one. This codebase's comments carry reasoning; a comment restating the code is worse than none.
- **`IpIntelligence::inspect()` gains one optional parameter:** `inspect(string $ip, bool $consented = false): IpVerdict`. The default is `false` so every existing call site is safe by omission.
- **Cache keys:** merged verdict `ip:v2:{ip}` (TTL 3600, or 300 when degraded); local verdict `ip:local:v2:{ip}` (TTL 3600); raw provider verdict `ip:provider:{providerKey}:{ip}` (TTL `ip_providers.ttl_seconds`, default 86400). Provider daily counters `ip_provider:{key}:{Y-m-d}` in Redis.
- **`"-"` from IP2Proxy maps to `null`, never to a string.**
- **Fraud rule keys are the contract.** `FraudServiceProvider::RULES` and `FraudRuleSeeder::RULES` are kept in the same order deliberately — they are a diffable pair. Add to both, in the same position.
- **Blocked-screen copy names a category, never a rule key, provider, ASN, organisation or score.**
- **Tests:** Pest, `tests/Feature/...`, `require_once __DIR__.'/FraudFixtures.php'` (or `WallFixtures.php`) at the top of each file — Pest only auto-loads `tests/Pest.php`.
- **Run before every commit:** `composer lint` (Pint) then `composer analyse` (PHPStan/Larastan). Both must be clean.
- **Commit per task**, message in the house style: lowercase `type(scope): imperative summary`, body explaining why. End with the `Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>` trailer.

## File Structure

**Created**

| File | Responsibility |
|---|---|
| `app/Enums/ProxyType.php` | Closed set of IP2Proxy/provider proxy classes |
| `app/Fraud/Rules/TorIpRule.php` | `tor_ip` |
| `app/Fraud/Rules/ResidentialProxyIpRule.php` | `residential_proxy_ip` |
| `app/Fraud/Rules/IpRiskScoreRule.php` | `ip_risk_score` |
| `app/Fraud/Contracts/RenderGateRule.php` | Marker: this rule may refuse a wall render |
| `app/Models/IpProvider.php` | One third-party provider's configuration and live state |
| `app/Fraud/Providers/Contracts/IpProviderDriver.php` | One provider's lookup contract |
| `app/Fraud/Providers/AbstractIpProvider.php` | Timeout, breaker, daily cap, counters, error recording |
| `app/Fraud/Providers/ProxyCheckProvider.php` | proxycheck.io adapter |
| `app/Fraud/Providers/IpQualityScoreProvider.php` | ipqualityscore.com adapter |
| `app/Fraud/Providers/IpProviderRegistry.php` | Key → driver class, mirrors `RuleRegistry` |
| `app/Fraud/ChainedIpIntelligence.php` | Local first, escalate, merge, cache, consent guard |
| `app/Filament/Admin/Pages/Protection.php` | The control surface |
| `resources/views/filament/admin/pages/protection.blade.php` | Its view |
| `tests/Feature/Panel/ProtectionPageTest.php` | Its test, in the directory the other panel tests use |
| `database/migrations/2026_08_19_000002_create_ip_providers_table.php` | |
| `database/migrations/2026_08_19_000003_promote_vpn_proxy_ip_to_block.php` | |
| `database/seeders/IpProviderSeeder.php` | |

**Modified**

| File | Change |
|---|---|
| `app/Fraud/IpVerdict.php` | New fields, `merge()`, `degraded()`, key-based `remember()` |
| `app/Fraud/Contracts/IpIntelligence.php` | `$consented` parameter |
| `app/Fraud/NullIpIntelligence.php`, `LocalIpIntelligence.php` | Signature |
| `app/Fraud/MaxMindIpIntelligence.php` | Read PX11 proxy type, usage type, threat |
| `app/Fraud/Rules/VpnProxyIpRule.php` | Narrow to consumer VPN, marker, docblock |
| `app/Fraud/Rules/DatacenterIpRule.php` | `DCH` trigger, marker, docblock |
| `app/Services/Wall/WallContext.php` | `verdict` field |
| `app/Services/Wall/WallGate.php` | `protectionBlock()`, consented re-inspect |
| `app/Http/Controllers/Wall/WallController.php` | Gate step after consent and ban |
| `app/Models/Setting.php` | `consentCountries()` |
| `app/Providers/FraudServiceProvider.php` | Register new rules, bind chain, register providers |
| `database/seeders/FraudRuleSeeder.php` | Three new rules, two rewritten descriptions |
| `lang/en/wall.php`, `lang/ar/wall.php` | Three notice keys |
| `docs/05-fraud-engine.md` | Rules table, IP intelligence section, VPN rationale |
| `tests/Feature/Fraud/IpVerdictCacheTest.php` | Key change |

---

### Task 1: `ProxyType`, and an `IpVerdict` that can carry a provider answer

**Files:**
- Create: `app/Enums/ProxyType.php`
- Modify: `app/Fraud/IpVerdict.php`, `app/Fraud/Contracts/IpIntelligence.php`, `app/Fraud/NullIpIntelligence.php`, `app/Fraud/LocalIpIntelligence.php`, `tests/Feature/Fraud/IpVerdictCacheTest.php`
- Test: `tests/Feature/Fraud/IpVerdictTest.php`

**Interfaces:**
- Produces: `ProxyType` enum (`Vpn`, `Tor`, `Datacenter`, `Public`, `Web`, `Search`, `Residential`, `Consumer`) with `ProxyType::parse(?string): ?self`. `IpVerdict` gains `?ProxyType $proxyType`, `?string $usageType`, `?string $threat`, `?int $riskScore`, `string $source = 'local'`, `bool $degraded = false`; plus `merge(self $other, string $source): self` and `degraded(): self`. `IpVerdict::remember(string $key, int $ttl, Closure $lookup): self` now takes a **cache key**, not an IP. `IpIntelligence::inspect(string $ip, bool $consented = false): IpVerdict`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Fraud/IpVerdictTest.php`:

```php
<?php

use App\Enums\ProxyType;
use App\Fraud\IpVerdict;

it('parses the IP2Proxy proxy classes and rejects everything else', function () {
    expect(ProxyType::parse('VPN'))->toBe(ProxyType::Vpn)
        ->and(ProxyType::parse('res'))->toBe(ProxyType::Residential)
        ->and(ProxyType::parse('-'))->toBeNull()
        ->and(ProxyType::parse(null))->toBeNull()
        ->and(ProxyType::parse('NOPE'))->toBeNull();
});

it('fills gaps from a provider answer but never clears a local positive', function () {
    $local = new IpVerdict(
        country: 'DE',
        asn: 24940,
        isDatacenter: true,
        isProxy: true,
        proxyType: ProxyType::Datacenter,
    );

    $provider = new IpVerdict(
        country: 'FR',
        organization: 'Hetzner Online GmbH',
        isDatacenter: false,
        isProxy: false,
        proxyType: ProxyType::Residential,
        riskScore: 91,
    );

    $merged = $local->merge($provider, 'ipqualityscore');

    // Present locally, so the provider does not get to overwrite it.
    expect($merged->country)->toBe('DE')
        ->and($merged->isDatacenter)->toBeTrue()
        ->and($merged->isProxy)->toBeTrue()
        ->and($merged->proxyType)->toBe(ProxyType::Datacenter)
        // Absent locally, so the provider fills it.
        ->and($merged->organization)->toBe('Hetzner Online GmbH')
        ->and($merged->riskScore)->toBe(91)
        ->and($merged->source)->toBe('ipqualityscore');
});

it('marks a verdict degraded without losing what it already knows', function () {
    $verdict = (new IpVerdict(country: 'EG', isProxy: true))->degraded();

    expect($verdict->degraded)->toBeTrue()
        ->and($verdict->country)->toBe('EG')
        ->and($verdict->isProxy)->toBeTrue();
});

it('remembers under the key it is given', function () {
    $verdict = IpVerdict::remember('ip:local:v2:9.9.9.9', 60, fn () => new IpVerdict(country: 'US'));

    expect($verdict->country)->toBe('US')
        ->and(cache()->get('ip:local:v2:9.9.9.9'))->toEqual($verdict);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/Fraud/IpVerdictTest.php`
Expected: FAIL — `Class "App\Enums\ProxyType" not found`.

- [ ] **Step 3: Write `ProxyType`**

Create `app/Enums/ProxyType.php`:

```php
<?php

namespace App\Enums;

/**
 * The IP2Proxy `PROXY_TYPE` classes, which the paid providers also map onto.
 *
 * A closed set rather than the raw string, because three rules compare against
 * these values and a typo in one of them is a rule that silently never fires —
 * the failure mode a fraud signal can least afford.
 */
enum ProxyType: string
{
    case Vpn = 'VPN';
    case Tor = 'TOR';
    case Datacenter = 'DCH';
    case Public = 'PUB';
    case Web = 'WEB';
    case Search = 'SES';
    case Residential = 'RES';
    case Consumer = 'CPN';

    /**
     * IP2Proxy returns `-` for an unlisted address and a provider can return
     * anything at all, so an unrecognised value is "we do not know", never an
     * exception. A lookup that throws on a new class the vendor added would
     * take the wall down for a data refresh.
     */
    public static function parse(?string $value): ?self
    {
        if (! is_string($value) || $value === '' || $value === '-') {
            return null;
        }

        return self::tryFrom(strtoupper($value));
    }
}
```

- [ ] **Step 4: Extend `IpVerdict`**

Replace `app/Fraud/IpVerdict.php` with:

```php
<?php

namespace App\Fraud;

use App\Enums\ProxyType;
use Closure;
use Illuminate\Support\Facades\Cache;

final readonly class IpVerdict
{
    public function __construct(
        /** ISO-2, uppercase. Null when the databases cannot place the address. */
        public ?string $country = null,
        public ?int $asn = null,
        public ?string $organization = null,
        public bool $isDatacenter = false,
        public bool $isProxy = false,
        public ?ProxyType $proxyType = null,
        /** IP2Proxy `USAGE_TYPE` — DCH, CDN, ISP, MOB, COM, ORG, EDU, GOV, LIB, SES, RSV. */
        public ?string $usageType = null,
        /** IP2Proxy `THREAT` — SPAM, BOTNET, SCANNER. */
        public ?string $threat = null,
        /** 0–100, third-party only. Null means nobody scored it, which is not zero. */
        public ?int $riskScore = null,
        /** Which layer produced this: `local`, or a provider key. */
        public string $source = 'local',
        /**
         * A provider that should have answered did not — it timed out, its
         * breaker was open, its cap was spent, or consent withheld it. The
         * verdict is usable but incomplete, and it caches for minutes rather
         * than an hour so the next request retries instead of inheriting a
         * transient failure for the full TTL.
         */
        public bool $degraded = false,
    ) {}

    public static function unknown(): self
    {
        return new self;
    }

    /**
     * A provider answer fills gaps; it never clears a local positive.
     *
     * If IP2Proxy says datacenter and a provider says clean, the verdict stays
     * datacenter. Fraud signals are additive, and a provider contradicting a
     * hosting-ASN match is a provider being wrong — deferring to it would let
     * one vendor's blind spot unmake a signal we hold ourselves.
     *
     * `riskScore` is the exception with no local value at all: the LITE BIN has
     * no fraud score, so the first provider to return one owns it.
     */
    public function merge(self $other, string $source): self
    {
        return new self(
            country: $this->country ?? $other->country,
            asn: $this->asn ?? $other->asn,
            organization: $this->organization ?? $other->organization,
            isDatacenter: $this->isDatacenter || $other->isDatacenter,
            isProxy: $this->isProxy || $other->isProxy,
            proxyType: $this->proxyType ?? $other->proxyType,
            usageType: $this->usageType ?? $other->usageType,
            threat: $this->threat ?? $other->threat,
            riskScore: $this->riskScore ?? $other->riskScore,
            source: $source,
            degraded: $this->degraded,
        );
    }

    public function degraded(): self
    {
        return new self(
            country: $this->country,
            asn: $this->asn,
            organization: $this->organization,
            isDatacenter: $this->isDatacenter,
            isProxy: $this->isProxy,
            proxyType: $this->proxyType,
            usageType: $this->usageType,
            threat: $this->threat,
            riskScore: $this->riskScore,
            source: $this->source,
            degraded: true,
        );
    }

    /**
     * The one cache seam every layer goes through, so they cannot drift on
     * shape or on the guard below. **It takes a key, not an IP** — there are
     * three layers with three TTLs (`ip:v2:` merged, `ip:local:v2:` local,
     * `ip:provider:{key}:` raw) and one entry cannot serve them all: local
     * country must expire hourly because carrier IPs rotate, while a paid
     * verdict expiring hourly re-bills every address every hour.
     *
     * A payload that is not a verdict is discarded and looked up again. An
     * entry written before this class was autoloadable deserialises to
     * `__PHP_Incomplete_Class`, and the return type would then raise a
     * TypeError on every wall request from that address until the TTL is up.
     * Degrading beats taking the wall down.
     *
     * The guard does **not** cover a shape change to a class that still
     * exists: an entry written before a property was added deserialises into a
     * valid instance whose new typed property is uninitialised, and the first
     * read of it throws. That is what the `v2` in the keys is for — a key
     * version is the only thing that reliably retires an old shape.
     *
     * @param  Closure(): self  $lookup
     */
    public static function remember(string $key, int $ttl, Closure $lookup): self
    {
        $verdict = Cache::get($key);

        if ($verdict instanceof self) {
            return $verdict;
        }

        $verdict = $lookup();

        Cache::put($key, $verdict, $ttl);

        return $verdict;
    }
}
```

- [ ] **Step 5: Widen the interface and the two simple implementations**

In `app/Fraud/Contracts/IpIntelligence.php`, replace the method:

```php
    /**
     * `$consented` is permission to send this address to a third party. It
     * defaults to false so a caller that has not thought about consent cannot
     * leak an IP by omission — see ChainedIpIntelligence, the only
     * implementation that reads it.
     */
    public function inspect(string $ip, bool $consented = false): IpVerdict;
```

In `app/Fraud/NullIpIntelligence.php`:

```php
    public function inspect(string $ip, bool $consented = false): IpVerdict
    {
        return IpVerdict::remember("ip:local:v2:{$ip}", MaxMindIpIntelligence::TTL, fn (): IpVerdict => IpVerdict::unknown());
    }
```

In `app/Fraud/LocalIpIntelligence.php`:

```php
    public function inspect(string $ip, bool $consented = false): IpVerdict
    {
        if (! self::isLocal($ip)) {
            return $this->inner->inspect($ip, $consented);
        }

        return new IpVerdict(country: strtoupper($this->country));
    }
```

In `app/Fraud/MaxMindIpIntelligence.php`, only the signature and the key for now:

```php
    public function inspect(string $ip, bool $consented = false): IpVerdict
    {
        return IpVerdict::remember("ip:local:v2:{$ip}", self::TTL, fn (): IpVerdict => $this->lookup($ip));
    }
```

- [ ] **Step 6: Update the existing cache test to the new key**

In `tests/Feature/Fraud/IpVerdictCacheTest.php`, replace every literal `"ip:{$ip}"` / `'ip:5.5.5.5'` style key with the `ip:local:v2:` prefix. Read the file first and change only the key strings — the assertions themselves still hold.

- [ ] **Step 7: Run the tests**

Run: `php artisan test tests/Feature/Fraud/`
Expected: PASS.

- [ ] **Step 8: Lint, analyse, commit**

```bash
composer lint && composer analyse
git add app/Enums/ProxyType.php app/Fraud tests/Feature/Fraud
git commit -m "feat(fraud): give IpVerdict the fields a provider answer carries

The value object held five fields and one cache key. It now carries proxy
type, usage type, threat and a risk score, knows how to merge a second
opinion without letting it clear a local positive, and can mark itself
degraded so a transient provider failure caches for minutes rather than an
hour. remember() takes a key instead of an IP because there are now three
layers with three different TTLs.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Read the PX11 fields already on disk

**Files:**
- Modify: `app/Fraud/MaxMindIpIntelligence.php`
- Test: `tests/Feature/Fraud/MaxMindFieldsTest.php`

**Interfaces:**
- Consumes: `IpVerdict` and `ProxyType` from Task 1.
- Produces: `MaxMindIpIntelligence::lookup()` populating `proxyType`, `usageType`, `threat`. No new public API.

**Context:** the databases are provisioned at `storage/app/geoip/`. Verified against `IP2PROXY-LITE-PX11.BIN` version `2026.8.16`: `PROXY_TYPE`, `USAGE_TYPE`, `THREAT`, `ISP`, `DOMAIN`, `ASN` all populate for listed addresses; `FRAUD_SCORE` returns `"This parameter is unavailable in selected .BIN data file"` and must never be read here. Unlisted addresses return `"-"` for every field.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Fraud/MaxMindFieldsTest.php`:

```php
<?php

use App\Enums\ProxyType;
use App\Fraud\MaxMindIpIntelligence;

require_once __DIR__.'/FraudFixtures.php';

it('reads the proxy classification fields when the database is on disk', function () {
    if (! is_file(MaxMindIpIntelligence::file(MaxMindIpIntelligence::PROXY_DB))) {
        $this->markTestSkipped('IP2Proxy database not provisioned in this environment.');
    }

    // Found rather than hardcoded. `geoip:update` replaces this file weekly and
    // LITE listings churn, so pinning a specific address writes a test that
    // fails in some future week with no code change behind it.
    $reader = new MaxMindIpIntelligence;
    $listed = null;

    mt_srand(7);

    for ($i = 0; $i < 5000 && $listed === null; $i++) {
        $candidate = mt_rand(1, 223).'.'.mt_rand(0, 255).'.'.mt_rand(0, 255).'.'.mt_rand(1, 254);

        if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            continue;
        }

        if ($reader->inspect($candidate)->isProxy) {
            $listed = $reader->inspect($candidate);
        }
    }

    if ($listed === null) {
        $this->markTestSkipped('No listed proxy found in the sample — the database may be empty.');
    }

    // Shape, not values. A listed address carries a class; nothing here carries
    // a score, because the LITE file answers that field with the vendor's
    // "please upgrade" sentence and storing it would be storing marketing copy.
    expect($listed->proxyType)->toBeInstanceOf(ProxyType::class)
        ->and($listed->riskScore)->toBeNull()
        ->and($listed->usageType)->not->toBe('-')
        ->and($listed->threat)->not->toBe('-');
});

it('degrades to unknown rather than throwing when no database is present', function () {
    config()->set('playndgain.geoip_path', storage_path('app/geoip-absent'));

    $verdict = (new MaxMindIpIntelligence)->inspect('194.226.224.226');

    expect($verdict->country)->toBeNull()
        ->and($verdict->proxyType)->toBeNull()
        ->and($verdict->usageType)->toBeNull()
        ->and($verdict->threat)->toBeNull();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/Fraud/MaxMindFieldsTest.php`
Expected: FAIL — `proxyType` is null because nothing reads `PROXY_TYPE`.

- [ ] **Step 3: Replace `isProxy()` with one record read**

In `app/Fraud/MaxMindIpIntelligence.php`, replace the `lookup()` and `isProxy()` methods:

```php
    private function lookup(string $ip): IpVerdict
    {
        [$asn, $organization] = $this->asn($ip);
        $proxy = $this->proxy($ip);

        return new IpVerdict(
            country: $this->country($ip),
            asn: $asn,
            organization: $organization,
            isDatacenter: $asn !== null && in_array($asn, self::HOSTING_ASNS, true),
            isProxy: $proxy['is_proxy'],
            proxyType: $proxy['proxy_type'],
            usageType: $proxy['usage_type'],
            threat: $proxy['threat'],
        );
    }

    /**
     * One `lookup(ALL)` rather than four single-field calls — the reader walks
     * the same index either way, and four walks for one row is three too many
     * on the click path.
     *
     * `FRAUD_SCORE` is deliberately not read. The LITE file does not carry it
     * and returns the vendor's "please upgrade this data file" sentence in its
     * place, which is a string, not a score. The third-party providers fill it.
     *
     * @return array{is_proxy: bool, proxy_type: ?ProxyType, usage_type: ?string, threat: ?string}
     */
    private function proxy(string $ip): array
    {
        $none = ['is_proxy' => false, 'proxy_type' => null, 'usage_type' => null, 'threat' => null];

        $reader = $this->reader(self::PROXY_DB);

        if ($reader === null) {
            return $none;
        }

        try {
            $record = $reader->lookup($ip, IP2ProxyDatabase::ALL);
        } catch (Throwable) {
            return $none;
        }

        if (! is_array($record)) {
            return $none;
        }

        return [
            // 0 = not a proxy, -1 = lookup failed, 1 = anonymiser, 2 = datacenter
            // or crawler. Failing closed would flag every address the moment the
            // file went stale, so anything not positive is "no".
            'is_proxy' => (int) ($record['isProxy'] ?? 0) > 0,
            'proxy_type' => ProxyType::parse(self::field($record, 'proxyType')),
            'usage_type' => self::field($record, 'usageType'),
            'threat' => self::field($record, 'threat'),
        ];
    }

    /**
     * IP2Proxy writes `-` for an unlisted address and a whole sentence for a
     * field this tier does not carry. Both mean "no value", and both would be
     * stored as data by a naive read.
     *
     * @param  array<string, mixed>  $record
     */
    private static function field(array $record, string $key): ?string
    {
        $value = $record[$key] ?? null;

        if (! is_string($value) || $value === '' || $value === '-' || str_contains($value, 'unavailable in selected')) {
            return null;
        }

        return $value;
    }
```

Add `use App\Enums\ProxyType;` to the imports.

- [ ] **Step 4: Run the tests**

Run: `php artisan test tests/Feature/Fraud/`
Expected: PASS.

- [ ] **Step 5: Lint, analyse, commit**

```bash
composer lint && composer analyse
git add app/Fraud/MaxMindIpIntelligence.php tests/Feature/Fraud/MaxMindFieldsTest.php
git commit -m "feat(fraud): read the IP2Proxy fields the wall already pays for

PX11 carries eleven fields and the reader collapsed them to one boolean, so a
residential proxy and a consumer VPN arrived at the rules indistinguishable.
Proxy type, usage type and threat now reach IpVerdict, in one record read
rather than four index walks. Fraud score stays unread: the LITE tier answers
that field with a sentence asking you to upgrade, and storing it would be
storing marketing copy as data.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: The rules, and the VPN promotion

**Files:**
- Create: `app/Fraud/Contracts/RenderGateRule.php`, `app/Fraud/Rules/TorIpRule.php`, `app/Fraud/Rules/ResidentialProxyIpRule.php`, `app/Fraud/Rules/IpRiskScoreRule.php`, `database/migrations/2026_08_19_000003_promote_vpn_proxy_ip_to_block.php`
- Modify: `app/Fraud/Rules/VpnProxyIpRule.php`, `app/Fraud/Rules/DatacenterIpRule.php`, `app/Providers/FraudServiceProvider.php`, `database/seeders/FraudRuleSeeder.php`
- Test: `tests/Feature/Fraud/IpRulesTest.php`

**Interfaces:**
- Consumes: `IpVerdict`, `ProxyType` (Task 1); `MaxMindIpIntelligence` field reads (Task 2).
- Produces: `RenderGateRule` marker interface with `public static function noticeKey(): string`. Rule keys `tor_ip`, `residential_proxy_ip`, `ip_risk_score`. `DatacenterIpRule`, `VpnProxyIpRule`, `TorIpRule`, `ResidentialProxyIpRule`, `IpRiskScoreRule` all implement `RenderGateRule`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Fraud/IpRulesTest.php`:

```php
<?php

use App\Enums\FraudAction;
use App\Enums\FraudStage;
use App\Enums\ProxyType;
use App\Fraud\FraudPipeline;
use App\Fraud\IpVerdict;
use App\Fraud\Rules\DatacenterIpRule;
use App\Fraud\Rules\IpRiskScoreRule;
use App\Fraud\Rules\ResidentialProxyIpRule;
use App\Fraud\Rules\TorIpRule;
use App\Fraud\Rules\VpnProxyIpRule;

require_once __DIR__.'/FraudFixtures.php';

/** Run one pre-click rule against one verdict and say whether it fired. */
function ipRuleFires(string $key, IpVerdict $verdict, array $config = []): bool
{
    $click = fraudClick();
    fakeIpVerdict($verdict);
    fraudRule($key, FraudStage::PreClick, FraudAction::Block, $config, weight: 10);

    return app(FraudPipeline::class)->evaluate(FraudStage::PreClick, fraudContext($click))->blocked;
}

it('fires tor_ip on Tor and on nothing else', function () {
    expect(ipRuleFires('tor_ip', new IpVerdict(isProxy: true, proxyType: ProxyType::Tor)))->toBeTrue()
        ->and(ipRuleFires('tor_ip', new IpVerdict(isProxy: true, proxyType: ProxyType::Vpn)))->toBeFalse()
        ->and(ipRuleFires('tor_ip', new IpVerdict))->toBeFalse();
});

it('fires residential_proxy_ip only on a rented home connection', function () {
    expect(ipRuleFires('residential_proxy_ip', new IpVerdict(isProxy: true, proxyType: ProxyType::Residential)))->toBeTrue()
        ->and(ipRuleFires('residential_proxy_ip', new IpVerdict(isProxy: true, proxyType: ProxyType::Vpn)))->toBeFalse();
});

it('narrows vpn_proxy_ip to the consumer classes', function () {
    expect(ipRuleFires('vpn_proxy_ip', new IpVerdict(isProxy: true, proxyType: ProxyType::Vpn)))->toBeTrue()
        ->and(ipRuleFires('vpn_proxy_ip', new IpVerdict(isProxy: true, proxyType: ProxyType::Public)))->toBeTrue()
        // Tor and residential have their own rules with their own weights now.
        ->and(ipRuleFires('vpn_proxy_ip', new IpVerdict(isProxy: true, proxyType: ProxyType::Tor)))->toBeFalse()
        ->and(ipRuleFires('vpn_proxy_ip', new IpVerdict(isProxy: true, proxyType: ProxyType::Residential)))->toBeFalse()
        // An unclassified proxy is still a proxy — the old boolean behaviour.
        ->and(ipRuleFires('vpn_proxy_ip', new IpVerdict(isProxy: true)))->toBeTrue();
});

it('fires datacenter_ip on a DCH proxy type no hosting ASN covers', function () {
    expect(ipRuleFires('datacenter_ip', new IpVerdict(asn: 4242, proxyType: ProxyType::Datacenter)))->toBeTrue()
        ->and(ipRuleFires('datacenter_ip', new IpVerdict(asn: 24940, isDatacenter: true)))->toBeTrue()
        ->and(ipRuleFires('datacenter_ip', new IpVerdict(asn: 4242)))->toBeFalse();
});

it('fires ip_risk_score at or above its threshold and never without a score', function () {
    expect(ipRuleFires('ip_risk_score', new IpVerdict(riskScore: 85), ['threshold' => 85]))->toBeTrue()
        ->and(ipRuleFires('ip_risk_score', new IpVerdict(riskScore: 84), ['threshold' => 85]))->toBeFalse()
        // Null is "nobody scored it", which is not zero and not clean.
        ->and(ipRuleFires('ip_risk_score', new IpVerdict, ['threshold' => 85]))->toBeFalse();
});

it('marks every IP-family rule as render-gate eligible with a notice key', function () {
    $rules = [
        DatacenterIpRule::class => 'blocked_datacenter',
        VpnProxyIpRule::class => 'blocked_proxy',
        TorIpRule::class => 'blocked_proxy',
        ResidentialProxyIpRule::class => 'blocked_proxy',
        IpRiskScoreRule::class => 'blocked_risk',
    ];

    foreach ($rules as $class => $notice) {
        expect($class::noticeKey())->toBe($notice);
    }
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/Fraud/IpRulesTest.php`
Expected: FAIL — `Class "App\Fraud\Rules\TorIpRule" not found`.

- [ ] **Step 3: Write the marker interface**

Create `app/Fraud/Contracts/RenderGateRule.php`:

```php
<?php

namespace App\Fraud\Contracts;

/**
 * A rule whose only input is the `IpVerdict`, and which may therefore refuse a
 * wall render rather than waiting for a click.
 *
 * The marker exists so the render gate cannot accidentally run a rule that
 * needs a click. `click_velocity` at render would count tab opens as clicks;
 * `banned_end_user` at render would duplicate a check `WallGate` already does.
 * Implementing this interface is the assertion that neither applies.
 *
 * The notice key is the screen the end user sees. Several rules share one on
 * purpose — the screen is a category, not a verdict, and telling a user which
 * rule caught them is what the fraud engine spends its effort not doing.
 */
interface RenderGateRule extends Rule
{
    /** A `wall.notice.*` key. Never contains the rule key. */
    public static function noticeKey(): string;
}
```

- [ ] **Step 4: Write the three new rules**

Create `app/Fraud/Rules/TorIpRule.php`:

```php
<?php

namespace App\Fraud\Rules;

use App\Enums\FraudStage;
use App\Enums\ProxyType;
use App\Fraud\Contracts\IpIntelligence;
use App\Fraud\Contracts\RenderGateRule;
use App\Fraud\FraudContext;
use App\Fraud\RuleResult;

/**
 * Tor exit node.
 *
 * Split out of `vpn_proxy_ip`, which scored it the same as a consumer VPN.
 * Nobody completes a mobile install offer over Tor by accident: the latency
 * makes it painful and the anonymity is the only reason to accept the pain.
 */
class TorIpRule implements RenderGateRule
{
    public function __construct(private readonly IpIntelligence $intelligence) {}

    public static function key(): string
    {
        return 'tor_ip';
    }

    public static function stage(): FraudStage
    {
        return FraudStage::PreClick;
    }

    public static function noticeKey(): string
    {
        return 'blocked_proxy';
    }

    public function evaluate(FraudContext $context, array $config): RuleResult
    {
        if (blank($context->ip)) {
            return RuleResult::pass();
        }

        $verdict = $this->intelligence->inspect($context->ip);

        if ($verdict->proxyType !== ProxyType::Tor) {
            return RuleResult::pass();
        }

        return RuleResult::trigger('click originates from a Tor exit node', [
            'ip' => $context->ip,
            'asn' => $verdict->asn,
            'organization' => $verdict->organization,
            'country' => $verdict->country,
            'source' => $verdict->source,
        ]);
    }
}
```

Create `app/Fraud/Rules/ResidentialProxyIpRule.php` — identical shape, with these differences:

```php
/**
 * Rented access to somebody else's home connection.
 *
 * The single most valuable signal this platform can read, and until now it
 * scored 20 alongside a consumer VPN. A residential proxy is not a privacy
 * tool; it is the standard transport for an install farm, precisely because it
 * defeats both the hosting-ASN list and a country check at once. The user
 * whose connection is being rented is usually unaware of it.
 */
class ResidentialProxyIpRule implements RenderGateRule
{
    // key(): 'residential_proxy_ip'
    // stage(): FraudStage::PreClick
    // noticeKey(): 'blocked_proxy'
    // evaluate(): passes unless $verdict->proxyType === ProxyType::Residential
    // trigger reason: 'click originates from a residential proxy'
}
```

Create `app/Fraud/Rules/IpRiskScoreRule.php`:

```php
<?php

namespace App\Fraud\Rules;

use App\Enums\FraudStage;
use App\Fraud\Contracts\IpIntelligence;
use App\Fraud\Contracts\RenderGateRule;
use App\Fraud\FraudContext;
use App\Fraud\RuleResult;

/**
 * A third-party risk score over a configured threshold.
 *
 * The only rule here with no local signal behind it: the LITE IP2Proxy file
 * carries no fraud score, so this fires exactly when a provider answered and
 * scored the address. A null score is "nobody scored it", which is neither
 * zero nor clean — an address nobody rated must not be treated as rated well.
 *
 * Seeded at `flag`, deliberately. It depends entirely on a vendor's number,
 * and blocking on a number nobody has calibrated against this platform's own
 * traffic is blocking revenue. The Protection page shows the distribution.
 */
class IpRiskScoreRule implements RenderGateRule
{
    private const DEFAULT_THRESHOLD = 85;

    public function __construct(private readonly IpIntelligence $intelligence) {}

    public static function key(): string
    {
        return 'ip_risk_score';
    }

    public static function stage(): FraudStage
    {
        return FraudStage::PreClick;
    }

    public static function noticeKey(): string
    {
        return 'blocked_risk';
    }

    public function evaluate(FraudContext $context, array $config): RuleResult
    {
        if (blank($context->ip)) {
            return RuleResult::pass();
        }

        $verdict = $this->intelligence->inspect($context->ip);
        $threshold = is_numeric($config['threshold'] ?? null)
            ? (int) $config['threshold']
            : self::DEFAULT_THRESHOLD;

        if ($verdict->riskScore === null || $verdict->riskScore < $threshold) {
            return RuleResult::pass();
        }

        return RuleResult::trigger('third-party risk score over threshold', [
            'ip' => $context->ip,
            'score' => $verdict->riskScore,
            'threshold' => $threshold,
            'country' => $verdict->country,
            'source' => $verdict->source,
        ]);
    }
}
```

- [ ] **Step 5: Narrow `VpnProxyIpRule` and widen `DatacenterIpRule`**

In `app/Fraud/Rules/VpnProxyIpRule.php`, replace the class docblock and the `implements`, add `noticeKey()`, and replace the verdict check:

```php
/**
 * Consumer VPN and unclassified proxy exit nodes.
 *
 * Tor and residential proxies used to land here too and scored the same 20.
 * They now have their own rules, because they are not the same thing: a
 * consumer VPN is often a privacy-conscious real person, while a residential
 * proxy is rented access to a stranger's connection.
 *
 * This rule blocks, and blocks at wall render. That reverses the original
 * default and the reversal is deliberate: blocking at render is not the same
 * refusal as blocking at click. The user is told they are on a VPN, the fix is
 * one toggle, and they come back and convert. A flagged VPN conversion is paid
 * out and then charged back.
 */
class VpnProxyIpRule implements RenderGateRule
{
    private const CONSUMER = [
        ProxyType::Vpn,
        ProxyType::Public,
        ProxyType::Web,
        ProxyType::Search,
        ProxyType::Consumer,
    ];

    // ... key(), stage() unchanged ...

    public static function noticeKey(): string
    {
        return 'blocked_proxy';
    }
```

and in `evaluate()`, replace `if (! $verdict->isProxy)` with:

```php
        // An unclassified proxy still counts: `isProxy` with no type is what a
        // stale database or a terse provider returns, and it is the behaviour
        // this rule had before the classes existed.
        $consumer = $verdict->proxyType === null
            ? $verdict->isProxy
            : in_array($verdict->proxyType, self::CONSUMER, true);

        if (! $consumer) {
            return RuleResult::pass();
        }
```

Also add `'proxy_type' => $verdict->proxyType?->value,` and `'source' => $verdict->source,` to the trigger context array.

In `app/Fraud/Rules/DatacenterIpRule.php`, change `implements Rule` to `implements RenderGateRule`, add:

```php
    public static function noticeKey(): string
    {
        return 'blocked_datacenter';
    }
```

and replace the pass condition:

```php
        // IP2Proxy classifies datacenter addresses whose ASN nobody thought to
        // add to the curated list, and the curated list covers ranges IP2Proxy
        // has not seen behaving as a proxy. Neither subsumes the other.
        $fromProxyType = $verdict->proxyType === ProxyType::Datacenter;

        if (! $verdict->isDatacenter && ! $fromConfig && ! $fromProxyType) {
            return RuleResult::pass();
        }
```

and the `matched` context value:

```php
            'matched' => match (true) {
                $fromConfig => 'config_asns',
                $verdict->isDatacenter => 'hosting_list',
                default => 'proxy_type',
            },
```

Update the "This blocks while `vpn_proxy_ip` only flags, and the asymmetry is the point" paragraph in its docblock — that asymmetry no longer exists. Replace with a sentence saying both block, and that this one catches hosting infrastructure while `vpn_proxy_ip` catches a person behind a tunnel.

- [ ] **Step 6: Register and seed**

In `app/Providers/FraudServiceProvider.php`, add to `RULES` in the pre-click block, immediately after `VpnProxyIpRule::class`:

```php
        TorIpRule::class,
        ResidentialProxyIpRule::class,
        IpRiskScoreRule::class,
```

plus the three `use` statements.

In `database/seeders/FraudRuleSeeder.php`, add the matching entries in the same position:

```php
        [
            'key' => 'tor_ip',
            'name' => 'Tor exit node',
            'description' => 'Click originates from a Tor exit node. Nobody completes a mobile install offer over Tor by accident.',
            'stage' => FraudStage::PreClick,
            'action' => FraudAction::Block,
            'score_weight' => 45,
            'config' => [],
        ],
        [
            'key' => 'residential_proxy_ip',
            'name' => 'Residential proxy',
            'description' => 'Rented access to somebody else\'s home connection — the standard transport for an install farm, because it defeats both the hosting-ASN list and a country check.',
            'stage' => FraudStage::PreClick,
            'action' => FraudAction::Block,
            'score_weight' => 40,
            'config' => [],
        ],
        [
            'key' => 'ip_risk_score',
            'name' => 'IP risk score',
            'description' => 'A third-party risk score over the threshold. Flags rather than blocks until the score has been calibrated against this platform\'s own traffic.',
            'stage' => FraudStage::PreClick,
            'action' => FraudAction::Flag,
            'score_weight' => 25,
            'config' => ['threshold' => 85],
        ],
```

And change the seeded `vpn_proxy_ip` entry to `FraudAction::Block`, `score_weight` 40, with the description rewritten to say it blocks at render and why.

- [ ] **Step 7: Write the promotion migration**

Create `database/migrations/2026_08_19_000003_promote_vpn_proxy_ip_to_block.php`:

```php
<?php

use App\Enums\FraudAction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * `FraudRuleSeeder` inserts missing rules and leaves existing rows alone,
     * so that it never resets a threshold an operator tuned. That is the right
     * behaviour and it is also why the seeder cannot make this change: every
     * database that has been seeded already holds `vpn_proxy_ip` at `flag`.
     *
     * Guarded on the current value. An operator who has already moved this rule
     * to `score` made a decision, and a migration is not the place to overrule
     * it.
     */
    public function up(): void
    {
        DB::table('fraud_rules')
            ->where('key', 'vpn_proxy_ip')
            ->where('action', FraudAction::Flag->value)
            ->update(['action' => FraudAction::Block->value, 'score_weight' => 40]);
    }

    public function down(): void
    {
        DB::table('fraud_rules')
            ->where('key', 'vpn_proxy_ip')
            ->where('action', FraudAction::Block->value)
            ->update(['action' => FraudAction::Flag->value, 'score_weight' => 20]);
    }
};
```

- [ ] **Step 8: Test the migration**

Append to `tests/Feature/Fraud/IpRulesTest.php`:

```php
it('promotes a seeded vpn rule to block and leaves a tuned one alone', function () {
    fraudRule('vpn_proxy_ip', FraudStage::PreClick, FraudAction::Flag, weight: 20);
    fraudRule('datacenter_ip', FraudStage::PreClick, FraudAction::Score, weight: 40);

    (require database_path('migrations/2026_08_19_000003_promote_vpn_proxy_ip_to_block.php'))->up();

    expect(FraudRule::query()->where('key', 'vpn_proxy_ip')->value('action'))->toBe(FraudAction::Block)
        ->and(FraudRule::query()->where('key', 'datacenter_ip')->value('action'))->toBe(FraudAction::Score);
});
```

with `use App\Models\FraudRule;` added to the imports.

- [ ] **Step 9: Run the tests**

Run: `php artisan test tests/Feature/Fraud/`
Expected: PASS. If `PipelineTest` fails on a changed `vpn_proxy_ip` expectation, update the expectation — the rule's behaviour genuinely changed.

- [ ] **Step 10: Lint, analyse, commit**

```bash
composer lint && composer analyse
git add app/Fraud app/Providers/FraudServiceProvider.php database/seeders/FraudRuleSeeder.php database/migrations tests/Feature/Fraud
git commit -m "feat(fraud): split the proxy signal into rules that price it differently

One boolean scored a Tor exit, a rented home connection and a privacy-minded
person on a consumer VPN identically, at 20. They are now four rules with four
weights, and vpn_proxy_ip is promoted to block on the operator's decision. The
seeder leaves existing rows alone by design, so a guarded migration flips it.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: The render gate

**Files:**
- Modify: `app/Services/Wall/WallContext.php`, `app/Services/Wall/WallGate.php`, `app/Http/Controllers/Wall/WallController.php`, `app/Models/Setting.php`, `lang/en/wall.php`, `lang/ar/wall.php`
- Test: `tests/Feature/Wall/RenderGateTest.php`

**Interfaces:**
- Consumes: `RenderGateRule` and the rules from Task 3.
- Produces: `WallContext->verdict` (`IpVerdict`, readonly). `WallGate::protectionBlock(WallContext $context): ?string` returning a notice key or null. `Setting::consentCountries(): array<int, string>`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Wall/RenderGateTest.php`:

```php
<?php

use App\Enums\FraudAction;
use App\Enums\FraudStage;
use App\Enums\ProxyType;
use App\Fraud\Contracts\IpIntelligence;
use App\Fraud\IpVerdict;
use App\Models\FraudEvent;
use App\Models\FraudRule;
use App\Providers\FraudServiceProvider;

require_once __DIR__.'/WallFixtures.php';

// `wallGet`, `wallClick`, `wallFixture`, `wallOffer` and `wallConsentCountries`
// all already live in WallFixtures.php — use them. `wallGet` in particular
// carries a real device User-Agent, which the wall filters on.

function gateVerdict(IpVerdict $verdict): void
{
    app()->register(FraudServiceProvider::class);

    app()->instance(IpIntelligence::class, new class($verdict) implements IpIntelligence
    {
        public function __construct(private readonly IpVerdict $verdict) {}

        public function inspect(string $ip, bool $consented = false): IpVerdict
        {
            return $this->verdict;
        }
    });
}

function gateRule(string $key, FraudAction $action = FraudAction::Block): FraudRule
{
    return FraudRule::query()->create([
        'key' => $key,
        'name' => $key,
        'description' => $key,
        'stage' => FraudStage::PreClick,
        'enabled' => true,
        'action' => $action,
        'score_weight' => 40,
        'config' => [],
    ]);
}

it('refuses the wall to a VPN and says so in a way the user can act on', function () {
    [$app] = wallFixture();
    gateVerdict(new IpVerdict(country: 'US', isProxy: true, proxyType: ProxyType::Vpn));
    gateRule('vpn_proxy_ip');

    $response = wallGet($app);

    $response->assertForbidden()
        ->assertSee(__('wall.notice.blocked_proxy.title'))
        // Never the rule key, never the ASN, never the provider.
        ->assertDontSee('vpn_proxy_ip');

    expect(FraudEvent::query()->where('rule_key', 'vpn_proxy_ip')->count())->toBe(1);
});

it('serves the wall when the same rule is only flagging', function () {
    [$app] = wallFixture();
    gateVerdict(new IpVerdict(country: 'US', isProxy: true, proxyType: ProxyType::Vpn));
    gateRule('vpn_proxy_ip', FraudAction::Flag);

    wallGet($app)->assertOk();
});

it('ignores a blocking rule that is not render-gate eligible', function () {
    [$app] = wallFixture();
    gateVerdict(new IpVerdict(country: 'US'));
    // Blocking, pre-click, and utterly unsuited to render: it would count a
    // tab open as a click.
    gateRule('click_velocity');

    wallGet($app)->assertOk();
});

it('sends an unconsented user to the consent screen rather than blocking them', function () {
    [$app] = wallFixture();
    wallConsentCountries(['DE']);
    gateVerdict(new IpVerdict(country: 'DE', isProxy: true, proxyType: ProxyType::Tor));
    gateRule('tor_ip');

    $response = wallGet($app);

    // The consent gate comes first: writing a fraud event here would record
    // this user's IP and end user ID before they had answered.
    $response->assertOk()->assertDontSee(__('wall.notice.blocked_proxy.title'));

    expect(FraudEvent::query()->count())->toBe(0);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/Wall/RenderGateTest.php`
Expected: FAIL — the wall renders normally; `assertForbidden` fails with 200.

- [ ] **Step 3: Add the notice copy**

In `lang/en/wall.php`, inside the `notice` array:

```php
        // The one place the fraud engine names what caught someone. It is a
        // deliberate exception to `Decision::publicReason()`: a fraud farm
        // running through a VPN already knows it is running through a VPN, so
        // the sentence teaches them nothing — while a real user does not know
        // their VPN is why the wall is empty, and one toggle makes them a
        // served user. Category only, never the rule, the ASN or the provider.
        'blocked_proxy' => [
            'title' => 'Turn off your VPN to continue',
            'body' => 'You appear to be connecting through a VPN or proxy. Disable it and reload this page to see your offers.',
        ],
        // No user-side fix exists for this one, so it does not pretend there is.
        'blocked_datacenter' => [
            'title' => 'Offers unavailable on this connection',
            'body' => 'Offers cannot be shown on this network. Try again from a mobile or home internet connection.',
        ],
        'blocked_risk' => [
            'title' => 'Offers unavailable on this connection',
            'body' => 'We could not verify this connection. Try again from a mobile or home internet connection.',
        ],
```

Add the same three keys to `lang/ar/wall.php`, translated, matching the tone of the entries already there.

- [ ] **Step 4: Carry the verdict on `WallContext`**

In `app/Services/Wall/WallContext.php`, add to the constructor after `$country`:

```php
        /**
         * The full IP verdict `country` was taken from. Carried rather than
         * re-inspected because the render gate, the eligibility filter and the
         * click all want it, and one request must reach one verdict — a second
         * lookup mid-request could straddle a cache expiry and disagree with
         * the country already rendered.
         */
        public readonly IpVerdict $verdict,
```

with `use App\Fraud\IpVerdict;` imported. Update `WallGate::context()` to pass it:

```php
        $verdict = $this->ipIntelligence->inspect($ip);
        $override = $this->countryOverride($request, $testMode);

        return new WallContext(
            app: $app,
            endUserId: $userId,
            ip: $ip,
            country: $override ?? $verdict->country,
            verdict: $verdict,
            // ... rest unchanged
```

Any other `new WallContext(...)` call site (check `tests/Feature/Wall/WallFixtures.php`) needs the new argument; pass `IpVerdict::unknown()` where a test does not care.

- [ ] **Step 5: Write `protectionBlock()`**

In `app/Services/Wall/WallGate.php`, add the import `use App\Fraud\Contracts\RenderGateRule;`, `use App\Fraud\FraudContext;`, `use App\Fraud\RuleRegistry;`, `use App\Models\FraudEvent;`, `use App\Models\FraudRule;`, `use App\Enums\FraudAction;`, `use App\Enums\FraudStage;`, inject `RuleRegistry $rules` in the constructor, and add:

```php
    /**
     * The last refusal before the offers table is touched — docs/05 §5.
     *
     * Reads the `fraud_rules` rows an admin already tunes rather than a second
     * set of toggles, so one signal is configured in one place. Only rules
     * marked `RenderGateRule` are eligible: their sole input is the verdict, so
     * running them here costs one array read and cannot misinterpret an
     * impression as a click.
     *
     * @return ?string A `wall.notice.*` key, or null to serve the wall.
     */
    public function protectionBlock(WallContext $context): ?string
    {
        $rows = FraudRule::query()
            ->where('stage', FraudStage::PreClick)
            ->where('action', FraudAction::Block)
            ->where('enabled', true)
            ->get();

        $fraud = new FraudContext(
            app: $context->app,
            endUserId: $context->endUserId,
            ip: $context->ip,
            country: $context->country,
            userAgent: $context->userAgent,
            referrer: $context->referrer,
        );

        foreach ($rows as $row) {
            $rule = $this->rules->resolve($row->key);

            if (! $rule instanceof RenderGateRule) {
                continue;
            }

            $result = $rule->evaluate($fraud, $row->config ?? []);

            if (! $result->triggered) {
                continue;
            }

            // Nothing blocks silently — docs/05 §1. One event per verdict TTL
            // rather than one per impression, because a blocked address is
            // served the notice and cached.
            FraudEvent::record($row, FraudStage::PreClick, $fraud, $result);

            return $rule::noticeKey();
        }

        return null;
    }
```

- [ ] **Step 6: Wire it into the controller, after consent and ban**

In `app/Http/Controllers/Wall/WallController.php`, immediately after the `isBanned` block and before the `$sort` line:

```php
        // Last, not first: a user with no consent decision belongs on the
        // consent screen, and a protection block written ahead of it would put
        // their IP and end user ID in a fraud_event before they answered.
        if ($blocked = $this->gate->protectionBlock($context)) {
            return $this->notice($blocked, $context, Response::HTTP_FORBIDDEN);
        }
```

Update the render-order comment at the top of the class docblock: step 9 becomes "IP protection gate", and the following steps renumber.

- [ ] **Step 7: Add `Setting::consentCountries()`**

In `app/Models/Setting.php`:

```php
    /**
     * Read in two places now — the wall's consent gate and the IP chain's
     * refusal to send an unconsented address to a third party. One decoder, so
     * the two cannot disagree about what the setting holds.
     *
     * @return list<string>
     */
    public static function consentCountries(): array
    {
        $raw = static::get('consent_required_countries');
        $countries = is_string($raw) ? json_decode($raw, true) : $raw;

        return is_array($countries) ? array_values(array_filter($countries, 'is_string')) : [];
    }
```

and rewrite `WallGate::consentRequired()` to use it:

```php
    public function consentRequired(?string $country): bool
    {
        return $country !== null && in_array($country, Setting::consentCountries(), true);
    }
```

- [ ] **Step 8: Run the tests**

Run: `php artisan test tests/Feature/Wall/ tests/Feature/Fraud/`
Expected: PASS.

- [ ] **Step 9: Lint, analyse, commit**

```bash
composer lint && composer analyse
git add app/Services/Wall app/Http/Controllers/Wall app/Models/Setting.php lang tests/Feature/Wall
git commit -m "feat(wall): refuse the wall to a blocked IP instead of a wall of dead offers

A user on a VPN used to get a full offer list, none of which would ever pay.
They now get one screen telling them to turn it off, which is the only fraud
message on this platform a real user can act on — and the reason it is safe to
show is that a fraud farm on a VPN already knows it is on a VPN.

The gate reads the fraud_rules rows an admin already tunes, so nothing is
configured twice, and it sits after the consent and ban steps so a blocked
render can never record an IP the consent gate has not cleared.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: `ip_providers`

**Files:**
- Create: `database/migrations/2026_08_19_000002_create_ip_providers_table.php`, `app/Models/IpProvider.php`, `database/seeders/IpProviderSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php` (call the new seeder alongside `FraudRuleSeeder`)
- Test: `tests/Feature/Fraud/IpProviderTest.php`

**Interfaces:**
- Produces: `IpProvider` model with `key, name, enabled, priority, api_key (encrypted), timeout_ms, daily_cap, ttl_seconds, fail_open, circuit_failures, circuit_cooldown_minutes, circuit_opened_at, last_error, last_error_at`. Methods: `IpProvider::active(): Collection` (enabled, configured, breaker closed, cap unspent, ordered by priority), `available(): bool`, `spent(): int`, `recordSuccess(): void`, `recordFailure(string $error): void`, `breakerOpen(): bool`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Fraud/IpProviderTest.php`:

```php
<?php

use App\Models\IpProvider;
use Illuminate\Support\Facades\Cache;

it('encrypts the api key at rest', function () {
    $provider = IpProvider::query()->create([
        'key' => 'proxycheck', 'name' => 'ProxyCheck.io', 'api_key' => 'secret-key',
    ]);

    expect($provider->fresh()->api_key)->toBe('secret-key')
        ->and(DB::table('ip_providers')->where('id', $provider->id)->value('api_key'))
        ->not->toBe('secret-key');
});

it('is unavailable until it is enabled and has a key', function () {
    $provider = IpProvider::query()->create(['key' => 'proxycheck', 'name' => 'P']);

    expect($provider->available())->toBeFalse();

    $provider->update(['enabled' => true]);
    expect($provider->available())->toBeFalse();

    $provider->update(['api_key' => 'k']);
    expect($provider->fresh()->available())->toBeTrue();
});

it('opens the breaker after consecutive failures and closes it after the cooldown', function () {
    $provider = IpProvider::query()->create([
        'key' => 'proxycheck', 'name' => 'P', 'enabled' => true, 'api_key' => 'k',
        'circuit_failures' => 2, 'circuit_cooldown_minutes' => 10,
    ]);

    $provider->recordFailure('timeout');
    expect($provider->breakerOpen())->toBeFalse();

    $provider->recordFailure('timeout');
    expect($provider->breakerOpen())->toBeTrue()
        ->and($provider->last_error)->toBe('timeout');

    // One success is enough: the breaker guards against a provider that is
    // down, not against one that is occasionally slow.
    $this->travel(11)->minutes();
    expect($provider->fresh()->breakerOpen())->toBeFalse();
});

it('stops being available once the daily cap is spent', function () {
    $provider = IpProvider::query()->create([
        'key' => 'proxycheck', 'name' => 'P', 'enabled' => true, 'api_key' => 'k', 'daily_cap' => 2,
    ]);

    $provider->recordSuccess();
    $provider->recordSuccess();

    expect($provider->spent())->toBe(2)
        ->and($provider->available())->toBeFalse();
});

it('orders active providers by priority and excludes the unavailable', function () {
    IpProvider::query()->create(['key' => 'a', 'name' => 'A', 'enabled' => true, 'api_key' => 'k', 'priority' => 20]);
    IpProvider::query()->create(['key' => 'b', 'name' => 'B', 'enabled' => true, 'api_key' => 'k', 'priority' => 10]);
    IpProvider::query()->create(['key' => 'c', 'name' => 'C', 'enabled' => false, 'api_key' => 'k', 'priority' => 1]);

    expect(IpProvider::active()->pluck('key')->all())->toBe(['b', 'a']);
});
```

Add `use Illuminate\Support\Facades\DB;` to the imports.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/Fraud/IpProviderTest.php`
Expected: FAIL — `Class "App\Models\IpProvider" not found`.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_08_19_000002_create_ip_providers_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A table rather than `settings` rows: each provider carries per-row live
     * state — breaker, last error, when — and a key/value store models that by
     * inventing composite keys nobody can query.
     *
     * Daily request counters are deliberately absent. They live in Redis: they
     * are written on every escalated lookup, and a table write on the click
     * path is the exact cost this whole design exists to avoid.
     */
    public function up(): void
    {
        Schema::create('ip_providers', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->boolean('enabled')->default(false);
            $table->unsignedTinyInteger('priority')->default(10);
            $table->text('api_key')->nullable();
            $table->unsignedSmallInteger('timeout_ms')->default(600);
            $table->unsignedInteger('daily_cap')->nullable();
            $table->unsignedInteger('ttl_seconds')->default(86400);
            $table->boolean('fail_open')->default(true);
            $table->unsignedTinyInteger('circuit_failures')->default(5);
            $table->unsignedTinyInteger('circuit_cooldown_minutes')->default(10);
            $table->unsignedTinyInteger('consecutive_failures')->default(0);
            $table->timestamp('circuit_opened_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamps();

            $table->index(['enabled', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_providers');
    }
};
```

- [ ] **Step 4: Write the model**

Create `app/Models/IpProvider.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * One third-party IP intelligence provider: its configuration, and the live
 * state that decides whether the chain is allowed to call it right now.
 *
 * Three independent brakes, because a paid API on the click path can fail in
 * three unrelated ways — it can be slow (timeout), it can be down (breaker),
 * and it can be expensive (daily cap). Any one of them skipping the provider
 * leaves the local verdict standing, which is the behaviour the platform had
 * before providers existed.
 *
 * @property string $key
 * @property bool $enabled
 * @property ?string $api_key
 */
class IpProvider extends Model
{
    protected $guarded = [];

    protected $casts = [
        'enabled' => 'boolean',
        'fail_open' => 'boolean',
        'api_key' => 'encrypted',
        'circuit_opened_at' => 'datetime',
        'last_error_at' => 'datetime',
    ];

    /**
     * Every provider the chain may call, cheapest brake first.
     *
     * @return Collection<int, self>
     */
    public static function active(): Collection
    {
        return static::query()
            ->where('enabled', true)
            ->orderBy('priority')
            ->get()
            ->filter(fn (self $provider): bool => $provider->available())
            ->values();
    }

    public function available(): bool
    {
        return $this->enabled
            && filled($this->api_key)
            && ! $this->breakerOpen()
            && ! $this->capSpent();
    }

    /**
     * Open means "this provider was down a moment ago, do not open a socket".
     * It closes on time rather than on a probe: a half-open probe on the click
     * path is one user paying the timeout to find out for everyone else.
     */
    public function breakerOpen(): bool
    {
        return $this->circuit_opened_at !== null
            && $this->circuit_opened_at->addMinutes((int) $this->circuit_cooldown_minutes)->isFuture();
    }

    public function capSpent(): bool
    {
        return $this->daily_cap !== null && $this->spent() >= (int) $this->daily_cap;
    }

    public function spent(): int
    {
        return (int) Cache::get($this->counterKey(), 0);
    }

    public function recordSuccess(): void
    {
        $this->countRequest();

        if ($this->consecutive_failures === 0 && $this->circuit_opened_at === null) {
            return;
        }

        $this->forceFill(['consecutive_failures' => 0, 'circuit_opened_at' => null])->save();
    }

    /**
     * A failure still counts against the cap. It cost a socket and, with most
     * vendors, a request — a brake that only counts successes is a brake a
     * failing provider can drive straight through.
     */
    public function recordFailure(string $error): void
    {
        $this->countRequest();

        $failures = (int) $this->consecutive_failures + 1;

        $this->forceFill([
            'consecutive_failures' => $failures,
            'circuit_opened_at' => $failures >= (int) $this->circuit_failures ? now() : $this->circuit_opened_at,
            'last_error' => $error,
            'last_error_at' => now(),
        ])->save();
    }

    private function countRequest(): void
    {
        // Two days, not one: the key is dated, so an entry only has to outlive
        // its own day, and a TTL exactly one day long expires mid-day for a
        // counter written at 23:59.
        Cache::add($key = $this->counterKey(), 0, now()->addDays(2));
        Cache::increment($key);
    }

    public function counterKey(): string
    {
        return "ip_provider:{$this->key}:".now()->utc()->toDateString();
    }
}
```

- [ ] **Step 5: Write the seeder**

Create `database/seeders/IpProviderSeeder.php`, following `FraudRuleSeeder`'s pattern exactly — a `RULES`-style const array, `firstOrCreate` on `key` so re-running never resets a configured provider:

```php
<?php

namespace Database\Seeders;

use App\Models\IpProvider;
use Illuminate\Database\Seeder;

/**
 * Both adapters, disabled and unconfigured.
 *
 * Rows are seeded rather than creatable from the panel for the same reason
 * fraud rules are: a row whose key has no driver class behind it does nothing,
 * silently, which is worse than not having it.
 *
 * Re-running inserts missing providers and leaves existing rows alone, so it
 * never clears a key or reverses a cap an operator set.
 */
class IpProviderSeeder extends Seeder
{
    /** @var list<array{key: string, name: string, priority: int, ttl_seconds: int}> */
    private const PROVIDERS = [
        // Free tier, no card, 1000 lookups a day — so it is the one that can be
        // switched on today, and it goes first.
        ['key' => 'proxycheck', 'name' => 'ProxyCheck.io', 'priority' => 10, 'ttl_seconds' => 86400],
        ['key' => 'ipqualityscore', 'name' => 'IPQualityScore', 'priority' => 20, 'ttl_seconds' => 86400],
    ];

    public function run(): void
    {
        foreach (self::PROVIDERS as $provider) {
            IpProvider::query()->firstOrCreate(['key' => $provider['key']], $provider);
        }
    }
}
```

Register it in `database/seeders/DatabaseSeeder.php` next to `FraudRuleSeeder`.

- [ ] **Step 6: Run the tests**

Run: `php artisan test tests/Feature/Fraud/IpProviderTest.php`
Expected: PASS.

- [ ] **Step 7: Lint, analyse, commit**

```bash
composer lint && composer analyse
git add database/migrations database/seeders app/Models/IpProvider.php tests/Feature/Fraud/IpProviderTest.php
git commit -m "feat(fraud): give a third-party IP provider somewhere to live

Configuration plus the live state that decides whether the chain may call it:
an encrypted key, a timeout, a daily cap and a circuit breaker. Three brakes
because a paid API on the click path fails in three unrelated ways — slow,
down, and expensive — and any one of them tripping must leave the local
verdict standing rather than the wall waiting.

Request counters stay in Redis. A table write per escalated lookup is the cost
this design exists to avoid.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: The two provider adapters

**Files:**
- Create: `app/Fraud/Providers/Contracts/IpProviderDriver.php`, `app/Fraud/Providers/AbstractIpProvider.php`, `app/Fraud/Providers/ProxyCheckProvider.php`, `app/Fraud/Providers/IpQualityScoreProvider.php`, `app/Fraud/Providers/IpProviderRegistry.php`
- Modify: `app/Providers/FraudServiceProvider.php`
- Test: `tests/Feature/Fraud/IpProviderDriverTest.php`

**Interfaces:**
- Consumes: `IpProvider` (Task 5), `IpVerdict` / `ProxyType` (Task 1).
- Produces: `IpProviderDriver::lookup(string $ip, IpProvider $config): ?IpVerdict` — null means "no answer, treat the verdict as degraded". `IpProviderRegistry::register(string $class)`, `resolve(string $key): ?IpProviderDriver`, `all(): array<string, class-string>`, mirroring `RuleRegistry`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Fraud/IpProviderDriverTest.php`:

```php
<?php

use App\Enums\ProxyType;
use App\Fraud\Providers\IpProviderRegistry;
use App\Fraud\Providers\IpQualityScoreProvider;
use App\Fraud\Providers\ProxyCheckProvider;
use App\Models\IpProvider;
use Illuminate\Support\Facades\Http;

function driverConfig(string $key, array $attributes = []): IpProvider
{
    return IpProvider::query()->create([
        'key' => $key, 'name' => $key, 'enabled' => true, 'api_key' => 'test-key', ...$attributes,
    ]);
}

it('maps a proxycheck answer onto a verdict', function () {
    Http::fake(['proxycheck.io/*' => Http::response([
        'status' => 'ok',
        '1.2.3.4' => [
            'asn' => 'AS24940', 'provider' => 'Hetzner', 'country' => 'Germany',
            'isocode' => 'DE', 'proxy' => 'yes', 'type' => 'VPN', 'risk' => 72,
        ],
    ])]);

    $verdict = app(ProxyCheckProvider::class)->lookup('1.2.3.4', driverConfig('proxycheck'));

    expect($verdict->country)->toBe('DE')
        ->and($verdict->asn)->toBe(24940)
        ->and($verdict->organization)->toBe('Hetzner')
        ->and($verdict->isProxy)->toBeTrue()
        ->and($verdict->proxyType)->toBe(ProxyType::Vpn)
        ->and($verdict->riskScore)->toBe(72)
        ->and($verdict->source)->toBe('proxycheck');
});

it('maps an ipqualityscore answer onto a verdict', function () {
    Http::fake(['ipqualityscore.com/*' => Http::response([
        'success' => true, 'country_code' => 'US', 'ASN' => 15169, 'ISP' => 'Google',
        'proxy' => true, 'vpn' => false, 'tor' => true, 'fraud_score' => 93,
    ])]);

    $verdict = app(IpQualityScoreProvider::class)->lookup('1.2.3.4', driverConfig('ipqualityscore'));

    expect($verdict->country)->toBe('US')
        ->and($verdict->proxyType)->toBe(ProxyType::Tor)
        ->and($verdict->riskScore)->toBe(93)
        ->and($verdict->source)->toBe('ipqualityscore');
});

it('returns null and records the failure when the provider errors', function () {
    Http::fake(['proxycheck.io/*' => Http::response('', 500)]);

    $config = driverConfig('proxycheck', ['circuit_failures' => 1]);
    $verdict = app(ProxyCheckProvider::class)->lookup('1.2.3.4', $config);

    expect($verdict)->toBeNull()
        ->and($config->fresh()->breakerOpen())->toBeTrue()
        ->and($config->fresh()->last_error)->not->toBeNull();
});

it('counts a successful lookup against the daily cap', function () {
    Http::fake(['proxycheck.io/*' => Http::response(['status' => 'ok', '1.2.3.4' => ['proxy' => 'no']])]);

    $config = driverConfig('proxycheck');
    app(ProxyCheckProvider::class)->lookup('1.2.3.4', $config);

    expect($config->spent())->toBe(1);
});

it('resolves a driver by key', function () {
    $registry = app(IpProviderRegistry::class);

    expect($registry->resolve('proxycheck'))->toBeInstanceOf(ProxyCheckProvider::class)
        ->and($registry->resolve('nope'))->toBeNull();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/Fraud/IpProviderDriverTest.php`
Expected: FAIL — driver classes not found.

- [ ] **Step 3: Write the contract and the registry**

Create `app/Fraud/Providers/Contracts/IpProviderDriver.php`:

```php
<?php

namespace App\Fraud\Providers\Contracts;

use App\Fraud\IpVerdict;
use App\Models\IpProvider;

/**
 * One vendor's API, reduced to the platform's own vocabulary.
 *
 * `null` is not an error to propagate — it is "this provider did not answer",
 * and the chain turns it into a degraded verdict rather than an exception. A
 * paid API is a convenience on the click path; it is never allowed to be a
 * dependency of it.
 */
interface IpProviderDriver
{
    public static function key(): string;

    public function lookup(string $ip, IpProvider $config): ?IpVerdict;
}
```

Create `app/Fraud/Providers/IpProviderRegistry.php` — a direct copy of `app/Fraud/RuleRegistry.php` with `Rule` swapped for `IpProviderDriver` and the docblock adjusted to say it mirrors `RuleRegistry` and `NetworkDriverManager`.

- [ ] **Step 4: Write the base adapter**

Create `app/Fraud/Providers/AbstractIpProvider.php`:

```php
<?php

namespace App\Fraud\Providers;

use App\Fraud\IpVerdict;
use App\Fraud\Providers\Contracts\IpProviderDriver;
use App\Models\IpProvider;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Everything both adapters do identically: spend the timeout, count the
 * request, trip the breaker, and never let a vendor's bad day reach a caller.
 *
 * A subclass supplies two things — where to send the request, and how to read
 * the answer. Everything a failure implies is handled here once, because a
 * second adapter that forgets to record a failure is a breaker that never
 * trips for that vendor.
 */
abstract class AbstractIpProvider implements IpProviderDriver
{
    /** @return array{string, array<string, mixed>} URL and query. */
    abstract protected function request(string $ip, IpProvider $config): array;

    /** @param array<string, mixed> $body */
    abstract protected function parse(string $ip, array $body): ?IpVerdict;

    public function lookup(string $ip, IpProvider $config): ?IpVerdict
    {
        [$url, $query] = $this->request($ip, $config);

        try {
            // No retry. A retry doubles the wait a wall render is already
            // holding for, and the breaker is the right answer to a provider
            // that needs one.
            $response = Http::timeout($config->timeout_ms / 1000)
                ->acceptJson()
                ->get($url, $query);
        } catch (Throwable $e) {
            // A timeout, a DNS failure, a refused connection. All the same
            // thing to a caller: no answer.
            $config->recordFailure(class_basename($e).': '.$e->getMessage());

            return null;
        }

        $verdict = $response->successful() ? $this->read($ip, $response) : null;

        if ($verdict === null) {
            $config->recordFailure($this->error($response));

            return null;
        }

        $config->recordSuccess();

        return $verdict;
    }

    private function read(string $ip, Response $response): ?IpVerdict
    {
        try {
            $body = $response->json();
        } catch (Throwable) {
            return null;
        }

        return is_array($body) ? $this->parse($ip, $body) : null;
    }

    private function error(Response $response): string
    {
        return $response->successful()
            ? 'unreadable response body'
            : "HTTP {$response->status()}";
    }
}
```

- [ ] **Step 5: Write `ProxyCheckProvider`**

Create `app/Fraud/Providers/ProxyCheckProvider.php`:

```php
<?php

namespace App\Fraud\Providers;

use App\Enums\ProxyType;
use App\Fraud\IpVerdict;
use App\Models\IpProvider;

/**
 * proxycheck.io. Free tier is 1000 lookups a day with no card, which is why it
 * seeds first — it is the provider an operator can switch on today.
 *
 * The response is keyed by the address queried, not by a fixed field name, and
 * `vpn=1&asn=1&risk=1` are opt-in flags rather than defaults.
 */
class ProxyCheckProvider extends AbstractIpProvider
{
    public static function key(): string
    {
        return 'proxycheck';
    }

    protected function request(string $ip, IpProvider $config): array
    {
        return ["https://proxycheck.io/v2/{$ip}", [
            'key' => $config->api_key,
            'vpn' => 3,     // classify the tunnel type rather than yes/no
            'asn' => 1,
            'risk' => 1,
        ]];
    }

    protected function parse(string $ip, array $body): ?IpVerdict
    {
        // `status` is `ok`, `warning`, `denied` or `error`. Only the first two
        // carry a record; the others are a key or quota problem, which is a
        // failure the breaker should see rather than a clean verdict.
        if (! in_array($body['status'] ?? null, ['ok', 'warning'], true)) {
            return null;
        }

        $record = $body[$ip] ?? null;

        if (! is_array($record)) {
            return null;
        }

        $proxy = ($record['proxy'] ?? 'no') === 'yes';
        $type = ProxyType::parse(self::normaliseType($record['type'] ?? null));

        return new IpVerdict(
            country: self::iso($record['isocode'] ?? null),
            asn: self::asn($record['asn'] ?? null),
            organization: is_string($record['provider'] ?? null) ? $record['provider'] : null,
            isDatacenter: $type === ProxyType::Datacenter,
            isProxy: $proxy,
            proxyType: $type,
            riskScore: is_numeric($record['risk'] ?? null) ? (int) $record['risk'] : null,
            source: self::key(),
        );
    }

    /**
     * proxycheck writes the class in prose; the enum wants IP2Proxy's codes.
     *
     * Only `Hosting` becomes `DCH`. `Business` is a corporate or office ISP
     * allocation, not cloud infrastructure — the same distinction the IPQS
     * adapter draws by excluding their `Corporate` connection type — and
     * mapping it here would set `isDatacenter` and hand a real office worker
     * to a rule that blocks at wall render. `Compromised Server` is a
     * different axis entirely: it records port-scan evidence of exposed
     * services, which says nothing about who owns the infrastructure, so it
     * is carried on `threat` instead (see `parse()`).
     *
     * Anything else falls through to null on purpose. An unmapped type leaves
     * the address judged on every other signal; a wrongly mapped one refuses
     * somebody the wall.
     */
    private static function normaliseType(mixed $type): ?string
    {
        return match (is_string($type) ? strtoupper($type) : null) {
            'VPN' => 'VPN',
            'TOR' => 'TOR',
            'HOSTING' => 'DCH',
            'RESIDENTIAL' => 'RES',
            'PUBLIC PROXY', 'SOCKS', 'SOCKS4', 'SOCKS5', 'HTTP', 'HTTPS' => 'PUB',
            'WEB PROXY' => 'WEB',
            default => null,
        };
    }

    private static function iso(mixed $value): ?string
    {
        return is_string($value) && strlen($value) === 2 ? strtoupper($value) : null;
    }

    /** `AS24940` — the prefix is theirs, the number is ours. */
    private static function asn(mixed $value): ?int
    {
        if (! is_string($value)) {
            return null;
        }

        $digits = ltrim($value, 'ASas');

        return ctype_digit($digits) ? (int) $digits : null;
    }
}
```

- [ ] **Step 6: Write `IpQualityScoreProvider`**

Create `app/Fraud/Providers/IpQualityScoreProvider.php`:

```php
<?php

namespace App\Fraud\Providers;

use App\Enums\ProxyType;
use App\Fraud\IpVerdict;
use App\Models\IpProvider;

/**
 * ipqualityscore.com. Paid, and the only source here that returns a calibrated
 * fraud score rather than a classification we score ourselves.
 *
 * It answers several overlapping booleans rather than one class, and they are
 * not mutually exclusive — a Tor exit is also a proxy. Read most specific
 * first, or every Tor node classifies as a plain proxy.
 */
class IpQualityScoreProvider extends AbstractIpProvider
{
    public static function key(): string
    {
        return 'ipqualityscore';
    }

    protected function request(string $ip, IpProvider $config): array
    {
        return ["https://ipqualityscore.com/api/json/ip/{$config->api_key}/{$ip}", [
            'strictness' => 1,
            // The wall's traffic is people on phones, not crawlers, and asking
            // for a mobile-aware answer avoids scoring carrier NAT as a proxy.
            'mobile' => 'true',
        ]];
    }

    protected function parse(string $ip, array $body): ?IpVerdict
    {
        if (($body['success'] ?? false) !== true) {
            return null;
        }

        $type = match (true) {
            ($body['tor'] ?? false) === true => ProxyType::Tor,
            ($body['vpn'] ?? false) === true => ProxyType::Vpn,
            ($body['proxy'] ?? false) === true => ProxyType::Public,
            default => null,
        };

        return new IpVerdict(
            country: is_string($body['country_code'] ?? null) ? strtoupper($body['country_code']) : null,
            asn: is_numeric($body['ASN'] ?? null) ? (int) $body['ASN'] : null,
            organization: is_string($body['ISP'] ?? null) ? $body['ISP'] : null,
            // Their own words for it. `connection_type` is the reliable one;
            // `host` and `organization` describe the owner, not the use.
            isDatacenter: ($body['connection_type'] ?? null) === 'Data Center',
            isProxy: ($body['proxy'] ?? false) === true,
            proxyType: $type,
            riskScore: is_numeric($body['fraud_score'] ?? null) ? (int) $body['fraud_score'] : null,
            source: self::key(),
        );
    }
}
```

- [ ] **Step 7: Register the drivers**

In `app/Providers/FraudServiceProvider.php`, add a singleton and a boot loop mirroring the rules:

```php
    /** @var list<class-string<IpProviderDriver>> */
    private const PROVIDERS = [
        ProxyCheckProvider::class,
        IpQualityScoreProvider::class,
    ];
```

in `register()`: `$this->app->singleton(IpProviderRegistry::class);`
in `boot()`, after the rules loop:

```php
        $providers = $this->app->make(IpProviderRegistry::class);

        foreach (self::PROVIDERS as $provider) {
            $providers->register($provider);
        }
```

- [ ] **Step 8: Run the tests**

Run: `php artisan test tests/Feature/Fraud/`
Expected: PASS.

- [ ] **Step 9: Lint, analyse, commit**

```bash
composer lint && composer analyse
git add app/Fraud/Providers app/Providers/FraudServiceProvider.php tests/Feature/Fraud/IpProviderDriverTest.php
git commit -m "feat(fraud): add the proxycheck and ipqualityscore adapters

Two vendors, one vocabulary. Each supplies where to send the request and how
to read the answer; the base class owns everything a failure implies, because
a second adapter that forgets to record one is a breaker that never trips for
that vendor.

Neither is allowed to be a dependency of the click path: an unreadable body, a
non-ok status and a timeout are the same thing to a caller — no answer.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: `ChainedIpIntelligence`

**Files:**
- Create: `app/Fraud/ChainedIpIntelligence.php`
- Modify: `app/Providers/FraudServiceProvider.php`, `app/Services/Wall/WallGate.php`
- Test: `tests/Feature/Fraud/ChainedIpIntelligenceTest.php`

**Interfaces:**
- Consumes: everything from Tasks 1, 5 and 6.
- Produces: `ChainedIpIntelligence` bound as `IpIntelligence`. `WallGate::context()` re-inspects with `consented: true` once a granted consent is in hand.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Fraud/ChainedIpIntelligenceTest.php`:

```php
<?php

use App\Enums\ProxyType;
use App\Fraud\ChainedIpIntelligence;
use App\Fraud\Contracts\IpIntelligence;
use App\Fraud\IpVerdict;
use App\Fraud\Providers\IpProviderRegistry;
use App\Models\IpProvider;
use App\Models\Setting;
use App\Providers\FraudServiceProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** A chain whose local half returns exactly what the test says. */
function chain(IpVerdict $local): ChainedIpIntelligence
{
    app()->register(FraudServiceProvider::class);

    $reader = new class($local) implements IpIntelligence
    {
        public function __construct(private readonly IpVerdict $verdict) {}

        public function inspect(string $ip, bool $consented = false): IpVerdict
        {
            return $this->verdict;
        }
    };

    return new ChainedIpIntelligence($reader, app(IpProviderRegistry::class));
}

function chainProvider(array $attributes = []): IpProvider
{
    return IpProvider::query()->create([
        'key' => 'proxycheck', 'name' => 'ProxyCheck.io',
        'enabled' => true, 'api_key' => 'k', ...$attributes,
    ]);
}

beforeEach(fn () => Cache::flush());

it('does not escalate when the local databases already answered', function () {
    Http::fake();
    chainProvider();

    $verdict = chain(new IpVerdict(country: 'US', isProxy: true, proxyType: ProxyType::Tor))->inspect('1.2.3.4');

    expect($verdict->proxyType)->toBe(ProxyType::Tor)
        ->and($verdict->source)->toBe('local');

    Http::assertNothingSent();
});

it('escalates when the local verdict looks clean and merges the answer', function () {
    Http::fake(['proxycheck.io/*' => Http::response([
        'status' => 'ok', '1.2.3.4' => ['proxy' => 'yes', 'type' => 'Residential', 'risk' => 88],
    ])]);
    chainProvider();

    $verdict = chain(new IpVerdict(country: 'US'))->inspect('1.2.3.4');

    expect($verdict->isProxy)->toBeTrue()
        ->and($verdict->proxyType)->toBe(ProxyType::Residential)
        ->and($verdict->riskScore)->toBe(88)
        ->and($verdict->country)->toBe('US');
});

it('never lets a provider clear a local positive', function () {
    Http::fake(['proxycheck.io/*' => Http::response([
        'status' => 'ok', '1.2.3.4' => ['proxy' => 'no', 'risk' => 2],
    ])]);
    chainProvider();

    // PUB is the vague class, so it escalates — and the provider disagrees.
    $verdict = chain(new IpVerdict(country: 'US', isProxy: true, proxyType: ProxyType::Public))->inspect('1.2.3.4');

    expect($verdict->isProxy)->toBeTrue()
        ->and($verdict->proxyType)->toBe(ProxyType::Public);
});

it('degrades on a provider failure and caches it for minutes not hours', function () {
    Http::fake(['proxycheck.io/*' => Http::response('', 500)]);
    chainProvider();

    $verdict = chain(new IpVerdict(country: 'US'))->inspect('1.2.3.4');

    expect($verdict->degraded)->toBeTrue()
        ->and($verdict->country)->toBe('US');

    // Five minutes, so one timeout cannot blind the paid layer for an hour.
    expect(Cache::get('ip:v2:1.2.3.4'))->toEqual($verdict);
});

it('rebuilds an expired merged verdict from the provider layer without paying again', function () {
    Http::fake(['proxycheck.io/*' => Http::response([
        'status' => 'ok', '1.2.3.4' => ['proxy' => 'yes', 'type' => 'VPN'],
    ])]);
    chainProvider();

    chain(new IpVerdict(country: 'US'))->inspect('1.2.3.4');
    Cache::forget('ip:v2:1.2.3.4');

    $verdict = chain(new IpVerdict(country: 'US'))->inspect('1.2.3.4');

    expect($verdict->proxyType)->toBe(ProxyType::Vpn);
    Http::assertSentCount(1);
});

it('refuses to send an unconsented consent-country address to a provider', function () {
    Http::fake();
    chainProvider();
    Setting::set('consent_required_countries', json_encode(['DE']));

    $verdict = chain(new IpVerdict(country: 'DE'))->inspect('1.2.3.4');

    expect($verdict->degraded)->toBeTrue()
        ->and($verdict->source)->toBe('local');

    Http::assertNothingSent();
});

it('escalates the same address once consent is given', function () {
    Http::fake(['proxycheck.io/*' => Http::response([
        'status' => 'ok', '1.2.3.4' => ['proxy' => 'yes', 'type' => 'VPN'],
    ])]);
    chainProvider();
    Setting::set('consent_required_countries', json_encode(['DE']));

    $chain = chain(new IpVerdict(country: 'DE'));
    $chain->inspect('1.2.3.4');
    $verdict = $chain->inspect('1.2.3.4', consented: true);

    expect($verdict->proxyType)->toBe(ProxyType::Vpn)
        ->and($verdict->degraded)->toBeFalse();
});

it('escalates an unplaced address, which is the case it is most worth it for', function () {
    Http::fake(['proxycheck.io/*' => Http::response([
        'status' => 'ok', '1.2.3.4' => ['proxy' => 'no', 'isocode' => 'EG'],
    ])]);
    chainProvider();
    Setting::set('consent_required_countries', json_encode(['DE']));

    expect(chain(IpVerdict::unknown())->inspect('1.2.3.4')->country)->toBe('EG');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/Fraud/ChainedIpIntelligenceTest.php`
Expected: FAIL — `Class "App\Fraud\ChainedIpIntelligence" not found`.

- [ ] **Step 3: Write the chain**

Create `app/Fraud/ChainedIpIntelligence.php`:

```php
<?php

namespace App\Fraud;

use App\Enums\ProxyType;
use App\Fraud\Contracts\IpIntelligence;
use App\Fraud\Providers\IpProviderRegistry;
use App\Models\IpProvider;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Local databases first, a paid provider only when they cannot answer.
 *
 * docs/05 §4 rules out a per-click API call: it adds latency to every click and
 * bills in proportion to the fraudulent traffic you are trying not to pay for.
 * This is the escape from that without abandoning it — the free layer answers
 * the addresses it can, and money is spent only on the ones it cannot.
 *
 * Three cache layers, because one TTL cannot serve them. Local country expires
 * hourly (mobile carrier IPs rotate, and a day of caching misattributes them).
 * A paid verdict expiring hourly would re-bill every address every hour. So the
 * raw provider answer is cached on its own for a day, the merged verdict for an
 * hour, and an expired merge is rebuilt from the provider layer for free.
 */
class ChainedIpIntelligence implements IpIntelligence
{
    /**
     * A verdict taken while a provider was unavailable caches for minutes.
     * Storing it for the full hour would mean one timeout blinds the paid layer
     * on that address until the TTL is up — the failure mode that makes a
     * fail-open design quietly stop working.
     */
    public const DEGRADED_TTL = 300;

    public function __construct(
        private readonly IpIntelligence $local,
        private readonly IpProviderRegistry $providers,
    ) {}

    public function inspect(string $ip, bool $consented = false): IpVerdict
    {
        $merged = Cache::get($key = "ip:v2:{$ip}");

        // A degraded verdict plus fresh consent is the one case worth redoing
        // early: the provider was withheld, not failed, and permission just
        // arrived.
        if ($merged instanceof IpVerdict && ! ($merged->degraded && $consented)) {
            return $merged;
        }

        $verdict = $this->build($ip, $consented);

        Cache::put($key, $verdict, $verdict->degraded ? self::DEGRADED_TTL : MaxMindIpIntelligence::TTL);

        return $verdict;
    }

    private function build(string $ip, bool $consented): IpVerdict
    {
        $verdict = $this->local->inspect($ip);

        if (! self::inconclusive($verdict)) {
            return $verdict;
        }

        if (! $this->mayEscalate($verdict, $consented)) {
            return $verdict->degraded();
        }

        foreach (IpProvider::active() as $config) {
            $answer = $this->ask($ip, $config);

            if ($answer === null) {
                $verdict = $verdict->degraded();

                continue;
            }

            $verdict = $verdict->merge($answer, $config->key);

            if (! self::inconclusive($verdict)) {
                break;
            }
        }

        return $verdict;
    }

    /**
     * The raw answer, cached on its own so an expired merge costs nothing.
     */
    private function ask(string $ip, IpProvider $config): ?IpVerdict
    {
        $key = "ip:provider:{$config->key}:{$ip}";
        $cached = Cache::get($key);

        if ($cached instanceof IpVerdict) {
            return $cached;
        }

        $driver = $this->providers->resolve($config->key);

        if ($driver === null) {
            return null;
        }

        $answer = $driver->lookup($ip, $config);

        if ($answer !== null) {
            Cache::put($key, $answer, (int) $config->ttl_seconds);
        }

        return $answer;
    }

    /**
     * Worth paying for a second opinion, or not.
     *
     * A clean local answer is exactly the answer a good residential proxy
     * produces, which is why "clean" counts as inconclusive here and a hit
     * does not. `PUB` is the vaguest IP2Proxy class — it does not separate a
     * compromised host from a shared exit — so it is worth resolving too.
     */
    private static function inconclusive(IpVerdict $verdict): bool
    {
        return $verdict->country === null
            || $verdict->proxyType === ProxyType::Public
            || (! $verdict->isProxy && ! $verdict->isDatacenter);
    }

    /**
     * Escalation ships an end user's IP address to a third party, and the
     * consent gate exists to stop exactly that happening before they agree.
     * `inspect()` receives an IP and nothing else, so it cannot read a consent
     * row — the caller that can holds `$consented`.
     *
     * An unplaced address escalates anyway. It is the case escalation is most
     * valuable for, and it matches the position the platform already takes:
     * `WallGate::consentRequired(null)` is false, so an address the databases
     * cannot place is not treated as consent-required anywhere else either.
     */
    private function mayEscalate(IpVerdict $verdict, bool $consented): bool
    {
        return $consented
            || $verdict->country === null
            || ! in_array($verdict->country, Setting::consentCountries(), true);
    }
}
```

- [ ] **Step 4: Bind it**

In `app/Providers/FraudServiceProvider.php`, replace the `IpIntelligence` binding:

```php
        // Wraps the reader rather than replacing it — the local databases still
        // answer every address they can, for free, before anything is spent.
        $this->app->bind(IpIntelligence::class, fn ($app): IpIntelligence => new ChainedIpIntelligence(
            $app->make(MaxMindIpIntelligence::class),
            $app->make(IpProviderRegistry::class),
        ));
```

Verify `LocalIpIntelligence`'s decorator in `AppServiceProvider` still wraps correctly — it extends whatever `IpIntelligence` resolves to, so it now wraps the chain. That is right: a dev machine's private address short-circuits before the chain, and a routable one goes through it.

- [ ] **Step 5: Re-inspect with consent in `WallGate`**

In `app/Services/Wall/WallGate.php`, add:

```php
    /**
     * The second half of the consent-before-escalation rule (docs/05 §4). The
     * first `inspect()` in `context()` cannot escalate for a consent-country
     * address because consent is not resolved yet — the country it returns is
     * what resolves it. Once a granted consent is in hand, this asks again with
     * permission. Outside a consent country it is a cache hit and costs nothing.
     */
    public function escalate(WallContext $context): WallContext
    {
        $verdict = $this->ipIntelligence->inspect($context->ip, consented: true);

        return new WallContext(...[...get_object_vars($context), 'verdict' => $verdict]);
    }
```

and call it in **both** controllers immediately after a granted consent is confirmed — in `WallController` before `isBanned`, and in `ClickController` at the end of its own consent block:

```php
            $context = $this->gate->escalate($context);
```

Both, not just the wall. A consent-country user whose merged verdict expired between render and click would otherwise be judged at click on degraded local-only data, and nothing on that path would ever retry the provider — the escalation would be permanently stuck one page behind.

Add a test for it in `tests/Feature/Wall/RenderGateTest.php`:

```php
it('escalates at click for a consent-country user whose verdict expired', function () {
    [$app, $network] = wallFixture();
    $offer = wallOffer($network);
    wallConsentCountries(['DE']);
    \App\Models\Consent::query()->create([
        'app_id' => $app->id, 'end_user_id' => 'USER_1', 'granted' => true,
    ]);

    $seen = [];
    app()->instance(IpIntelligence::class, new class($seen) implements IpIntelligence
    {
        /** @param array<int, bool> $seen */
        public function __construct(public array &$seen) {}

        public function inspect(string $ip, bool $consented = false): IpVerdict
        {
            $this->seen[] = $consented;

            return new IpVerdict(country: 'DE');
        }
    });

    wallClick($app, $offer);

    // The first call cannot escalate — the country it returns is what decides
    // whether consent was needed. The second one can.
    expect(app(IpIntelligence::class)->seen)->toContain(true);
});
```

- [ ] **Step 6: Run the whole suite**

Run: `php artisan test`
Expected: PASS. Existing wall and fraud tests that construct `IpIntelligence` fakes need the `$consented` parameter added to their `inspect()` signatures — fix them where they fail.

- [ ] **Step 7: Lint, analyse, commit**

```bash
composer lint && composer analyse
git add app/Fraud app/Providers/FraudServiceProvider.php app/Services/Wall/WallGate.php app/Http/Controllers/Wall/WallController.php tests
git commit -m "feat(fraud): escalate an inconclusive address to a paid provider

docs/05 rules out a per-click API call, and it is right to: it bills in
proportion to the fraud you are trying not to pay for. This is the escape
without abandoning the rule — the free databases answer what they can, and
money is spent only on what they cannot, which is also the answer a good
residential proxy is designed to produce.

Three cache layers because one TTL cannot serve them: local country must
expire hourly, a paid verdict expiring hourly re-bills every address every
hour, and an expired merge rebuilds from the provider layer for free.

Escalation stops at the consent gate. inspect() gets an IP and nothing else,
so the caller that knows about consent passes the permission in, defaulting to
denied so no existing call site can leak an address by omission.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 8: The Protection page

**Files:**
- Create: `app/Filament/Admin/Pages/Protection.php`, `resources/views/filament/admin/pages/protection.blade.php`
- Test: `tests/Feature/Panel/ProtectionPageTest.php`

**Interfaces:**
- Consumes: `IpProvider` (Task 5), `IpProviderRegistry` (Task 6), `MaxMindIpIntelligence::DATABASES` and `::file()`, `RenderGateRule` implementations (Task 3).
- Produces: nothing other tasks depend on.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Panel/ProtectionPageTest.php` — the directory and the helpers seventeen
existing panel tests already use. `pestphp/pest-plugin-livewire` is NOT installed, so
`Livewire::test()` is the entry point, not the `livewire()` function. Read
`tests/Feature/Panel/BlacklistFormTest.php` first and match its arrangement.

```php
<?php

use App\Enums\UserRole;
use App\Filament\Admin\Pages\Protection;
use App\Models\IpProvider;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    // A Livewire component tested outside a request has no panel, and every
    // Filament URL helper it touches then resolves against no routes.
    Filament::setCurrentPanel('admin');

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
});

it('saves provider settings and never echoes the key back', function () {
    $provider = IpProvider::query()->create(['key' => 'proxycheck', 'name' => 'ProxyCheck.io']);

    Livewire::test(Protection::class)
        ->fillForm(['providers' => [['id' => $provider->id, 'enabled' => true, 'api_key' => 'new-key', 'timeout_ms' => 900]]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($provider->fresh()->enabled)->toBeTrue()
        ->and($provider->fresh()->api_key)->toBe('new-key')
        ->and($provider->fresh()->timeout_ms)->toBe(900);
});

it('reports which local databases are present', function () {
    expect(collect(app(Protection::class)->getDatabasesProperty())->pluck('label'))
        ->toContain('Country', 'ASN', 'Proxy');
});

it('tests an address against the local reader without writing the shared cache', function () {
    Http::fake();

    Livewire::test(Protection::class)
        ->set('testIp', '8.8.8.8')
        ->call('runTest')
        ->assertHasNoErrors();

    // An operator testing a suspicious address must not thereby install a
    // verdict that then governs real traffic.
    expect(cache()->has('ip:v2:8.8.8.8'))->toBeFalse();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/Panel/ProtectionPageTest.php`
Expected: FAIL — `Class "App\Filament\Admin\Pages\Protection" not found`.

- [ ] **Step 3: Write the page**

Create `app/Filament/Admin/Pages/Protection.php`. Follow `app/Filament/Admin/Pages/Settings.php` exactly for the form/content/`InteractsWithFormActions` scaffolding — read it first and mirror its structure. The distinct parts:

```php
<?php

namespace App\Filament\Admin\Pages;

use App\Enums\FraudAction;
use App\Enums\FraudStage;
use App\Fraud\Contracts\RenderGateRule;
use App\Fraud\MaxMindIpIntelligence;
use App\Fraud\Providers\IpProviderRegistry;
use App\Models\AuditLog;
use App\Models\FraudRule;
use App\Models\IpProvider;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Number;

/**
 * One page for everything that decides whether an address may see a wall —
 * the spec's §9.
 *
 * The four sections answer four different questions and are on one page
 * because an operator asking one of them is usually about to ask another:
 * which providers are running and are they healthy, are the local databases
 * fresh, what does this specific address look like, and what would we do
 * about it.
 */
class Protection extends Page
{
    use InteractsWithFormActions;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|\UnitEnum|null $navigationGroup = 'Risk';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.admin.pages.protection';

    /** @var array<string, mixed> */
    public array $data = [];

    public string $testIp = '';

    /** @var list<array<string, mixed>>|null */
    public ?array $testResults = null;

    public function mount(): void
    {
        $this->getSchema('form')?->fill([
            'providers' => IpProvider::query()->orderBy('priority')->get()->map(fn (IpProvider $p): array => [
                'id' => $p->id,
                'name' => $p->name,
                'enabled' => $p->enabled,
                'priority' => $p->priority,
                'api_key' => $p->api_key,
                'timeout_ms' => $p->timeout_ms,
                'daily_cap' => $p->daily_cap,
                'ttl_seconds' => $p->ttl_seconds,
                'fail_open' => $p->fail_open,
            ])->all(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Providers')
                ->description('A paid lookup is spent only on an address the local databases cannot judge. Three brakes — timeout, breaker, daily cap — and any of them tripping leaves the local verdict standing.')
                ->schema([
                    Repeater::make('providers')
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false)
                        ->itemLabel(fn (array $state): string => (string) ($state['name'] ?? ''))
                        ->columns(4)
                        ->schema([
                            // Required. A Repeater dehydrates only the
                            // components it declares, so without this the id
                            // filled in `mount()` never comes back and `save()`
                            // silently updates nothing.
                            Hidden::make('id'),
                            Toggle::make('enabled'),
                            TextInput::make('priority')->numeric()->required()->minValue(1)->maxValue(255)
                                ->helperText('Ascending. First conclusive answer wins.'),
                            TextInput::make('api_key')->password()->revealable()->maxLength(191),
                            TextInput::make('timeout_ms')->label('Timeout')->numeric()->required()->suffix('ms')->minValue(100)->maxValue(5000)
                                ->helperText('This sits on the wall render path. Above a second is a slow offerwall.'),
                            TextInput::make('daily_cap')->numeric()->minValue(1)
                                ->helperText('Empty means uncapped, which is a decision rather than a default.'),
                            TextInput::make('ttl_seconds')->label('Cache lifetime')->numeric()->required()->minValue(3600)
                                ->helperText('A day is right: proxy status changes on weekly timescales, and an hour re-bills every address every hour.'),
                            Toggle::make('fail_open')
                                ->helperText('On, a failed lookup leaves the local verdict standing. Off refuses the address.'),
                        ]),
                ]),
        ]);
    }

    public function save(): void
    {
        $rows = (array) ($this->getSchema('form')?->getState()['providers'] ?? []);

        foreach ($rows as $row) {
            $provider = IpProvider::query()->find($row['id'] ?? null);

            if ($provider === null) {
                continue;
            }

            $before = $provider->only(['enabled', 'priority', 'timeout_ms', 'daily_cap', 'ttl_seconds', 'fail_open']);

            $provider->update([
                'enabled' => (bool) ($row['enabled'] ?? false),
                'priority' => (int) $row['priority'],
                'api_key' => $row['api_key'] ?: null,
                'timeout_ms' => (int) $row['timeout_ms'],
                'daily_cap' => $row['daily_cap'] === null || $row['daily_cap'] === '' ? null : (int) $row['daily_cap'],
                'ttl_seconds' => (int) $row['ttl_seconds'],
                'fail_open' => (bool) ($row['fail_open'] ?? false),
            ]);

            // The key is never in the audit trail, only the fact it moved.
            AuditLog::record('ip_provider.updated', $provider, $before, $provider->only(array_keys($before)));
        }

        Notification::make()->title('Protection settings saved')->success()->send();
    }

    /**
     * Live state next to the form, because an enabled and quiet provider must
     * look different at a glance from an enabled and failing one.
     *
     * @return list<array<string, mixed>>
     */
    public function getProviderStatesProperty(): array
    {
        return IpProvider::query()->orderBy('priority')->get()->map(fn (IpProvider $p): array => [
            'name' => $p->name,
            'enabled' => $p->enabled,
            'configured' => filled($p->api_key),
            'spent' => $p->spent(),
            'cap' => $p->daily_cap,
            'breaker' => $p->breakerOpen() ? 'open until '.$p->circuit_opened_at?->addMinutes((int) $p->circuit_cooldown_minutes)->diffForHumans() : 'closed',
            'last_error' => $p->last_error,
            'last_error_at' => $p->last_error_at?->diffForHumans(),
            'ok' => $p->available() || ! $p->enabled,
        ])->all();
    }

    /**
     * @return list<array{label: string, value: string, ok: bool}>
     */
    public function getDatabasesProperty(): array
    {
        $rows = [];

        foreach (MaxMindIpIntelligence::DATABASES as $label => $file) {
            $path = MaxMindIpIntelligence::file($file);
            $present = is_file($path);

            $rows[] = [
                'label' => $label,
                'value' => $present
                    ? Number::fileSize(filesize($path) ?: 0).', updated '.\Carbon\CarbonImmutable::createFromTimestamp(filemtime($path) ?: 0)->diffForHumans()
                    : 'missing',
                'ok' => $present,
            ];
        }

        return $rows;
    }

    public function updateDatabasesAction(): Action
    {
        return Action::make('updateDatabases')
            ->label('Update now')
            ->requiresConfirmation()
            ->modalDescription('Downloads roughly 1.5 GB. The wall keeps serving from the current files while it runs.')
            ->action(function (): void {
                Artisan::queue('geoip:update');

                Notification::make()->title('Database refresh queued')->success()->send();
            });
    }

    /**
     * Direct to each provider, and nothing written to the merged `ip:v2:` key:
     * the answer must be what the provider says now rather than what it said an
     * hour ago, and an operator testing a suspicious address must not thereby
     * install a verdict that then governs real traffic.
     *
     * The local reader does populate its own `ip:local:v2:` entry, which is
     * harmless — it is derived from files on disk and identical to what the
     * next real request would compute anyway.
     */
    public function runTest(): void
    {
        $ip = trim($this->testIp);

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            Notification::make()->title('Not an IP address')->danger()->send();

            return;
        }

        $results = [];
        $started = microtime(true);
        $local = (new MaxMindIpIntelligence)->inspect($ip);
        $results[] = self::row('Local databases', $local, $started);

        foreach (IpProvider::query()->where('enabled', true)->orderBy('priority')->get() as $config) {
            $driver = app(IpProviderRegistry::class)->resolve($config->key);

            if ($driver === null) {
                continue;
            }

            $started = microtime(true);
            $answer = $driver->lookup($ip, $config);

            $results[] = $answer === null
                ? ['source' => $config->name, 'error' => $config->fresh()?->last_error ?? 'no answer', 'ms' => self::ms($started)]
                : self::row($config->name, $answer, $started);
        }

        $this->testResults = $results;
    }

    /** @return array<string, mixed> */
    private static function row(string $source, \App\Fraud\IpVerdict $verdict, float $started): array
    {
        return [
            'source' => $source,
            'country' => $verdict->country,
            'asn' => $verdict->asn,
            'organization' => $verdict->organization,
            'proxy_type' => $verdict->proxyType?->value,
            'usage_type' => $verdict->usageType,
            'threat' => $verdict->threat,
            'risk' => $verdict->riskScore,
            'ms' => self::ms($started),
        ];
    }

    private static function ms(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }

    /**
     * The rules this page's signals feed, with their fire counts — the same
     * data as the Fraud Rules resource, filtered to the family, so tuning a
     * proxy threshold does not mean leaving the page.
     *
     * @return list<array<string, mixed>>
     */
    public function getRulesProperty(): array
    {
        $registry = app(\App\Fraud\RuleRegistry::class);

        return FraudRule::query()
            ->where('stage', FraudStage::PreClick)
            ->orderBy('key')
            ->get()
            ->filter(fn (FraudRule $rule): bool => $registry->resolve($rule->key) instanceof RenderGateRule)
            ->map(fn (FraudRule $rule): array => [
                'key' => $rule->key,
                'name' => $rule->name,
                'action' => $rule->action->value,
                'weight' => $rule->score_weight,
                'enabled' => $rule->enabled,
                'gates_render' => $rule->enabled && $rule->action === FraudAction::Block,
            ])
            ->values()
            ->all();
    }
}
```

- [ ] **Step 4: Write the view**

Create `resources/views/filament/admin/pages/protection.blade.php`. Mirror `health.blade.php`'s card grid for the database and provider-state blocks, and print `{{ $this->content }}` for the form, as `settings.blade.php` does. Structure:

```blade
<x-filament-panels::page>
    {{ $this->content }}

    <x-filament::section heading="Provider status">
        <div class="grid gap-3 sm:grid-cols-2">
            @foreach ($this->providerStates as $state)
                {{-- card: name, enabled/configured dot, "N / cap today", breaker, last error --}}
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section heading="Local databases">
        {{ $this->updateDatabasesAction }}
        <div class="grid gap-3 sm:grid-cols-3">
            @foreach ($this->databases as $database)
                {{-- same card markup as health.blade.php --}}
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section heading="Test an address">
        <div class="flex gap-2">
            <input type="text" wire:model="testIp" placeholder="8.8.8.8" class="fi-input">
            <x-filament::button wire:click="runTest">Test</x-filament::button>
        </div>

        @if ($this->testResults)
            <table>{{-- source, country, ASN, organisation, proxy type, usage, threat, risk, ms --}}</table>
        @endif
    </x-filament::section>

    <x-filament::section heading="IP rules">
        <table>{{-- key, name, action, weight, enabled, "gates render" --}}</table>
    </x-filament::section>
</x-filament-panels::page>
```

Fill in the card and table markup by copying the classes used in `health.blade.php` — do not invent a new visual language for this page.

- [ ] **Step 5: Run the tests**

Run: `php artisan test tests/Feature/Panel/ProtectionPageTest.php`
Expected: PASS.

- [ ] **Step 6: Lint, analyse, commit**

```bash
composer lint && composer analyse
git add app/Filament/Admin/Pages/Protection.php resources/views/filament/admin/pages/protection.blade.php tests/Feature/Panel/ProtectionPageTest.php
git commit -m "feat(admin): one page for everything that decides who sees a wall

Providers with their live state, because an enabled and quiet provider has to
look different at a glance from an enabled and failing one. The local database
ages, with the refresh attached rather than one green line away on Health. A
test tool that calls each provider directly and writes nothing, so an operator
checking a suspicious address does not thereby install a verdict that governs
real traffic. And the IP rules inline, so tuning a threshold is not a trip to
another page.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 9: Make the documentation tell the truth

**Files:**
- Modify: `docs/05-fraud-engine.md`, `docs/03-offerwall.md`, `docs/07-admin-panel.md`, `.env.example`, `config/playndgain.php`

**Interfaces:** none. This task ships no behaviour.

**Why it is a task and not a footnote:** four places in this codebase currently argue for the defaults this work reverses. A comment that defends behaviour the code no longer has is worse than no comment — the next person trusts it.

- [ ] **Step 1: Rewrite the `docs/05` rule tables**

In `docs/05-fraud-engine.md` §3, pre-click table: change `vpn_proxy_ip` to `block`, and add rows for `tor_ip` (block), `residential_proxy_ip` (block) and `ip_risk_score` (flag, `{threshold: 85}`). Update the `datacenter_ip` row to mention the `DCH` proxy-type trigger alongside the ASN list.

- [ ] **Step 2: Replace the VPN-versus-datacenter subsection**

Delete the subsection "On `datacenter_ip` being `block` while `vpn_proxy_ip` is `flag`" — the asymmetry it explains no longer exists. Replace it with a subsection titled "On blocking a VPN at render" carrying the reasoning from the spec §7: blocking at render is a different refusal from blocking at click, because the user is told what to change and one toggle makes them a served user; and the reason showing that sentence is safe is that a fraud farm on a VPN already knows it is on a VPN.

- [ ] **Step 3: Rewrite `docs/05` §4**

Replace the `IpIntelligence` code sample with the chain: local first, escalation on an inconclusive verdict, the three cache layers and their TTLs, the three brakes, and the consent rule. Delete the closing line "An optional paid provider (proxycheck.io, IPQS) is a config toggle, off by default... Not built in v1 beyond the interface seam" — it is built now. Add the `ip_providers` table to the section.

- [ ] **Step 4: Update `docs/03` render order**

In `docs/03-offerwall.md` §2, add the IP protection gate to the numbered render pipeline after the banned-user step, and say why it sits there rather than earlier: a protection block ahead of the consent gate would record an IP the consent gate has not cleared.

- [ ] **Step 5: Add the Protection page to `docs/07`**

A short section describing the four blocks and what each answers, matching the density of the Health and Settings sections already there.

- [ ] **Step 6: Verify no stale rationale survives**

Run: `grep -rn "flags rather than blocks\|only flags\|asymmetry is the point\|not built in v1" app docs`
Expected: no matches. Anything that remains is a comment defending behaviour that no longer exists — fix it.

- [ ] **Step 7: Commit**

```bash
git add docs config .env.example
git commit -m "docs(fraud): retire the rationale for defaults this work reverses

Four places argued that vpn_proxy_ip should flag while datacenter_ip blocks,
and one said a paid provider was not built beyond the interface seam. Both
were true last week. A comment defending behaviour the code no longer has is
worse than no comment, because the next person trusts it.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Self-Review

**Spec coverage**

| Spec section | Task |
|---|---|
| §3 chain, escalation condition, merge rule | 7 |
| §3 failure and cost controls (timeout, breaker, cap) | 5, 6 |
| §3 degraded verdicts, short TTL | 1, 7 |
| §3 two cache layers | 1, 7 |
| §3 consent before escalation, `$consented` parameter, re-escalation at render **and** click | 1, 4, 7 |
| §3 `ip:v2:` key versioning | 1 |
| §4 extended `IpVerdict`, `ProxyType` enum, `"-"` → null, no `fraudScore` | 1, 2 |
| §5 render gate, `WallContext->verdict`, `RenderGateRule`, notice mapping | 4 |
| §5 `datacenter_ip` and `vpn_proxy_ip` behaviour change called out | 3, 9 |
| §5 fraud event at render, consent exception | 4 |
| §6 block screen copy and its limits | 4 |
| §7 three new rules, narrowed VPN rule, `DCH` trigger, promotion migration | 3 |
| §8 `ip_providers` table, encrypted key, seeded, Redis counters, audit log | 5, 8 |
| §9 four page sections | 8 |
| §10 test matrix | every task |
| §11 non-goals | not implemented, by design |

No gaps.

**Type consistency**

- `inspect(string $ip, bool $consented = false): IpVerdict` — used identically in Tasks 1, 4, 7 and in every test fake.
- `IpVerdict::remember(string $key, int $ttl, Closure $lookup)` — key-first everywhere from Task 1 onward.
- `RenderGateRule::noticeKey()` static — declared in Task 3, consumed in Tasks 4 and 8.
- `IpProviderDriver::lookup(string $ip, IpProvider $config): ?IpVerdict` — Task 6 defines, Tasks 7 and 8 consume.
- `IpProvider::active()`, `available()`, `spent()`, `breakerOpen()`, `recordSuccess()`, `recordFailure(string)` — Task 5 defines, Tasks 6, 7, 8 consume.
- `Setting::consentCountries()` — Task 4 defines, Task 7 consumes.
- Cache keys `ip:v2:`, `ip:local:v2:`, `ip:provider:{key}:`, `ip_provider:{key}:{date}` — consistent across Tasks 1, 5, 7, 8.

**Ordering note:** Task 5's migration is timestamped `000002` and Task 3's `000003`, so `ip_providers` exists before the rule promotion runs. Tasks 3 and 5 are otherwise independent and may be built in either order.
