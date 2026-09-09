<?php

/**
 * The landing page is judged on rendering completely without JavaScript.
 * Everything here reads the raw response body — never a headless browser —
 * because that is exactly what a visitor with a blocked bundle receives.
 */
it('serves the landing page with the claim in the raw HTML', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('Turn playtime into payouts.', escape: false)
        ->assertSee('One wall. Your brand.', escape: false)
        ->assertSee('Live in an afternoon.', escape: false)
        ->assertSee('Stated once, before you integrate.', escape: false);
});

it('states what it can defend and invents nothing', function () {
    $html = $this->get('/')->assertOk()->getContent();

    // Four tiles, four capabilities, and no branch: the band prints the same
    // four things on a fresh install and on a busy one. It used to print live
    // counts with a capability standing in below each sample floor, so there
    // were two versions of this section and only one of them was ever tested
    // on a given box.
    expect(substr_count($html, 'metric-tile'))->toBe(4)
        ->and($html)
        ->toContain('One integration')
        ->toContain('Games, surveys, installs')
        ->toContain('Signed callbacks')
        ->toContain('Paid monthly')
        ->not->toContain('data-count')
        ->not->toContain('1,284')
        ->not->toContain('99.98')
        ->not->toContain('illustrative');

    // Nothing on the page is a counter, so nothing on the page can count up.
    preg_match_all('/data-metric="([^"]*)"/', $html, $metrics);

    expect($metrics[1])->toBe([]);
});

it('describes payouts as monthly, never as a fixed number of days', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)
        ->toContain('Paid monthly')
        ->toContain('When you are paid')
        ->not->toMatch('/NET-?30/i');
});

it('ships nothing that starts hidden', function () {
    $html = $this->get('/')->getContent();

    // No reveal system in the markup: entrances are a stylesheet rule that
    // only applies once JavaScript has arrived, and a page whose sections
    // start at zero opacity is a page that disappears when a script fails.
    expect($html)
        ->not->toContain('js-ready')
        ->not->toContain('class="reveal')
        ->not->toMatch('/style="[^"]*opacity:\s*0(?![.\d])/i');
});

it('gives every image an alt attribute', function () {
    $html = $this->get('/')->getContent();

    preg_match_all('/<img\b[^>]*>/i', $html, $matches);

    expect($matches[0])->not->toBeEmpty();

    foreach ($matches[0] as $tag) {
        expect($tag)->toMatch('/\salt="/i', "Missing alt: {$tag}");
    }
});

it('shows the product itself, framed, with a skeleton behind every shot', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)
        ->toContain('/img/landing/wall-dark.webp')
        ->toContain('/img/landing/wall-light.webp')
        ->toContain('/img/landing/dashboard.webp')
        ->toContain('/img/landing/postbacks-card.webp')
        ->toContain('class="phone"')
        ->toContain('fetchpriority="high"')
        ->toMatch('/<link rel="preload" as="image"[^>]*wall-dark/')
        ->not->toContain('class="wall-card')
        ->not->toContain('landing-wall');

    // Every product image sits in a .shot frame, which paints the skeleton
    // until the image has decoded and reserves the box with width/height.
    preg_match_all('/<img\b[^>]*\/img\/landing\/(?!partners)[^>]*>/', $html, $shots);

    expect($shots[0])->not->toBeEmpty();

    foreach ($shots[0] as $tag) {
        expect($tag)->toMatch('/\swidth="\d+"/')->toMatch('/\sheight="\d+"/');
    }

    expect(substr_count($html, 'class="shot'))->toBeGreaterThanOrEqual(count($shots[0]));
});

it('never names a demand partner on a public surface', function () {
    $html = $this->get('/')->assertOk()->getContent();

    // AGENTS.md: upstream networks are infrastructure. The page speaks of
    // demand in aggregate and by kind; no partner mark or name is served.
    expect($html)
        ->toContain('id="inventory"')
        ->toContain('demand partner')
        ->not->toContain('/img/networks/')
        ->not->toContain('/img/landing/partners/');

    foreach (['AdGate', 'Ayet', 'Adscend', 'Torox', 'Notik', 'Monlix', 'OGAds', 'Klink', 'HangMyAds', 'Adtogame', 'GemiAd', 'ClickWall', 'Offery', 'Skylup', 'MyChips', 'Admantum'] as $network) {
        expect($html)->not->toContain($network);
    }
});

it('speaks to publishers, not advertisers', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)
        ->toContain('id="publishers"')
        ->toContain('href="/dashboard/register"')
        ->toContain('Monetise every player.')
        ->not->toContain('id="advertisers"')
        ->not->toContain('For advertisers')
        ->not->toContain('Buy verified actions.');
});

it('says each thing once, and tags every playbook as an example', function () {
    $html = $this->get('/')->assertOk()->getContent();

    // Two capability cards, not four. The two that left were the two the page
    // already made elsewhere — see LandingContent::features().
    expect($html)
        ->toContain('Every blocked click says why')
        ->toContain('Nothing is edited after the fact')
        ->not->toContain('A fraud engine that explains itself')
        ->not->toContain('Your brand on the wall');

    // And each of those claims now appears exactly once in the body copy.
    expect(substr_count($html, 'Filtered on every render'))->toBe(1);

    expect(substr_count($html, '>Example</span>'))->toBe(3);
    expect($html)->toContain('What a first month looks like.');
});

it('names no app on a surface where no offer stands behind it', function () {
    $html = $this->get('/')->assertOk()->getContent();

    // Real app identities belong on the wall, where an offer row carries them
    // and the art is the app's own. They do not belong in a decorative pill or
    // a ledger mock: a hardcoded "Coin Master · +640 Coins" claimed inventory
    // and a conversion that this installation never had.
    expect($html)
        ->not->toContain('Coin Master')
        ->not->toContain('OFFER19624')
        ->not->toContain('+640');
});

it('alternates ink and paper sections', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect(substr_count($html, 'class="on-paper'))->toBeGreaterThanOrEqual(4)
        ->and(substr_count($html, 'on-ink'))->toBeGreaterThanOrEqual(3);
});

it('highlights the callback handler on the server', function () {
    $html = $this->get('/')->assertOk()->getContent();

    // Real parameter names from the docs, coloured by the same highlighter
    // the docs use — the browser is never asked to learn PHP. The mobile
    // toggle is x-cloak: without a script the handler is simply shown.
    expect($html)
        ->toContain('callback handler · php')
        ->toContain('payout_usd')
        ->toContain('PLAYNDGAIN_SECRET')
        ->toContain('class="hl-')
        ->toContain('id="callback-code"')
        ->toMatch('/\d+ lines/');
});

it('carries the newsletter form in the footer', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)
        ->toContain('action="'.route('newsletter.subscribe').'"')
        ->toContain('name="email"')
        ->toContain('name="company"');
});

it('gives the mobile menu an accessible, escapable, focus-managed contract', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)
        ->toContain('aria-controls="site-menu"')
        ->toContain(':aria-expanded="open"')
        ->toContain('@keydown.escape.window')
        ->toContain("classList.toggle('menu-open', open)")
        ->toContain('$refs.toggle.focus()');
});
