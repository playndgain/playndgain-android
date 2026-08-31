<?php

use App\Models\App;
use App\Models\Network;
use App\Models\Offer;
use App\Models\OfferClick;
use App\Models\Publisher;
use App\Networks\Drivers\FakeNetworkDriver;
use App\Networks\DTO\PostbackDTO;
use App\Networks\NetworkDriverManager;
use Database\Factories\OfferClickFactory;
use Illuminate\Testing\TestResponse;

/**
 * Shared arrangement for the money-path suite.
 *
 * Pest only auto-loads `tests/Pest.php`, so each file in this directory
 * `require_once`s this one.
 */

/**
 * A publisher, an app, an offer on a driver-backed network, and the click a
 * postback has to resolve back to.
 *
 * @param  array<string, mixed>  $app
 * @param  array<string, mixed>  $click
 * @param  array<string, mixed>  $publisher
 */
function moneyFixture(array $app = [], array $click = [], array $publisher = [], string $driver = 'fake'): OfferClick
{
    // No NetworkServiceProvider exists yet, so nothing registers the drivers.
    app(NetworkDriverManager::class)->register($driver, FakeNetworkDriver::class);
    FakeNetworkDriver::reset();

    $network = Network::factory()->create(['driver' => $driver]);
    $owner = Publisher::factory()->create($publisher);
    $placement = App::factory()->create(['publisher_id' => $owner->id, ...$app]);
    $offer = Offer::factory()->create(['network_id' => $network->id]);

    return OfferClickFactory::new()->create([
        'app_id' => $placement->id,
        'publisher_id' => $owner->id,
        'offer_id' => $offer->id,
        'network_id' => $network->id,
        ...$click,
    ]);
}

function postbackDto(
    OfferClick $click,
    string $status = 'credited',
    float $payout = 0.13,
    string $txId = 'NTX-100',
    ?string $clickId = null,
): PostbackDTO {
    return new PostbackDTO(
        networkTxId: $txId,
        clickId: $clickId ?? $click->click_id,
        payout: $payout,
        status: $status,
        offerExternalId: $click->offer->external_id,
        offerName: $click->offer->name,
        endUserId: $click->end_user_id,
        ip: $click->ip,
    );
}

/**
 * @param  array<string, mixed>  $payload
 */
function firePostback(?PostbackDTO $dto, array $payload = [], string $driver = 'fake'): TestResponse
{
    FakeNetworkDriver::shouldParsePostback($dto);

    return test()->post("/postback/{$driver}", $payload);
}
