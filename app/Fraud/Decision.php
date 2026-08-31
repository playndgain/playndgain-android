<?php

namespace App\Fraud;

final readonly class Decision
{
    /**
     * @param  int  $score  0–100
     * @param  ?string  $reason  Internal rule key. Never shown to an end user.
     * @param  list<string>  $triggered  Every rule that fired, blocking or not.
     */
    public function __construct(
        public bool $blocked,
        public int $score,
        public ?string $reason,
        public array $triggered = [],
    ) {}

    public static function clean(): self
    {
        return new self(false, 0, null);
    }

    /**
     * Never leak the rule that caught them — it teaches a fraud farm exactly
     * what to change. Publishers get a coarse category, end users get nothing.
     */
    public function publicReason(): string
    {
        return 'unavailable';
    }
}
