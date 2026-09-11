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
            // Mirrors the identity migration's backfill, so every test that
            // posts to `/postback/{driver}` keeps resolving without being
            // rewritten. A platform account gets a random key instead — see
            // `platform()`.
            'postback_key' => fn (array $attributes): string => Str::substr((string) ($attributes['driver'] ?? Str::random(32)), 0, 64),
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

    /**
     * A second, third, nth account on a connector that hosts many.
     *
     * The default key is the driver, which is exactly what a platform cannot
     * have: two rows on the same driver would collide on the unique key and on
     * the callback URL. Both halves of the identity are set together because
     * `account_scope` is what the composite unique index reads.
     */
    public function platform(string $driver): static
    {
        return $this->state(function () use ($driver): array {
            $key = Str::lower(Str::random(32));

            return [
                'driver' => $driver,
                'postback_key' => $key,
                'account_scope' => $key,
            ];
        });
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
