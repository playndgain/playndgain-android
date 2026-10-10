<?php

use App\Models\Conversion;
use App\Models\Network;
use App\Services\Money\ConversionProcessor;

it('rewrites partner conversions that were named after their network', function () {
    $network = Network::factory()->create(['name' => 'Leaky Partner']);

    $leaked = Conversion::factory()->create(['network_id' => $network->id, 'offer_id' => null, 'offer_name' => 'Leaky Partner']);
    $named = Conversion::factory()->create(['network_id' => $network->id, 'offer_id' => null, 'offer_name' => 'Level 5']);

    (require database_path('migrations/2026_10_10_000001_genericise_partner_conversion_names.php'))->up();

    expect($leaked->refresh()->offer_name)->toBe(ConversionProcessor::PARTNER_OFFER_NAME)
        ->and($named->refresh()->offer_name)->toBe('Level 5');
});
