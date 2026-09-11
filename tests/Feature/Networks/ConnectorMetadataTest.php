<?php

use App\Enums\ConnectorCategory;
use App\Filament\Admin\Resources\Networks\NetworkResource;
use App\Networks\Connector;
use App\Networks\Drivers\AffiseDriver;
use App\Networks\Drivers\DirectDriver;
use App\Networks\Drivers\FakeNetworkDriver;
use App\Networks\Drivers\OGAdsDriver;
use App\Networks\Drivers\SwaarmDriver;
use App\Networks\NetworkDriverManager;
use App\Providers\NetworkServiceProvider;
use Tests\Support\PlatformTestDriver;

beforeEach(function () {
    $this->app->register(NetworkServiceProvider::class);
    $this->manager = $this->app->make(NetworkDriverManager::class);
});

it('gives every registered driver a connector with a human label', function () {
    foreach ($this->manager->all() as $key => $class) {
        $connector = $class::connector();

        expect($connector)->toBeInstanceOf(Connector::class)
            // The whole point of the label is that it is not the machine key.
            ->and($connector->label)->not->toBe('')
            ->and($connector->label)->not->toBe($key);
    }
});

it('fills a partner mark from the file on disk and the house override', function () {
    expect(OGAdsDriver::connector()->website)->toBe('https://ogads.com')
        ->and(OGAdsDriver::connector()->formDefaults('ogads'))->toBe([
            'name' => 'OGAds',
            'website' => 'https://ogads.com',
            'logo_path' => 'img/networks/ogads.png',
        ])
        ->and(DirectDriver::connector()->logoPathFor('direct'))->toBe('img/networks/house.svg')
        ->and(SwaarmDriver::connector()->logoPathFor('swaarm'))->toBe('img/networks/swaarm.svg')
        ->and(AffiseDriver::connector()->logoPathFor('affise'))->toBe('img/networks/affise.svg');
});

it('gives every listed connector a mark on disk', function () {
    foreach ($this->manager->all() as $key => $class) {
        $connector = $class::connector();

        if (! $connector->listedInAdmin) {
            continue;
        }

        expect($connector->logoPathFor($key))->not->toBeNull("{$key} has no mark");
    }
});

it('treats the house channel and the test double as internal, everything else as a partner', function () {
    expect(DirectDriver::connector()->category)->toBe(ConnectorCategory::Internal)
        ->and(DirectDriver::connector()->label)->toBe('PLAYNDGAIN (house)')
        ->and(FakeNetworkDriver::connector()->category)->toBe(ConnectorCategory::Internal)
        ->and(FakeNetworkDriver::connector()->listedInAdmin)->toBeFalse()
        ->and(OGAdsDriver::connector()->category)->toBe(ConnectorCategory::Direct)
        ->and(OGAdsDriver::connector()->label)->toBe('OGAds');
});

it('holds one account per direct connector and never reverses a late rejection', function () {
    // Both flags are read outside app/Networks/ — the create form refuses a
    // second row on a single-account connector, and the reconciler decides from
    // the second whether a `rejected` after a credit takes money back. Only the
    // platform connectors may say yes to either; a per-partner integration
    // saying yes by accident would claw back paid conversions.
    foreach ($this->manager->all() as $key => $class) {
        $connector = $class::connector();

        if ($connector->category === ConnectorCategory::Platform) {
            expect($connector->supportsMultipleAccounts)->toBeTrue("{$key} is a platform that holds one account");

            continue;
        }

        expect($connector->supportsMultipleAccounts)->toBeFalse("{$key} claims multiple accounts")
            ->and($connector->rejectedAfterCreditReverses)->toBeFalse("{$key} claims a reversing rejection");
    }
});

it('registers both platform connectors', function () {
    expect($this->manager->has('swaarm'))->toBeTrue()
        ->and($this->manager->has('affise'))->toBeTrue()
        ->and(SwaarmDriver::connector()->category)->toBe(ConnectorCategory::Platform)
        ->and(AffiseDriver::connector()->category)->toBe(ConnectorCategory::Platform)
        // Skylup is the same API as Swaarm with the host hard-coded, and it
        // stays a direct integration with one partner.
        ->and($this->manager->all()['skylup']::connector()->category)->toBe(ConnectorCategory::Direct);
});

it('flattens connectors for the create-page cards in the same category order', function () {
    $withoutDirect = array_keys(NetworkResource::driverConnectors(false));
    $withDirect = array_keys(NetworkResource::driverConnectors(true));

    expect($withoutDirect)->toBe(['swaarm', 'affise', 'direct'])
        ->and($withoutDirect)->not->toContain('fake')
        ->and($withDirect[0])->toBe('swaarm')
        ->and($withDirect)->toContain('ogads')
        ->and(array_search('direct', $withDirect, true))->toBeLessThan(array_search('ogads', $withDirect, true));
});

it('groups the driver select with the reusable connectors before the per-partner ones', function () {
    $withDirect = NetworkResource::driverOptions(true);
    $withoutDirect = NetworkResource::driverOptions(false);

    expect(array_keys($withDirect))->toBe(['Platform connectors', 'Internal', 'Direct connectors'])
        // The toggle hides the per-partner integrations, never the reusable ones.
        ->and(array_keys($withoutDirect))->toBe(['Platform connectors', 'Internal'])
        ->and($withDirect['Platform connectors'])->toBe(['swaarm' => 'Swaarm', 'affise' => 'Affise'])
        ->and($withDirect['Direct connectors'])->toHaveKey('ogads')
        ->and($withDirect['Direct connectors']['ogads'])->toBe('OGAds')
        ->and($withDirect['Internal'])->toHaveKey('direct')
        ->and($withDirect['Internal'])->not->toHaveKey('fake');
});

it('adds a newly registered platform connector to the group', function () {
    $this->manager->register(PlatformTestDriver::key(), PlatformTestDriver::class);

    expect(NetworkResource::driverOptions(false)['Platform connectors'])
        ->toBe(['swaarm' => 'Swaarm', 'affise' => 'Affise', 'platform-test' => 'Platform test']);
});

it('resolves the driver keys behind a category for the table filter', function () {
    expect(NetworkResource::driverKeysFor(ConnectorCategory::Internal))->toContain('direct')
        ->and(NetworkResource::driverKeysFor(ConnectorCategory::Direct))->toContain('ogads')
        ->and(NetworkResource::driverKeysFor(ConnectorCategory::Direct))->not->toContain('direct')
        ->and(NetworkResource::driverKeysFor(ConnectorCategory::Platform))->toBe(['swaarm', 'affise']);
});
