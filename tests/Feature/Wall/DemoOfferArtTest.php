<?php

use App\Models\Offer;
use Database\Seeders\DemoWallSeeder;
use Illuminate\Support\Facades\Storage;

/**
 * The demo catalogue's artwork, as a fixture pipeline rather than as generated
 * art. `database/fixtures/offer-art` holds one file per app the demo names, and
 * DemoWallSeeder copies each one into the path OfferIconController already
 * serves from. What these tests hold in place is the three things that were
 * wrong or fragile about the tile it replaced.
 */

/**
 * The disk is faked for every test in this file, and it has to be: the seeder
 * writes to the real `local` disk by design — that is the whole point, it fills
 * the icon proxy's own cache — and the offer ids in a test database are not the
 * offer ids on the developer's box. Left real, these tests would scatter files
 * named after test ids into storage/app/private/offer-icons and leave them
 * there. The fixtures themselves are read from base_path and stay untouched.
 */
beforeEach(function () {
    Storage::fake('local');
});
it('installs a fixture for every demo offer the manifest names, and none for the rest', function () {
    $this->seed(DemoWallSeeder::class);

    $manifest = json_decode((string) file_get_contents(base_path('database/fixtures/offer-art/manifest.json')), true);
    $named = array_keys($manifest['art']);

    expect($named)->not->toBeEmpty();

    foreach (Offer::query()->whereIn('external_id', $named)->get() as $offer) {
        expect(Storage::disk('local')->exists("offer-icons/{$offer->id}.jpg"))
            ->toBeTrue("no art installed for {$offer->external_id}")
            ->and($offer->icon_url)->toBe('fixture://'.$offer->external_id);
    }

    // The survey and the truncation row carry no app identity, so they carry no
    // art. That is deliberate: the letter tile is the fallback a real offer
    // gets when its network deletes an asset, and it stays on the demo wall
    // where it can be seen to work.
    $bare = Offer::query()->where('external_id', 'demo-6')->sole();

    expect($bare->icon_url)->toBeNull()
        ->and(Storage::disk('local')->exists("offer-icons/{$bare->id}.jpg"))->toBeFalse();
});

it('serves the fixture through the icon proxy and 404s an offer with no art', function () {
    $this->seed(DemoWallSeeder::class);

    $withArt = Offer::query()->where('external_id', 'demo-1')->sole();
    $withoutArt = Offer::query()->where('external_id', 'demo-6')->sole();

    $this->get("/img/offer/{$withArt->id}")
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');

    // One visual outcome for "no art" and "the network deleted it": the card
    // falls back to its letter tile either way.
    $this->get("/img/offer/{$withoutArt->id}")->assertNotFound();
});

it('replaces art a previous run left behind instead of losing to it', function () {
    $this->seed(DemoWallSeeder::class);

    $offer = Offer::query()->where('external_id', 'demo-1')->sole();

    // OfferIconController::stored() scans png, jpg, webp then gif and returns
    // the first hit. Earlier versions of this seeder wrote PNGs, so a box that
    // had ever run one would keep serving the old gradient tile from
    // offer-icons/{id}.png and the new JPEG would never be reached — the art
    // would look unchanged with nothing to show why.
    Storage::disk('local')->put("offer-icons/{$offer->id}.png", 'stale');

    $this->seed(DemoWallSeeder::class);

    expect(Storage::disk('local')->exists("offer-icons/{$offer->id}.png"))->toBeFalse()
        ->and(Storage::disk('local')->exists("offer-icons/{$offer->id}.jpg"))->toBeTrue();

    $this->get("/img/offer/{$offer->id}")->assertHeader('Content-Type', 'image/jpeg');
});

it('ships artwork that carries no metadata identifying where it came from', function () {
    // AGENTS.md draws the anonymity line at image metadata. The store publishes
    // these files with an EXIF UserComment holding its own asset token and a
    // Photoshop resource block holding a digest; demo:offer-art walks the JPEG
    // and drops every APPn and comment segment on the way in.
    foreach (glob(base_path('database/fixtures/offer-art/*.jpg')) as $file) {
        $name = basename($file);
        $image = @getimagesize($file, $info);

        expect($image)->not->toBeFalse("{$name} is not a readable image")
            ->and($image[0])->toBe(512, "{$name} is not 512px wide")
            ->and($image[1])->toBe(512, "{$name} is not 512px tall")
            ->and($info)->toBe([], "{$name} still carries metadata segments");
    }
});
