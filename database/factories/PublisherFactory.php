<?php

namespace Database\Factories;

use App\Enums\PublisherStatus;
use App\Models\Publisher;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Publisher>
 */
class PublisherFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'company_name' => fake()->company(),
            'country' => fake()->randomElement(['EG', 'US', 'DE', 'BR', 'IN']),
            'status' => PublisherStatus::Approved,
            'rev_share' => 70.00,
            'hold_days' => null,
            'min_payout' => 20.00,
            'balance' => 0,
            'lifetime_earnings' => 0,
            'approved_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => PublisherStatus::Pending,
            'approved_at' => null,
        ]);
    }
}
