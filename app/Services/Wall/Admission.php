<?php

namespace App\Services\Wall;

/**
 * The outcome of steps 7–9 for one request — docs/03 §2.
 *
 * `refusal` is null when the user may see offers. Otherwise it is `consent`
 * (no decision yet), `declined`, `banned`, or the `wall.notice.*` key a render
 * gate rule returned. One value object so the HTML wall and the SDK API turn
 * the same decision into their own response, and never decide it twice.
 */
final class Admission
{
    public function __construct(
        public readonly WallContext $context,
        public readonly ?string $refusal = null,
    ) {}

    public function admitted(): bool
    {
        return $this->refusal === null;
    }
}
