<?php

namespace Database\Factories;

use App\Enums\AppPlatform;
use App\Enums\AppStatus;
use App\Enums\PostbackMethod;
use App\Models\App;
use App\Models\Publisher;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<App>
 */
class AppFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'publisher_id' => Publisher::factory(),
            'name' => fake()->words(2, true),
            'url' => fake()->url(),
            'platform' => AppPlatform::All,
            'status' => AppStatus::Active,
            'currency_name' => 'Coins',
            'currency_rate' => 1000.0000,
            'postback_url' => null,
            'postback_method' => PostbackMethod::GET,
            'require_signed_wall' => false,
            'anonymize_ip' => false,
            'approved_at' => now(),
        ];
    }
}
