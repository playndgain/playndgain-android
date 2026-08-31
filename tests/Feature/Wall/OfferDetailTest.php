<?php

use App\Enums\BlacklistType;
use App\Enums\NetworkStatus;
use App\Enums\OfferStatus;
use App\Models\OfferClick;
use Database\Factories\BannedEndUserFactory;
use Database\Factories\BlacklistFactory;

require_once __DIR__.'/WallFixtures.php';

/**
 * `/wall/offer/{offer}` — docs/03 §5.
 *
 * The page exists to answer "what do I actually have to do", so the assertions
 * that matter are: the steps are there, they are priced in the publisher's own
 * currency, they add up to the headline, and the page refuses everything a
 * click refuses.
 */
it('lists the steps priced in the publisher currency and never the USD payout', function () {
    // $2.00 + $1.00 at 70% rev-share and 1000 coins per dollar = 1,400 + 700,
    // and the offer's own $3.00 payout is 2,100.
    [$app, $network] = wallFixture(['currency_name' => 'Coins', 'currency_rate' => 1000], ['rev_share' => 70]);
    wallGeo('US');

    $offer = wallOffer($network, [
        'name' => 'Village Builder',
        'payout' => 3.00,
        'countries' => [],
        'devices' => [],
        'is_multi_reward' => true,
        'goals' => [
            ['name' => 'Reach village 5', 'payout' => 2.00],
            ['name' => 'Reach village 15', 'payout' => 1.00],
        ],
    ]);

    wallDetail($app, $offer)
        ->assertOk()
        ->assertSee('Reach village 5')
        ->assertSee('Reach village 15')
        ->assertSee('1,400')
        ->assertSee('700')
        ->assertSee('2,100')
        ->assertDontSee('3.00')
        ->assertDontSee('$');
});

it('gives an offer with no stored steps one step of its own', function () {
    [$app, $network] = wallFixture(['currency_name' => 'Coins', 'currency_rate' => 1000], ['rev_share' => 70]);
    wallGeo('US');

    $offer = wallOffer($network, ['payout' => 0.50, 'countries' => [], 'devices' => [], 'goals' => null]);

    wallDetail($app, $offer)
        ->assertOk()
        ->assertSee(__('wall.offer.single_step'))
        ->assertSee('350');
});

it('serves the fragment without the page chrome', function () {
    [$app, $network] = wallFixture();
    wallGeo('US');

    $offer = wallOffer($network, ['countries' => [], 'devices' => []]);

    // The dialog injects this into a page that already has a <head> and a
    // footer. A second copy of either is what a fragment must never carry.
    wallDetail($app, $offer, extra: ['fragment' => '1'])
        ->assertOk()
        ->assertSee($offer->name)
        ->assertDontSee('<!doctype html>', false)
        ->assertDontSee(__('wall.powered_by'));
});

it('refuses an offer the publisher blocked, exactly as a click does', function () {
    [$app, $network] = wallFixture();
    [$other] = wallFixture();
    wallGeo('US');

    $offer = wallOffer($network, ['countries' => [], 'devices' => []]);

    BlacklistFactory::new()->create([
        'publisher_id' => $app->publisher_id,
        'app_id' => $app->id,
        'type' => BlacklistType::Offer,
        'value' => (string) $offer->id,
    ]);

    wallDetail($app, $offer)->assertStatus(410)->assertSee(__('wall.notice.offer_gone.title'));
    wallDetail($other, $offer)->assertOk()->assertSee($offer->name);
});

it('refuses an inactive offer and a banned end user', function () {
    [$app, $network] = wallFixture();
    wallGeo('US');

    $gone = wallOffer($network, ['countries' => [], 'devices' => [], 'status' => OfferStatus::Inactive]);
    $fine = wallOffer($network, ['countries' => [], 'devices' => []]);

    wallDetail($app, $gone)->assertStatus(410);

    BannedEndUserFactory::new()->create(['publisher_id' => $app->publisher_id, 'end_user_id' => 'USER_1']);

    wallDetail($app, $fine, 'USER_1')->assertStatus(403)->assertSee(__('wall.notice.banned.title'));
    wallDetail($app, $fine, 'USER_2')->assertOk();
});

