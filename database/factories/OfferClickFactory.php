<?php

namespace Database\Factories;

use App\Enums\DeviceType;
use App\Models\App;
use App\Models\Offer;
use App\Models\OfferClick;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The click a postback has to resolve back to.
 *
 * `OfferClick` carries no `HasFactory` trait — the model belongs to the
 * offerwall phase — so this is used as `OfferClickFactory::new()`.
 *
 * @extends Factory<OfferClick>
 */
class OfferClickFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'app_id' => App::factory(),
            'publisher_id' => fn (array $attributes) => App::withoutGlobalScopes()
                ->findOrFail($attributes['app_id'])->publisher_id,
            'offer_id' => Offer::factory(),
            'network_id' => fn (array $attributes) => Offer::findOrFail($attributes['offer_id'])->network_id,
            'end_user_id' => 'USER_'.fake()->numberBetween(100, 9999),
            'ip' => fake()->ipv4(),
            'country' => 'US',
            'user_agent' => fake()->userAgent(),
            'device_type' => DeviceType::Android,
            'risk_score' => 0,
            'blocked' => false,
            'is_test' => false,
            'created_at' => now(),
        ];
    }

    public function test(): static
    {
        return $this->state(fn () => ['is_test' => true]);
    }
}
