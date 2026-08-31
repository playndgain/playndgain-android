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
        /**
         * A threat classification from whichever layer produced the verdict —
         * IP2Proxy's `SPAM`, `BOTNET`, `SCANNER` from the local database, or a
         * provider's own, currently `COMPROMISED_SERVER` from ProxyCheck. A
         * union, not a closed set. No rule reads it yet.
         */
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
     *
     * `proxyType` carries a second, narrower exception: a local `PUB` is
     * replaced by a provider's more specific class, because `PUB` is not a
     * positive to protect — it is IP2Proxy's vaguest class, the one that does
     * not separate a compromised host from a shared exit, and the entire
     * reason `ChainedIpIntelligence::inconclusive()` escalates on it is to
     * refine it into something a rule can act on. Every other local class
     * still wins untouched.
     */
    public function merge(self $other, string $source): self
    {
        return new self(
            country: $this->country ?? $other->country,
            asn: $this->asn ?? $other->asn,
            organization: $this->organization ?? $other->organization,
            isDatacenter: $this->isDatacenter || $other->isDatacenter,
            isProxy: $this->isProxy || $other->isProxy,
            proxyType: $this->proxyType !== null && $this->proxyType !== ProxyType::Public
                ? $this->proxyType
                : ($other->proxyType ?? $this->proxyType),
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
     * `MaxMindIpIntelligence::inspect()`'s cache seam, so the local layer
     * cannot drift on shape or on the guard below. **It takes a key, not an
     * IP** — there are three layers with three TTLs (`ip:v2:` merged,
     * `ip:local:v2:` local, `ip:provider:{key}:` raw) and one entry cannot
     * serve them all: local country must expire hourly because carrier IPs
     * rotate, while a paid verdict expiring hourly re-bills every address
     * every hour.
     *
     * The other two layers do not go through this method.
     * `ChainedIpIntelligence::inspect()` manages the merged `ip:v2:` entry
     * itself, and its private `ask()` manages the raw `ip:provider:{key}:`
     * entry itself — both need a conditional rebuild (a degraded verdict plus
     * fresh consent) and a conditional TTL (`DEGRADED_TTL` vs. the full hour)
     * that this method's unconditional "cached or looked up" shape cannot
     * express. Both re-implement the `instanceof self` guard below by hand for
     * exactly that reason.
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
