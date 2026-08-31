<?php

namespace Database\Factories;

use App\Enums\ConversionStatus;
use App\Models\App;
use App\Models\Conversion;
use App\Models\Offer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Conversion>
 */
class ConversionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $revenue = fake()->randomFloat(6, 0.05, 12);
        $revShare = 70.00;
        $payout = round($revenue * $revShare / 100, 6);

        return [
            'trans_id' => 'OFFER'.fake()->unique()->numberBetween(10000000, 99999999).'-'.Str::random(16),
            'network_txid' => (string) fake()->unique()->numberBetween(10000000, 99999999),
            'app_id' => App::factory(),
            'publisher_id' => fn (array $attributes) => App::withoutGlobalScopes()
                ->findOrFail($attributes['app_id'])->publisher_id,
            'offer_id' => Offer::factory(),
            'network_id' => fn (array $attributes) => Offer::findOrFail($attributes['offer_id'])->network_id,
            'end_user_id' => 'user_'.fake()->numberBetween(1, 9999),
            'offer_name' => fake()->words(3, true),
            'revenue' => $revenue,
            'payout' => $payout,
            'rev_share_applied' => $revShare,
            'currency_amount' => round($payout * 1000, 4),
            'status' => ConversionStatus::Approved,
            'is_test' => false,
            'clears_at' => now()->copy()->startOfMonth()->addMonth(),
        ];
    }
}
