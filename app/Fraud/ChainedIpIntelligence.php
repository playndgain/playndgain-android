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

        $providers = IpProvider::active();

        if ($providers->isEmpty() && self::anyProviderEnabled()) {
            // Every enabled provider is currently braked or capped, which is
            // one of the degraded conditions IpVerdict::$degraded names. Left
            // un-degraded, this would cache clean for the full hour and blind
            // the paid layer on this address for up to that TTL past the
            // cooldown — exactly what DEGRADED_TTL exists to bound. An
            // install with nothing enabled at all is different: there is
            // nothing to retry, so the clean verdict still caches for the
            // full hour.
            return $verdict->degraded();
        }

        foreach ($providers as $config) {
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

    /** Distinguishes "nothing is configured" from "everything is braked or capped". */
    private static function anyProviderEnabled(): bool
    {
        return IpProvider::query()->where('enabled', true)->exists();
    }
}
