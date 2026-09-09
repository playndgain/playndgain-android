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
    expect(file_get_contents(public_path('img/networks/house.svg')))
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

it('links the favicon set from the marketing layout', function () {
    $this->get('/')
        ->assertSuccessful()
        ->assertSee('favicon.ico', escape: false)
        ->assertSee('favicon.svg', escape: false)
        ->assertSee('apple-touch-icon.png', escape: false)
        // The data URI it replaced would still "contain a favicon".
        ->assertDontSee('data:image/svg+xml', escape: false);
});

it('gives both panels a favicon and a mark that still says which panel it is', function () {
    Config::set('playndgain.require_admin_2fa', false);

    $this->actingAs(User::factory()->admin()->create([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
    ]));

    $this->get('/admin')
        ->assertSuccessful()
        ->assertSee('favicon.ico', escape: false)
        // brandLogo replaces brandName, so the suffix has to survive inside it.
        ->assertSee('Admin', escape: false);

    $this->actingAs(Publisher::factory()->create()->user);

    $this->get('/dashboard')
        ->assertSuccessful()
        ->assertSee('favicon.ico', escape: false)
        ->assertSee('Publisher', escape: false);
});

it('renders one mark per brand surface with no duplicate gradient ids', function () {
    $html = $this->get('/')->assertSuccessful()->getContent();

    // Header, footer and the page body all render the mark — the bowl of its
    // "P" is the signature. The tile's gradient needs an id, and the same id
    // rendered twice on one page is a collision the second loses, so every
    // instance mints its own and no two may match.
    expect(substr_count((string) $html, 'M21 19H33a11 11 0 0 1 0 22H21'))->toBeGreaterThanOrEqual(2);

    preg_match_all('/<linearGradient id="([^"]+)"/', (string) $html, $matches);

    expect($matches[1])->not->toBeEmpty()
        ->and(count(array_unique($matches[1])))->toBe(count($matches[1]));
});
