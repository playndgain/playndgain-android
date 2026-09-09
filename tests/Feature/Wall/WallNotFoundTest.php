<?php

require_once __DIR__.'/WallFixtures.php';

/*
 * The wall is an iframe on someone else's page, so a dead link has to end on
 * the wall's own notice — never on the framework's error page, which reads as
 * the publisher's site being broken. The 400 for a malformed user id already
 * did this; a 404 for an app or offer that does not exist is the same case.
 */

it('renders the wall notice for an unknown app id', function () {
    wallGeo('US');

    $response = test()->withHeaders(['User-Agent' => WALL_UA])
        ->get('/wall?appid=99999&userid=USER_1')
        ->assertNotFound()
        ->assertSee(__('wall.notice.invalid.title'), false)
        ->assertDontSee('Not Found', false);

    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');
});

it('renders the wall notice for an offer that does not exist', function () {
    wallGeo('US');
    [$app] = wallFixture();

    test()->withHeaders(['User-Agent' => WALL_UA])
        ->get('/wall/offer/999999?'.http_build_query(['appid' => $app->app_id, 'userid' => 'USER_1']))
        ->assertNotFound()
        ->assertSee(__('wall.notice.invalid.title'), false);
});

it('leaves the plain 404 alone outside the wall', function () {
    $this->get('/definitely-not-a-route')
        ->assertNotFound()
        ->assertDontSee(__('wall.notice.invalid.title'), false);
});
