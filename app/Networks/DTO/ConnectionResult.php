<?php

namespace App\Networks\DTO;

/**
 * Result of the admin panel's "Test connection" button.
 */
final readonly class ConnectionResult
{
    public function __construct(
        public bool $ok,
        public string $message,
        public ?int $offerCount = null,
    ) {}

    public static function ok(string $message, ?int $offerCount = null): self
    {
        return new self(true, $message, $offerCount);
    }

    public static function fail(string $message): self
    {
        return new self(false, $message);
    }
}
