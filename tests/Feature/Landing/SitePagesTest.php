<?php

/**
 * The written pages outside /legal, and the thing that keeps letting them rot:
 * a link in the shared header or footer that points at nothing.
 *
 * Both partials render on every public page, so one `href="#"` left behind is a
 * dead link on the landing page, the documentation, the legal pages and the
 * contact form at once. The last two tests here walk them for real.
 */
it('serves each written page with content of its own', function (string $path, string $heading, string $marker) {
    $this->get($path)
        ->assertOk()
        ->assertSee($heading, escape: false)
        ->assertSee($marker, escape: false)
        ->assertSee('id="main"', escape: false);
})->with([
    ['/about', 'About PLAYNDGAIN', 'The ledger is append-only'],
    ['/advertise', 'Supply offers to PLAYNDGAIN', 'A subid macro in the tracking URL'],
    ['/status', 'Status', 'an in-app status page cannot report that the app is down'],
]);

it('does not pretend the status page is a monitor', function () {
    // docs/11 §7 puts external HTTP checks in an external tool. A page that is
    // green whenever you can load it is worse than no page, so this one says
    // what the surfaces are and what their failures look like instead.
    $this->get('/status')
        ->assertOk()
        ->assertDontSee('All systems operational')
        ->assertSee('External uptime checks run outside this application', escape: false);
});

it('leaves no dead links in the shared header and footer', function () {
    $html = $this->get('/')->assertOk()->getContent();

    foreach (['header', 'footer'] as $landmark) {
        preg_match('#<'.$landmark.'\b.*?</'.$landmark.'>#s', $html, $block);

        expect($block)->not->toBeEmpty("No <{$landmark}> in the rendered page.");
        expect($block[0])->not->toContain('href="#"');
    }
});

it('resolves every internal link in the header and the footer', function () {
    $html = $this->get('/')->assertOk()->getContent();

    preg_match_all('#<(?:header|footer)\b.*?</(?:header|footer)>#s', $html, $blocks);
    preg_match_all('/href="([^"]+)"/', implode('', $blocks[0]), $matches);

    $paths = collect($matches[1])
        ->map(fn (string $href): string => (string) parse_url($href, PHP_URL_PATH))
        // Fragment-only hrefs have no path left; absolute externals have no
        // host we own. Everything that remains is a route this app must answer.
        ->filter(fn (string $path): bool => $path !== '' && str_starts_with($path, '/'))
        ->unique()
        ->values();

    expect($paths)->not->toBeEmpty();

    foreach ($paths as $path) {
        expect($this->get($path)->status())->toBe(200, "Dead link in the site chrome: {$path}");
    }
});
