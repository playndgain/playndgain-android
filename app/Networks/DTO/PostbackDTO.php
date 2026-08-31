<?php

namespace App\Networks\DTO;

/**
 * A parsed inbound postback, normalized across every network's format.
 */
final readonly class PostbackDTO
{
    /**
     * @param  string  $networkTxId  The idempotency key, unique with network_id.
     * @param  ?string  $clickId  Our UUID, echoed back through the subid macro.
     * @param  float  $payout  USD. Negative on a reversal.
     * @param  string  $status  credited|reversed|rejected|pending
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $networkTxId,
        public ?string $clickId,
        public float $payout,
        public string $status,
        public ?string $offerExternalId = null,
        public ?string $offerName = null,
        public ?string $endUserId = null,
        public ?string $ip = null,
        public array $raw = [],
    ) {}

    /**
     * The same postback with the click supplied by hand.
     *
     * Used by the admin panel's "match to click" action, where the network
     * truncated or dropped our subid and a human supplies it from the click
     * log — docs/07 §8.
     */
    public function withClickId(string $clickId): self
    {
        return new self(
            networkTxId: $this->networkTxId,
            clickId: $clickId,
            payout: $this->payout,
            status: $this->status,
            offerExternalId: $this->offerExternalId,
            offerName: $this->offerName,
            endUserId: $this->endUserId,
            ip: $this->ip,
            raw: $this->raw,
        );
    }

    public function isCredit(): bool
    {
        return $this->status === 'credited';
    }

    public function isReversal(): bool
    {
        return $this->status === 'reversed';
    }
}
