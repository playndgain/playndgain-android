<?php

namespace App\Providers;

use App\Networks\Contracts\NetworkDriver;
use App\Networks\Drivers\AdGateMediaDriver;
use App\Networks\Drivers\AdmantiumDriver;
use App\Networks\Drivers\AdscendMediaDriver;
use App\Networks\Drivers\AdswedmediaDriver;
use App\Networks\Drivers\AdtogameDriver;
use App\Networks\Drivers\AffiseDriver;
use App\Networks\Drivers\AyetStudiosDriver;
use App\Networks\Drivers\ClickwallDriver;
use App\Networks\Drivers\DirectDriver;
use App\Networks\Drivers\FakeNetworkDriver;
use App\Networks\Drivers\GemiAdDriver;
use App\Networks\Drivers\HangMyAdsDriver;
use App\Networks\Drivers\KlinkDriver;
use App\Networks\Drivers\LootablyDriver;
use App\Networks\Drivers\MonlixDriver;
use App\Networks\Drivers\MyChipsDriver;
use App\Networks\Drivers\NotikDriver;
use App\Networks\Drivers\OfferyDriver;
use App\Networks\Drivers\OGAdsDriver;
use App\Networks\Drivers\SkylupDriver;
use App\Networks\Drivers\SwaarmDriver;
use App\Networks\Drivers\ToroxDriver;
use App\Networks\NetworkDriverManager;
use Illuminate\Support\ServiceProvider;

/**
 * Step 7 of the checklist in docs/02 §6 — every driver registers itself here.
 * Each class carries its own key, so a driver is added in exactly one place and
 * the key can never drift from `networks.driver`.
 */
class NetworkServiceProvider extends ServiceProvider
{
    /** @var list<class-string<NetworkDriver>> */
    private const DRIVERS = [
        OGAdsDriver::class,
        NotikDriver::class,
        MonlixDriver::class,
        AdscendMediaDriver::class,
        AdmantiumDriver::class,
        AdswedmediaDriver::class,
        DirectDriver::class,
        AdGateMediaDriver::class,
        LootablyDriver::class,
        ToroxDriver::class,
        AyetStudiosDriver::class,
        HangMyAdsDriver::class,
        GemiAdDriver::class,
        AdtogameDriver::class,
        OfferyDriver::class,
        MyChipsDriver::class,
        KlinkDriver::class,
        ClickwallDriver::class,
        SkylupDriver::class,
        // The platform connectors. Unlike every key above, more than one
        // `networks` row may legitimately use these.
        SwaarmDriver::class,
        AffiseDriver::class,
    ];

    public function boot(): void
    {
        $manager = $this->app->make(NetworkDriverManager::class);

        foreach (self::DRIVERS as $driver) {
            $manager->register($driver::key(), $driver);
        }

        // The test double stays out of production: registered there, a `fake`
        // network row would be one admin click away from live traffic. Local
        // and staging get it too, because DemoWallSeeder's inventory hangs off
        // a `fake` network and a demo click has to redirect, not 500.
        if (! $this->app->environment('production')) {
            $manager->register(FakeNetworkDriver::key(), FakeNetworkDriver::class);
        }
    }
}
