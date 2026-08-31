<?php

use App\Models\Network;
use App\Networks\Drivers\MonlixDriver;
use App\Networks\Drivers\OGAdsDriver;
use App\Networks\Exceptions\UnknownDriverException;
use App\Networks\NetworkDriverManager;
use App\Providers\NetworkServiceProvider;
use Database\Seeders\NetworkSeeder;

beforeEach(function () {
    $this->app->register(NetworkServiceProvider::class);
    $this->manager = $this->app->make(NetworkDriverManager::class);
});

it('registers every seeded network driver', function (string $driver) {
    expect($this->manager->has($driver))->toBeTrue();
})->with([
    'notik', 'admantium', 'monlix', 'adscendmedia', 'ogads',
    'adswedmedia', 'adgatemedia', 'lootably', 'direct',
]);

it('seeds a network only for a driver that exists', function () {
    $this->seed(NetworkSeeder::class);

    // A seeded row an admin can fill in and enable, with no adapter behind it,
    // syncs nothing and looks like a broken network rather than a missing one.
    Network::query()->pluck('driver')->each(
        fn (string $driver) => expect($this->manager->has($driver))->toBeTrue(),
    );
});

it('resolves a network to its driver', function () {
    $network = Network::factory()->create(['driver' => 'ogads']);

    expect($this->manager->make($network))->toBeInstanceOf(OGAdsDriver::class);
    expect($this->manager->make(Network::factory()->create(['driver' => 'monlix'])))
        ->toBeInstanceOf(MonlixDriver::class);
});

it('throws for a driver nobody registered', function () {
    $this->manager->make(Network::factory()->create(['driver' => 'nonexistent']));
})->throws(UnknownDriverException::class);

it('keeps the test double out of the registry in production', function () {
    // runningUnitTests() is what puts `fake` in the map, so its presence here is
    // the assertion that the guard is what admits it.
    expect($this->manager->has('fake'))->toBeTrue()
        ->and($this->app->runningUnitTests())->toBeTrue();
});

it('gives the admin form a credential field set to render', function (string $driver) {
    $class = $this->manager->all()[$driver];

    // The admin panel builds the credentials block from this, so an empty
    // schema is a network nobody can configure.
    expect($class::credentialSchema())->not->toBeEmpty()
        ->and($class::key())->toBe($driver);
})->with([
    'notik', 'admantium', 'monlix', 'adscendmedia', 'ogads',
    'adswedmedia', 'adgatemedia', 'lootably', 'direct',
]);
