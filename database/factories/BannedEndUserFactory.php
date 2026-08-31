<?php

namespace Database\Factories;

use App\Enums\BannedBy;
use App\Models\BannedEndUser;
use App\Models\Publisher;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Used as `BannedEndUserFactory::new()` — the model has no `HasFactory` trait.
 *
 * @extends Factory<BannedEndUser>
 */
class BannedEndUserFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'publisher_id' => Publisher::factory(),
            // Null = every app this publisher owns.
            'app_id' => null,
            'end_user_id' => 'USER_'.fake()->numberBetween(100, 9999),
            'banned_by' => BannedBy::Publisher,
            'reason' => 'Fraudulent activity',
            'expires_at' => null,
        ];
    }

    /** An auto-ban that has run out. The wall must serve this user again. */
    public function expired(): static
    {
        return $this->state(fn () => [
            'banned_by' => BannedBy::System,
            'expires_at' => now()->subDay(),
        ]);
    }
}
