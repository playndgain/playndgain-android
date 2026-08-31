<?php

namespace Database\Factories;

use App\Enums\BlacklistType;
use App\Models\Blacklist;
use App\Models\Publisher;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Used as `BlacklistFactory::new()` — the model has no `HasFactory` trait.
 *
 * A null `app_id` means the row applies to every app the publisher owns; the
 * states below take the value the wall filter compares against, which differs
 * per type (offer ID, ISO-2 code, device type, network id or driver).
 *
 * @extends Factory<Blacklist>
 */
class BlacklistFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'publisher_id' => Publisher::factory(),
            'app_id' => null,
            'type' => BlacklistType::Country,
            'value' => 'DE',
            'reason' => null,
        ];
    }

    public function offer(int $offerId): static
    {
        return $this->state(fn () => ['type' => BlacklistType::Offer, 'value' => (string) $offerId]);
    }

    public function country(string $code): static
    {
        return $this->state(fn () => ['type' => BlacklistType::Country, 'value' => strtoupper($code)]);
    }

    public function device(string $device): static
    {
        return $this->state(fn () => ['type' => BlacklistType::Device, 'value' => $device]);
    }

    public function network(string $idOrDriver): static
    {
        return $this->state(fn () => ['type' => BlacklistType::Network, 'value' => $idOrDriver]);
    }
}
