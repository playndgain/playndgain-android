<?php

use App\Enums\NetworkStatus;
use App\Models\Network;
use App\Models\Offer;

/**
 * Motion is an enhancement with two gates — JavaScript present, and no
 * request for less of it. These read the built stylesheet and the source of
 * the site bundle, because that is what decides whether a section can ever
 * be hidden: without `html.js` nothing is, and under
 * prefers-reduced-motion: reduce nothing is either.
 */
it('hides entrances only behind JavaScript and the motion query', function () {
    $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
    $css = file_get_contents(public_path('build/'.$manifest['resources/css/app.css']['file']));

    $motion = strpos($css, 'prefers-reduced-motion:no-preference');

    expect($motion)->not->toBeFalse();

    // Nothing before the no-preference block touches an entrance, and inside
    // it every hidden state is scoped to `.js` and made of opacity/transform.
    expect(substr($css, 0, $motion))->not->toContain('data-reveal');
    expect(substr($css, $motion))
        ->toMatch('/\.js \[data-reveal\]\{[^}]*opacity:0[^}]*transform:translate/')
        ->toContain('.js [data-reveal].is-in{opacity:1;transform:none}')
        ->toContain('.js:not(.hero-seen) .hero-enter [data-enter]');

    // The image fade is JavaScript-gated too: with the bundle absent, a shot
    // paints as it arrives.
    expect($css)->toMatch('/\.js \.shot>img\{[^}]*opacity:0/')
        ->toContain('prefers-reduced-motion:reduce');
});

it('ships no animation library in the site bundle', function () {
    $src = file_get_contents(resource_path('js/site.js'));

    expect($src)
        ->not->toContain('gsap')
        ->not->toContain('ScrollTrigger')
        ->not->toContain("from 'motion");
});

it('renders every figure at its final value without a script', function () {
    // Two live offers across three countries. It used to matter that they were
    // here: the proof band counted them, and this test checked the count-up
    // landed on the figure already in the HTML. The band no longer counts
    // anything, so what matters now is the stronger property — that a page
    // served to a reader with no script has no figure waiting to be animated
    // into place, because it has no animated figures at all.
    $network = Network::factory()->create(['status' => NetworkStatus::Active]);
    Offer::factory()->for($network)->create(['countries' => ['US', 'EG']]);
    Offer::factory()->for($network)->create(['countries' => ['DE']]);

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)
        ->not->toContain('data-count')
        ->not->toContain('js-ready')
        ->not->toContain('data-metric');

    // The figures that are on the page are terms, not statistics, and they are
    // final in the markup: the minimum payout and the ledger's precision.
    expect($html)
        ->toContain('Minimum payout')
        ->toContain('6 decimals');
});
