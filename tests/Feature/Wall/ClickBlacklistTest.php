<?php

use App\Enums\BlacklistType;
use App\Models\Blacklist;
use App\Models\OfferClick;

require_once __DIR__.'/WallFixtures.php';

/**
 * A publisher's blacklist has to hold on a URL the user typed themselves.
 *
 * The wall filters blocked offers out of the payload, but /click takes an offer
 * id and every other parameter it needs is visible to the end user — appid,
 * userid and sig are all in the iframe URL they are looking at. If the click
 * route trusts the wall to have filtered, a block is advisory: the publisher
 * still gets the conversion, still owes their user the reward, and still
 * carries whatever they blocked the offer for.
 */
function blockFor(mixed $app, BlacklistType $type, string $value): void
{
    Blacklist::query()->create([
        'publisher_id' => $app->publisher_id,
        'app_id' => $app->id,
        'type' => $type,
        'value' => $value,
    ]);
}

it('refuses a click on an offer the publisher blacklisted', function () {
    [$app, $network] = wallFixture();
    $offer = wallOffer($network);

    blockFor($app, BlacklistType::Offer, (string) $offer->id);

    wallClick($app, $offer)->assertGone();

    // No redirect means no click row, so no conversion can ever attach to it.
    expect(OfferClick::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('refuses a click on a network the publisher blacklisted', function () {
    [$app, $network] = wallFixture();
    $offer = wallOffer($network);

    blockFor($app, BlacklistType::Network, $network->driver);

    wallClick($app, $offer)->assertGone();
});

it('refuses a click on a network disabled by id on that app', function () {
    [$app, $network] = wallFixture();
    $offer = wallOffer($network);

    blockFor($app, BlacklistType::Network, (string) $network->id);

    wallClick($app, $offer)->assertGone();
});

it('refuses a click on a device the publisher blacklisted', function () {
    [$app, $network] = wallFixture();
    $offer = wallOffer($network);

    // The stored value is the enum's string. Comparing the DeviceType object
    // against it matches nothing and the block quietly does not apply.
    blockFor($app, BlacklistType::Device, 'android');

    wallClick($app, $offer)->assertGone();
});

it('refuses a click from a country the app blocked', function () {
    [$app, $network] = wallFixture();
    $offer = wallOffer($network);

    wallGeo('EG');
    $app->update(['blocked_countries' => ['EG']]);

    wallClick($app, $offer)->assertGone();
});

it('still allows a click the publisher has not blocked', function () {
    [$app, $network] = wallFixture();
    $offer = wallOffer($network);

    blockFor($app, BlacklistType::Offer, '999999');

    wallClick($app, $offer)->assertRedirect();

    expect(OfferClick::query()->withoutGlobalScopes()->count())->toBe(1);
});
