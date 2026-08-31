<?php

use App\Models\Network;

require_once __DIR__.'/WallFixtures.php';

/*
 * The wall's app chrome: the topbar (brand, balance, menu trigger), the
 * mobile app-nav, and the menu sheet. The offers themselves are covered by
 * WallTest and WallCatalogueTest; this file is about the frame around them.
 */

it('shows the publisher-passed balance in the topbar and carries it across navigation', function () {
    wallGeo('US');
    [$app, $network] = wallFixture();
    wallOffer($network, ['countries' => [], 'devices' => []]);

    // An integer string is grouped for display; the raw value rides every
    // tab and app-nav URL so a section switch never loses it.
    wallGet($app, 'USER_1', ['balance' => '21000'])->assertOk()
        ->assertSee('wall-balance', false)
        ->assertSee('21,000', false)
        ->assertSee('balance=21000', false);
});

it('passes a publisher-formatted balance through untouched', function () {
    wallGeo('US');
    [$app, $network] = wallFixture();
    wallOffer($network, ['countries' => [], 'devices' => []]);

    // "21k" is the publisher's own formatting — grouping it would corrupt it.
    wallGet($app, 'USER_1', ['balance' => '21k'])->assertOk()
        ->assertSee('21k', false)
        ->assertDontSee('21,000', false);
});

it('omits the balance pill when the publisher passes no balance', function () {
    wallGeo('US');
    [$app, $network] = wallFixture();
    wallOffer($network, ['countries' => [], 'devices' => []]);

    wallGet($app)->assertOk()->assertDontSee('wall-balance', false);
});

it('offers the opposite colour scheme in the menu and keeps the wall parameters on it', function () {
    wallGeo('US');
    [$app, $network] = wallFixture();
    wallOffer($network, ['countries' => [], 'devices' => []]);

    // The fixture theme is dark, so the toggle offers light — and the URL it
    // points at keeps the appid, the user and the balance.
    wallGet($app, 'USER_1', ['balance' => '21000'])->assertOk()
        ->assertSee('theme=light', false)
        ->assertSee('balance=21000', false)
        ->assertSee(__('wall.menu.light'), false);

    // Flipped, the page is light and the toggle offers the way back.
    wallGet($app, 'USER_1', ['theme' => 'light'])->assertOk()
        ->assertSee('content="light"', false)
        ->assertSee('theme=dark', false)
        ->assertSee(__('wall.menu.dark'), false);
});

it('marks the current section in both navigations', function () {
    wallGeo('US');
    [$app, $network] = wallFixture();
    wallOffer($network, ['countries' => [], 'devices' => []]);

    // Tab strip and app-nav each mark the current page — one navigation per
    // breakpoint, but the landmark contract is the same either way.
    wallGet($app)->assertOk()
        ->assertSee('wall-nav', false)
        ->assertSee('aria-current="page"', false)
        ->assertSee('/wall/history?appid='.$app->app_id, false)
        ->assertSee('brand-mark', false)
        ->assertSee('#ic-spark', false)
        ->assertSee('#ic-clock', false)
        ->assertSee('#ic-grid', false);

    test()->withHeaders(['User-Agent' => WALL_UA])
        ->get('/wall/history?'.http_build_query(['appid' => $app->app_id, 'userid' => 'USER_1']))
        ->assertOk()
        ->assertSee('wall-nav', false)
        ->assertSee('aria-current="page"', false);
});

it('always shows the app-nav offerwall link', function () {
    wallGeo('US');
    [$app, $network] = wallFixture();
    wallOffer($network, ['countries' => [], 'devices' => []]);

    wallGet($app)->assertOk()->assertSee('/wall/partners?appid='.$app->app_id, false);
});

it('ships the menu sheet with only destinations that exist', function () {
    wallGeo('US');
    [$app, $network] = wallFixture();
    wallOffer($network, ['countries' => [], 'devices' => []]);

    // The trigger is enhancement-gated (x-cloak): with JS off it never
    // renders as a dead button, with JS on wall.js wires it to the dialog.
    wallGet($app)->assertOk()
        ->assertSee('id="wall-menu"', false)
        ->assertSee('data-menu', false)
        ->assertSee(route('legal', 'privacy'), false)
        ->assertSee(route('legal', 'terms'), false);
});
