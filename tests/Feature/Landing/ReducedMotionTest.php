<?php

/**
 * docs/09 §10 asks for: "`prefers-reduced-motion: reduce` → ScrollTrigger
 * instances are killed and counters show final values."
 *
 * The shipped implementation is better than the spec it was written against,
 * so this asserts what the code actually guarantees. site.js reads the media
 * query and returns before `gsap.registerPlugin` — under reduced motion no
 * ScrollTrigger is ever created, so there is nothing to kill. Never-created
 * beats created-then-killed: a kill loop has to run to completion to be
 * correct, and anything that throws before it finishes leaves a half-built
 * timeline animating at a visitor who asked for none. The same branch also
 * withholds the `js-ready` class, which is the only thing that hides a reveal,
 * so the reduced-motion path is the page exactly as it left the server.
 *
 * Pest has no headless browser, so the media query itself cannot be evaluated
 * here. What is asserted instead is every guarantee that has to hold for that
 * branch to be safe: the HTML is already correct before a script runs, and the
 * branch is still ordered ahead of GSAP in the source that ships.
 */
it('renders every counter at its final value in the raw HTML', function () {
    $html = $this->get('/')->getContent();

    preg_match_all('/data-count="(\d+)"[^>]*>([^<]*)</', $html, $matches, PREG_SET_ORDER);

    expect($matches)->not->toBeEmpty('The page has no [data-count] elements — the selector moved.');

    foreach ($matches as [, $target, $rendered]) {
        // Reduced motion skips the count-in entirely: whatever the server wrote
        // is what stays on screen, so it has to be the real figure and not a 0.
        expect((int) str_replace(',', '', $rendered))->toBe((int) $target);
    }
});

it('never ships a hidden reveal, because js-ready is a JS-only class', function () {
    $html = $this->get('/')->getContent();

    // Nothing is hidden at parse time. `.js-ready .reveal` is the only rule that
    // sets a reveal to opacity 0, and the class arrives from site.js — on the
    // non-reduced branch alone. Server HTML carrying it would hide the page for
    // every visitor whose bundle never loads.
    expect($html)->not->toContain('js-ready');

    $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
    $css = file_get_contents(public_path('build/'.$manifest['resources/css/app.css']['file']));

    expect($css)->toContain('.js-ready .reveal')
        ->toContain('prefers-reduced-motion');
});

it('returns from site.js before GSAP is ever registered', function () {
    $path = resource_path('js/site.js');

    // Fails rather than skips: an assertion that quietly stops running is worse
    // than no assertion at all (see BundleTest).
    expect(file_exists($path))->toBeTrue("{$path} is missing — the entry point moved.");

    $src = file_get_contents($path);

    $reduce = strpos($src, 'prefers-reduced-motion');
    $register = strpos($src, 'gsap.registerPlugin');

    expect($reduce)->not->toBeFalse('site.js never reads prefers-reduced-motion.');
    expect($register)->not->toBeFalse('site.js never registers a GSAP plugin — the needle moved.');

    // Position rather than an exact line: the guarantee is the ordering, and a
    // literal match would rot the first time the variable is renamed.
    expect($reduce)->toBeLessThan($register, 'GSAP is registered before the reduced-motion check is read.');

    expect(substr($src, $reduce, $register - $reduce))
        ->toMatch('/\breturn\b/', 'Nothing returns between reading the media query and registering GSAP.');
});
