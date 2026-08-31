<?php

namespace App\Fraud;

use App\Fraud\Contracts\IpIntelligence;

/**
 * The default binding until the MaxMind and IP2Proxy databases are on disk.
 *
 * It places nobody and accuses nobody, which pushes the decision onto the
 * consumers of an unknown country. **The eligibility filter treats a null
 * country as fail-closed: only worldwide offers are served.** Showing a
 * DE-targeted offer to an address we cannot place generates a chargeback, and
 * a chargeback costs more than an unshown offer. Test mode's `country`
 * parameter is how a publisher previews a geo in the meantime.
 */
class NullIpIntelligence implements IpIntelligence
{
    public function inspect(string $ip, bool $consented = false): IpVerdict
    {
        // Cached anyway, so swapping in the real reader changes one binding and
        // no call site. One hour: mobile carrier IPs rotate, and a day of
        // caching would misattribute them.
        return IpVerdict::remember("ip:local:v2:{$ip}", MaxMindIpIntelligence::TTL, fn (): IpVerdict => IpVerdict::unknown());
    }
}
