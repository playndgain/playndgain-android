<?php

/**
 * The offerwall is a list of cards on a mid-range Android over 3G, and the
 * landing page is still by design. Neither bundle may carry an animation
 * library — 34 KB gzipped of engine is the wall's whole budget twice over,
 * and the site has nothing left to animate.
 *
 * Vite hoists what site.js and wall.js share into a common chunk, so checking
 * each entry's own file is not enough: this walks the import graph.
 */

/** @return list<string> every emitted file reachable from a manifest entry */
function chunkGraph(array $manifest, string $entry, array $seen = []): array
{
    if (isset($seen[$entry]) || ! isset($manifest[$entry])) {
        return [];
    }

    $seen[$entry] = true;
    $files = [$manifest[$entry]['file']];

    foreach ($manifest[$entry]['imports'] ?? [] as $import) {
        $files = array_merge($files, chunkGraph($manifest, $import, $seen));
        $seen[$import] = true;
    }

    return $files;
}

beforeEach(function () {
    // A missing manifest fails rather than skips: an assertion that quietly
    // stops running is the failure mode this file exists to guard against.
    expect(file_exists(public_path('build/manifest.json')))
        ->toBeTrue('public/build/manifest.json is missing — run `npm run build`.');

    $this->manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
});

it('builds every entry point', function () {
    expect($this->manifest)->toHaveKeys([
        'resources/css/app.css',
        'resources/js/site.js',
        'resources/js/wall.js',
        'resources/css/filament/theme.css',
    ]);
});

it('keeps animation libraries out of both bundles', function (string $entry) {
    $files = chunkGraph($this->manifest, $entry);

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        expect(file_get_contents(public_path('build/'.$file)))
            ->not->toContain('gsap', "GSAP reached {$entry} through {$file}.");
    }
})->with(['resources/js/site.js', 'resources/js/wall.js']);

it('does not depend on GSAP at all', function () {
    $package = json_decode(file_get_contents(base_path('package.json')), true);

    expect(array_merge($package['dependencies'] ?? [], $package['devDependencies'] ?? []))
        ->not->toHaveKey('gsap')
        ->not->toHaveKey('@alpinejs/intersect');
});
