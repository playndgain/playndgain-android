<?php

namespace App\Networks\DTO;

/**
 * The one offer shape the rest of the application sees.
 *
 * Normalization is the driver's job, not the sync job's. Countries arrive
 * uppercase ISO-2 or the driver throws. Payout arrives as a USD float or the
 * driver converts it. "United States" never reaches this class — "US" does.
 */
final readonly class OfferDTO
{
    /**
     * @param  string  $trackingUrl  Must contain the driver's subid macro.
     * @param  float  $payout  USD, what the network pays us.
     * @param  list<string>  $countries  ISO-2, uppercase. [] = worldwide.
     * @param  list<string>  $devices  android|ios|desktop. [] = all.
     * @param  list<string>  $categories
     * @param  list<array{name: string, payout: float}>  $goals  Steps of a multi-reward offer, USD, in the order the user completes them.
     */
    public function __construct(
        public string $externalId,
        public string $name,
        public string $trackingUrl,
        public float $payout,
        public ?string $description = null,
        public ?string $instructions = null,
        public ?string $iconUrl = null,
        public ?string $previewUrl = null,
        public array $countries = [],
        public array $devices = [],
        public ?string $osMinVersion = null,
        public array $categories = [],
        public string $conversionType = 'cpa',
        public bool $isMultiReward = false,
        public array $goals = [],
        public ?int $dailyCap = null,
    ) {}
}
