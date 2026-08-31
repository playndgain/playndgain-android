<?php

namespace Database\Factories;

use App\Enums\ConversionType;
use App\Enums\OfferStatus;
use App\Models\Network;
use App\Models\Offer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Offer>
 */
class OfferFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'network_id' => Network::factory(),
            'external_id' => (string) fake()->unique()->numberBetween(100000, 999999),
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'instructions' => 'Reach level 10 within 7 days',
            'tracking_url' => 'https://example.test/click?offer={offer_id}&subid={click_id}',
            'payout' => fake()->randomFloat(6, 0.05, 12),
            'conversion_type' => ConversionType::Cpi,
            'countries' => ['EG', 'US'],
            'devices' => ['android', 'ios'],
            'is_multi_reward' => false,
            'status' => OfferStatus::Active,
            'is_featured' => false,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }

    public function worldwide(): static
    {
        return $this->state(fn () => ['countries' => []]);
    }
}
