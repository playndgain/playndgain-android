<?php

use App\Models\Publisher;

/**
 * The panels wear the same brand as the marketing site — design.md §10.
 * These assert the wiring, not the pixels: the theme bundle is linked, dark is
 * forced server-side, and no font CDN is contacted.
 */
$brandTheme = function (string $html) {
    $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);

    expect($html)->toContain($manifest['resources/css/filament/theme.css']['file'])
        // Forced in the panel provider, so the class is in the HTML rather than
        // applied by a script — the panel is never light for one frame.
        ->toContain('class="fi dark"')
        // design.md §3: one origin. Filament's default font provider would add
        // a Google Fonts request for Inter.
        ->not->toContain('fonts.googleapis.com')
        ->not->toContain('fonts.bunny.net');
};

it('dresses the publisher dashboard in the brand', function () use ($brandTheme) {
    $publisher = Publisher::factory()->create();

    $brandTheme($this->actingAs($publisher->user)->get('/dashboard')->getContent());
});

it('dresses both panel login pages in the brand', function (string $path) use ($brandTheme) {
    $brandTheme($this->get($path)->getContent());
})->with(['/admin/login', '/dashboard/login']);