it('refuses an offer whose network is disabled', function () {
    [$app, $network] = wallFixture();
    wallGeo('US');

    $offer = wallOffer($network, ['countries' => [], 'devices' => []]);

    $network->update(['status' => NetworkStatus::Disabled]);

    wallDetail($app, $offer)->assertStatus(410)->assertDontSee($offer->name);
});

it('writes no click row — reading the terms is not a click', function () {
    [$app, $network] = wallFixture();
    wallGeo('US');

    $offer = wallOffer($network, ['countries' => [], 'devices' => []]);

    wallDetail($app, $offer)->assertOk();

    // Counting this as a click would inflate every CTR on the platform and
    // misprice the inventory it is measured against.
    expect(OfferClick::query()->count())->toBe(0);
});

it('never lets the detail html be cached', function () {
    [$app, $network] = wallFixture();
    wallGeo('US');

    $offer = wallOffer($network, ['countries' => [], 'devices' => []]);

    wallDetail($app, $offer)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
});

it('flattens the markup networks pack into their copy', function () {
    // Real inventory: `<b>` around the important word, `<br>` between steps,
    // `&emsp;` for indent. The wall escapes what it prints, so unflattened this
    // reached the user as the literal string and read as a broken product.
    [$app, $network] = wallFixture();
    wallGeo('US');

    $offer = wallOffer($network, [
        'countries' => [],
        'devices' => [],
        'description' => 'Register &amp; win.',
        'instructions' => '<b>Open the app</b><br>&emsp;1. Tap Start<br><br>2. Finish within 7 days',
    ]);

    wallDetail($app, $offer)
        ->assertOk()
        ->assertSee('Open the app')
        ->assertSee('1. Tap Start')
        ->assertSee('2. Finish within 7 days')
        ->assertSee('Register & win.')
        // Escaped, so a surviving tag shows up as its entity in the response.
        ->assertDontSee('&lt;b&gt;', false)
        ->assertDontSee('&amp;emsp;', false);
});

it('refuses an offer targeted at another country or another device', function () {
    // The hole this closes: the wall filters its list in SQL, but this URL
    // carries an offer id the end user can read off their own iframe. A copied
    // link would otherwise render another country's offer — terms, steps and a
    // live start button — to someone who can never complete it.
    [$app, $network] = wallFixture();
    wallGeo('US');

    $german = wallOffer($network, ['name' => 'German Offer', 'countries' => ['DE'], 'devices' => []]);
    $desktop = wallOffer($network, ['name' => 'Desktop Offer', 'countries' => [], 'devices' => ['desktop']]);
    $fine = wallOffer($network, ['name' => 'Open Offer', 'countries' => [], 'devices' => []]);

    // WALL_UA is an Android phone.
    wallDetail($app, $german)->assertStatus(410)->assertDontSee($german->name);
    wallDetail($app, $desktop)->assertStatus(410)->assertDontSee($desktop->name);
    wallDetail($app, $fine)->assertOk()->assertSee($fine->name);
});

it('refuses every offer to a user it could not place', function () {
    [$app, $network] = wallFixture();
    wallGeo(null);

    $offer = wallOffer($network, ['countries' => [], 'devices' => []]);

    // Worldwide is open to every country — but not to a user whose country we
    // never established. The list and this route agree on that.
    wallDetail($app, $offer)->assertStatus(410);
});

it('never lets a stored country code reach the flag fallback as script', function () {
    [$app, $network] = wallFixture();
    wallGeo('US');

    // The shape a code review found: `offers.countries` is fed by nine network
    // catalogues and by an admin tag field, and the flag fallback used to
    // interpolate each code into a JavaScript string inside an `onerror`
    // attribute. Blade escapes the quote, the browser decodes the attribute
    // before parsing the handler as script, and the literal breaks open.
    $offer = wallOffer($network, [
        'countries' => ['US', "a');alert(1)//", 'GB'],
        'devices' => [],
    ]);

    $html = wallDetail($app, $offer)->assertOk()->getContent();

    expect($html)->not->toContain('alert(1)')
        // The malformed code is dropped rather than rendered escaped: it could
        // never match a resolved country, so it is data with no meaning.
        ->and($html)->not->toContain('a&#039;)')
        // The two real ones still render.
        ->and($html)->toContain('/flags/us.svg')
        ->and($html)->toContain('/flags/gb.svg');
});
