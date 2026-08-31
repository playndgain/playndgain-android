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
