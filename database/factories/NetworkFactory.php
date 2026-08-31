<?php

namespace Database\Factories;

use App\Enums\NetworkIntegration;
use App\Enums\NetworkStatus;
use App\Models\Network;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Network>
 */
class NetworkFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'driver' => Str::slug($name),
            'name' => $name,
            'status' => NetworkStatus::Active,
            'credentials' => ['api_key' => Str::random(32)],
            // A network with no allowlist cannot accept a postback at all, so
            // the default carries the loopback address every test request
            // arrives from. Use `withoutIpAllowlist()` to exercise the refusal.
            // The product default is `allow_any_postback_ip = true`; tests keep
            // the gate on so an empty or foreign list still refuses.
            'postback_ips' => ['127.0.0.1'],
            'allow_any_postback_ip' => false,
            'priority' => 0,
            'sync_interval_minutes' => 60,
            'offers_count' => 0,
            'integration' => NetworkIntegration::Api,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn () => [
            'status' => NetworkStatus::Disabled,
            'credentials' => null,
        ]);
    }

    public function withoutIpAllowlist(): static
    {
        return $this->state(fn () => ['postback_ips' => []]);
    }

    public function allowingAnyPostbackIp(): static
    {
        return $this->state(fn () => [
            'postback_ips' => [],
            'allow_any_postback_ip' => true,
        ]);
    }
}
