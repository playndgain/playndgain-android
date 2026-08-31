<?php

namespace App\Fraud\Providers;

use App\Fraud\Providers\Contracts\IpProviderDriver;

/**
 * Key => class, mirroring RuleRegistry and NetworkDriverManager. Concrete
 * drivers register themselves from FraudServiceProvider.
 */
class IpProviderRegistry
{
    /** @var array<string, class-string<IpProviderDriver>> */
    protected array $providers = [];

    /**
     * @param  class-string<IpProviderDriver>  $class
     */
    public function register(string $class): void
    {
        $this->providers[$class::key()] = $class;
    }

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    public function resolve(string $key): ?IpProviderDriver
    {
        $class = $this->providers[$key] ?? null;

        return $class === null ? null : app($class);
    }

    /**
     * @return array<string, class-string<IpProviderDriver>>
     */
    public function all(): array
    {
        return $this->providers;
    }
}
