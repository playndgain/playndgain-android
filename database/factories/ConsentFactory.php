<?php

namespace Database\Factories;

use App\Models\App;
use App\Models\Consent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * `Consent` carries no `HasFactory` trait — the model belongs to the offerwall
 * phase and the models are frozen — so this is used as `ConsentFactory::new()`.
 *
 * @extends Factory<Consent>
 */
class ConsentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'app_id' => App::factory(),
            'end_user_id' => 'USER_'.fake()->numberBetween(100, 9999),
            'granted' => true,
            'purposes' => ['fraud_prevention', 'reward_attribution', 'network_delivery'],
            'ip' => fake()->ipv4(),
            'country' => 'DE',
            'user_agent_hash' => hash('sha256', fake()->userAgent()),
        ];
    }

    public function declined(): static
    {
        return $this->state(fn () => ['granted' => false]);
    }
}
