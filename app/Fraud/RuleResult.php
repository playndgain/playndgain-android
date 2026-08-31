<?php

namespace App\Fraud;

final readonly class RuleResult
{
    /**
     * @param  array<string, mixed>  $context  The inputs the rule saw. This is
     *                                         what makes a block explainable
     *                                         later without re-running it.
     */
    public function __construct(
        public bool $triggered,
        public string $reason = '',
        public array $context = [],
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public static function trigger(string $reason, array $context = []): self
    {
        return new self(true, $reason, $context);
    }

    public static function pass(): self
    {
        return new self(false);
    }
}
