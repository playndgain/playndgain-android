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
            // The address a credit note prints. Complete by default: a payout
            // destination cannot be made default without it.
            'billing_address' => fake()->streetAddress(),
            'billing_city' => fake()->city(),
            'billing_postal_code' => fake()->postcode(),
            'status' => PublisherStatus::Approved,
            'rev_share' => 70.00,
            'hold_days' => null,
            'min_payout' => 20.00,
            'balance' => 0,
            'lifetime_earnings' => 0,
            'approved_at' => now(),
        ];
    }

    /** A signup that has not filled in its billing address yet. */
    public function withoutBilling(): static
    {
        return $this->state(fn () => [
            'company_name' => null,
            'billing_address' => null,
            'billing_city' => null,
            'billing_postal_code' => null,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => PublisherStatus::Pending,
            'approved_at' => null,
        ]);
    }
}
