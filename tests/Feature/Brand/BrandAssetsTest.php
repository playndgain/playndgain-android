<?php

use App\Models\Publisher;
use App\Models\User;
use Illuminate\Support\Facades\Config;

/**
 * `public/favicon.ico` shipped as a zero-byte file — present enough that
 * nothing complained, empty enough that every surface asking for it got
 * nothing. That is the failure class this file exists for: the assets are
 * asserted to be non-empty and actually linked, not just to exist.
 */
it('uses the brand mark as the house network logo', function () {
    expect(file_get_contents(public_path('img/brand/playndgain-symbol.svg')))
        ->toBe(file_get_contents(public_path('favicon.svg')))
        ->and(file_get_contents(public_path('img/networks/house.svg')))
        ->toBe(file_get_contents(public_path('favicon.svg')));
});

it('ships a favicon set that is not empty', function (string $file, int $minimumBytes) {
    $path = public_path($file);

    expect(file_exists($path))->toBeTrue("{$file} is missing — run php scripts/make-favicons.php")
        ->and(filesize($path))->toBeGreaterThan($minimumBytes);
})->with([
    ['favicon.ico', 500],
    ['favicon.svg', 100],
    ['apple-touch-icon.png', 500],
]);

it('writes a real icon container rather than a renamed png', function () {
    $bytes = (string) file_get_contents(public_path('favicon.ico'));

    // ICONDIR: reserved 0, type 1 (icon), then the image count.
    ['reserved' => $reserved, 'type' => $type, 'count' => $count] =
        unpack('vreserved/vtype/vcount', $bytes);

    expect($reserved)->toBe(0)
        ->and($type)->toBe(1)
        ->and($count)->toBeGreaterThanOrEqual(2);
});

it('links the favicon set from every html surface', function (string $path) {
    $this->get($path)
        ->assertSuccessful()
        ->assertSee('favicon.ico', escape: false)
        ->assertSee('favicon.svg', escape: false)
        ->assertSee('apple-touch-icon.png', escape: false)
        // The data URI it replaced would still "contain a favicon".
        ->assertDontSee('data:image/svg+xml', escape: false);
})->with([
    '/',
    '/docs',
    '/admin/login',
    '/dashboard/login',
    '/dashboard/register',
]);

it('gives both panels a favicon and a mark that still says which panel it is', function () {
    Config::set('playndgain.require_admin_2fa', false);

    $this->actingAs(User::factory()->admin()->create([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
    ]));

    $this->get('/admin')
        ->assertSuccessful()
        ->assertSee('favicon.svg', escape: false)
        // brandLogo replaces brandName, so the suffix has to survive inside it.
        ->assertSee('Admin', escape: false);

    $this->actingAs(Publisher::factory()->create()->user);

    $this->get('/dashboard')
        ->assertSuccessful()
        ->assertSee('favicon.svg', escape: false)
        ->assertSee('Publisher', escape: false);
});

it('renders the approved monogram geometry on every brand surface', function () {
    $html = $this->get('/')->assertSuccessful()->getContent();

    // Header, footer, page body and intro all carry the source path rather than
    // a separately drawn approximation that can drift from the approved SVG.
    expect(substr_count((string) $html, 'M 600.205 996.47'))->toBeGreaterThanOrEqual(2);
});
