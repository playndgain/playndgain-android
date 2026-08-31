<?php

namespace App\Networks;

use App\Models\Network;
use App\Networks\Contracts\NetworkDriver;
use App\Networks\Exceptions\UnknownDriverException;

class NetworkDriverManager
{
    /**
     * Driver key => class. Keys match `networks.driver` and the postback route
     * segment. Registering a driver here is step 7 of the checklist in
     * docs/02-network-adapters.md §6.
     *
     * @var array<string, class-string<NetworkDriver>>
     */
    protected array $drivers = [];

    /**
     * @param  class-string<NetworkDriver>  $class
     */
    public function register(string $key, string $class): void
    {
        $this->drivers[$key] = $class;
    }

    public function make(Network $network): NetworkDriver
    {
        $class = $this->drivers[$network->driver]
            ?? throw new UnknownDriverException($network->driver);

        return new $class($network);
    }

    public function has(string $key): bool
    {
        return isset($this->drivers[$key]);
    }

    /**
     * @return array<string, class-string<NetworkDriver>>
     */
    public function all(): array
    {
        return $this->drivers;
    }
}
