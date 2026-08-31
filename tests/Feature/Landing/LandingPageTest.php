<?php

/**
 * The landing page is judged on rendering completely without JavaScript.
 * Everything here reads the raw response body — never a headless browser —
 * because that is exactly what a visitor with a blocked bundle receives.
 */
it('serves the landing page with the hero headline in the raw HTML', function () {
    $response = $this->get('/');

    $response->assertOk()
        ->assertSee('Monetize your users.', escape: false)
        ->assertSee('Audit every cent.', escape: false)
        ->assertSee('One conversion, click to credited.', escape: false);
});

it('never ships a reveal element that starts hidden', function () {
    $html = $this->get('/')->getContent();

    preg_match_all('/<[a-z][a-z0-9]*\b[^>]*class="[^"]*\breveal\b[^"]*"[^>]*>/i', $html, $matches);

    expect($matches[0])->not->toBeEmpty('The page has no .reveal elements — the selector moved.');

    foreach ($matches[0] as $tag) {
        // Reveals start VISIBLE and are hidden by JS on mount. The reverse — a
        // stylesheet that starts every section at zero — is a page that
        // disappears when a script fails to load.
        expect($tag)->not->toMatch('/opacity[-:]\s*0(?![.\d])/i');
    }
});

it('gates the reveal-hiding rule behind the js-ready class', function () {
    $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
    $css = file_get_contents(public_path('build/'.$manifest['resources/css/app.css']['file']));

    expect($css)->toContain('.js-ready .reveal');
});

it('paints the landing wall with the live card chrome and store artwork', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)
        ->toContain('class="wall-card')
        ->toContain('/img/landing/offers/coin-master.webp')
        ->toContain('/img/landing/offers/monopoly-go.webp')
        ->toContain('/img/landing/offers/temu.webp')
        ->toContain('/img/landing/offers/tiktok.webp')
        ->toContain('Coin Master')
        ->toContain('MONOPOLY GO!')
        ->not->toContain('icon-tile');

    foreach (['coin-master', 'monopoly-go', 'temu', 'tiktok'] as $slug) {
        expect(is_file(public_path("img/landing/offers/{$slug}.webp")))->toBeTrue();
    }
});

it('gives every image an alt attribute', function () {
    $html = $this->get('/')->getContent();

    preg_match_all('/<img\b[^>]*>/i', $html, $matches);

    expect($matches[0])->not->toBeEmpty();

    foreach ($matches[0] as $tag) {
        expect($tag)->toMatch('/\salt="/i', "Missing alt: {$tag}");
    }
});

it('renders the metric counters at their real values, not zero', function () {
    $html = $this->get('/')->getContent();

    preg_match_all('/data-count="(\d+)"[^>]*>([^<]*)</', $html, $matches, PREG_SET_ORDER);

    expect($matches)->toHaveCount(4);

    foreach ($matches as [, $target, $rendered]) {
        expect((int) str_replace(',', '', $rendered))->toBe((int) $target);
    }
});
