<?php

namespace App\Fraud;

use App\Models\App;
use App\Models\Conversion;
use App\Models\Offer;
use App\Models\OfferClick;

/**
 * Everything a rule is allowed to see, for either stage.
 *
 * docs/03 calls this ClickContext and docs/05 calls it FraudContext; they are
 * the same object seen from two ends of the pipeline, so it is one class with
 * the later fields nullable. A pre-click rule gets no conversion, and a
 * post-conversion rule gets the click that was resolved for it.
 */
final class FraudContext
{
    public function __construct(
        public readonly App $app,
        public readonly string $endUserId,
        public readonly ?string $ip = null,
        public readonly ?string $country = null,
        public readonly ?string $userAgent = null,
        public readonly ?string $device = null,
        public readonly ?string $os = null,
        public readonly ?string $browser = null,
        public readonly ?string $referrer = null,
        public readonly ?Offer $offer = null,
        public readonly ?OfferClick $click = null,
        public readonly ?Conversion $conversion = null,
        /** USD. Present on the post-conversion stage. */
        public readonly ?string $payout = null,
        /**
         * The address the *conversion* arrived from, taken only from the
         * network's postback and never from the click.
         *
         * `ConversionProcessor` resolves `$dto->ip ?? $click->ip` for the
         * context's `$ip`, which is right for everything that wants "the best
         * address we have" — but it means a post-conversion rule reading `$ip`
         * cannot tell the completer's address from a fallback copy of the
         * clicker's. That distinction is the whole point of
         * `conversion_proxy_ip`: a shared tracking URL is completed by someone
         * we never saw at click, and re-inspecting the clicker's address would
         * report on the wrong person.
         *
         * Null means the network sent no address, which is no evidence rather
         * than suspicion — both post-conversion IP rules pass on it.
         */
        public readonly ?string $conversionIp = null,
        /**
         * The verdict already resolved for this request, when the caller has
         * one. `WallGate::protectionBlock()` passes `WallContext::$verdict`
         * here so the five IP-family rules can reuse it instead of calling
         * `IpIntelligence::inspect()` again — one request reaches one
         * verdict rather than one lookup per rule. Null, as it is at click
         * time, means a rule falls back to `inspect()` and hits the cache.
         */
        public readonly ?IpVerdict $verdict = null,
        /**
         * The native SDK's build string, when the request came from one. Read
         * by `EmulatorDeviceRule` alongside the User-Agent and never instead of
         * it — see `WallContext::$deviceHint`.
         */
        public readonly ?string $deviceHint = null,
    ) {}

    public function publisherId(): int
    {
        return (int) $this->app->publisher_id;
    }

    public function appId(): int
    {
        return (int) $this->app->id;
    }

    /** The row a fraud event hangs off — the click pre-click, the conversion after. */
    public function subject(): OfferClick|Conversion|null
    {
        return $this->conversion ?? $this->click;
    }

    public function with(mixed ...$overrides): self
    {
        return new self(...[...get_object_vars($this), ...$overrides]);
    }
}
