<?php

/**
 * docs/12 Phase 6: "the offerwall build contains no GSAP. Write this before the
 * animation work, not after." The wall is a list of cards on a mid-range Android
 * over 3G — 34 KB gzipped of animation engine is the whole budget twice over.
 *
 * Vite hoists what site.js and wall.js share into a common chunk, so checking
 * the wall's own file is not enough: this walks the import graph.
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
    // stops running is the failure mode docs/12 asked to guard against.
    expect(file_exists(public_path('build/manifest.json')))
        ->toBeTrue('public/build/manifest.json is missing — run `npm run build`.');

    $this->manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
});

it('builds all three entry points', function () {
    expect($this->manifest)->toHaveKeys([
        'resources/css/app.css',
        'resources/js/site.js',
        'resources/js/wall.js',
    ]);
});

it('keeps GSAP out of the offerwall bundle', function () {
    $files = chunkGraph($this->manifest, 'resources/js/wall.js');

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        expect(file_get_contents(public_path('build/'.$file)))
            ->not->toContain('gsap', "GSAP reached the offerwall through {$file}.");
    }
});

it('still finds GSAP in the site bundle', function () {
    // Guards the test above: if the needle stopped matching, the wall assertion
    // would pass for the wrong reason.
    $found = collect(chunkGraph($this->manifest, 'resources/js/site.js'))
        ->contains(fn (string $file) => str_contains(file_get_contents(public_path('build/'.$file)), 'gsap'));

    expect($found)->toBeTrue();
});
