<?php

namespace App\Fraud\Contracts;

use App\Fraud\IpVerdict;

/**
 * Local databases, never a per-click API call — a paid lookup on the click path
 * adds latency to every click and a bill proportional to the fraudulent traffic
 * you are trying not to pay for (docs/05 §4).
 */
interface IpIntelligence
{
    /**
     * `$consented` is permission to send this address to a third party. It
     * defaults to false so a caller that has not thought about consent cannot
     * leak an IP by omission — see ChainedIpIntelligence, the only
     * implementation that reads it.
     */
    public function inspect(string $ip, bool $consented = false): IpVerdict;
}
