<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\PublisherPanelProvider;
use App\Providers\FraudServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\NetworkServiceProvider;

return [
    AppServiceProvider::class,
    HorizonServiceProvider::class,
    NetworkServiceProvider::class,
    // After AppServiceProvider on purpose: it rebinds IpIntelligence over the
    // null implementation bound there.
    FraudServiceProvider::class,
    AdminPanelProvider::class,
    PublisherPanelProvider::class,
];
