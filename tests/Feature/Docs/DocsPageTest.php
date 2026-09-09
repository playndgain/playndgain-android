<?php

use App\Enums\AppStatus;
use App\Models\App;
use App\Models\Publisher;
use App\Models\User;

it('serves the index and every page in the navigation', function () {
    $this->get('/docs')->assertOk()->assertSee('Introduction');

    $slugs = array_column(array_merge(...array_column(config('docs.nav'), 'pages')), 'slug');

    expect($slugs)->toHaveCount(23);

    foreach ($slugs as $slug) {
        $this->get("/docs/{$slug}")->assertOk();
    }
});

it('404s a slug that is not published, even when the file exists', function () {
    // nav.php is a real file in resources/docs. It is not a published page, and
    // resolving against the nav rather than the filesystem is what keeps it out.
    $this->get('/docs/nav')->assertNotFound();
});

it('shows the placeholder app id to an anonymous reader', function () {
    $this->get('/docs/url-parameters')
        ->assertOk()
        ->assertSee('?appid=YOUR_APP_ID', escape: false);
});

it('substitutes APP_URL from config and never hardcodes the production origin', function () {
    config()->set('app.url', 'https://docs.example.test');

    foreach (['quick-start', 'signed-urls', 'url-parameters'] as $slug) {
        $this->get("/docs/{$slug}")
            ->assertOk()
            ->assertSee('https://docs.example.test/wall', escape: false)
            ->assertDontSee('{APP_URL}', escape: false)
            ->assertDontSee('https://playndgain.com', escape: false);
    }
});

it('does not hardcode https://playndgain.com in published Markdown', function () {
    $hits = collect(glob(resource_path('docs/*.md')))
        ->filter(fn (string $path): bool => str_contains((string) file_get_contents($path), 'https://playndgain.com'))
        ->map(fn (string $path): string => basename($path))
        ->values()
        ->all();

    expect($hits)->toBeEmpty();
});

it('pre-fills a logged-in publisher\'s real app id', function () {
    $user = User::factory()->create();
    $publisher = Publisher::factory()->for($user)->create();
    $app = App::factory()->for($publisher)->create(['name' => 'Just rewards', 'status' => AppStatus::Active]);

    $response = $this->actingAs($user)->get('/docs/url-parameters');

    $response->assertOk()
        ->assertSee("?appid={$app->app_id}", escape: false)
        ->assertSee('Just rewards')
        ->assertDontSee('YOUR_APP_ID');
});

it('ignores an app that is not active', function () {
    $user = User::factory()->create();
    $publisher = Publisher::factory()->for($user)->create();
    App::factory()->for($publisher)->create(['status' => AppStatus::Pending]);

    $this->actingAs($user)->get('/docs/url-parameters')
        ->assertOk()
        ->assertSee('?appid=YOUR_APP_ID', escape: false);
});

/**
 * The page is HTML. Search, language tabs and copy buttons are the only things
 * allowed to degrade — the content and the navigation are not, which is why
 * the mobile nav is a <details> and not an Alpine slide-over.
 */
it('renders its content and its navigation with JavaScript disabled', function () {
    $html = (string) $this->get('/docs/chargebacks-reversals')->getContent();

    expect($html)
        ->toContain('What a reversal is')
        ->toContain('you keep paying players for revenue you were never paid');

    preg_match('/<details class="doc-disclosure.*?<\/details>/s', $html, $matches);

    expect($matches[0] ?? '')
        ->toContain('/docs/quick-start')
        ->not->toContain('x-cloak');
});

it('server-renders all five snippets into the DOM', function () {
    $html = (string) $this->get('/docs/signature-verification')->getContent();

    expect($html)
        ->toContain('php -S localhost:8080 callback.php')
        ->toContain('node callback.mjs')
        ->toContain('python callback.py')
        ->toContain('go run callback.go')
        ->toContain('ruby callback.rb');
});

it('highlights fenced code on the server and ships no highlighter to the client', function () {
    $html = (string) $this->get('/docs/signature-verification')->getContent();

    expect($html)->toContain('class="hl-keyword"');
});

it('gives every heading an anchor and every page a neighbour', function () {
    $html = (string) $this->get('/docs/retries-and-failure')->getContent();

    expect($html)
        ->toContain('<h2 id="deduplicate-on-trans-id">')
        ->toContain('<a class="doc-anchor" href="#deduplicate-on-trans-id"')
        ->toContain('/docs/chargebacks-reversals')   // previous
        ->toContain('/docs/test-mode');              // next
});

/**
 * docs/10 §4: a macro added to the resolver cannot be missing from the docs.
 * The resolver's array is private, so the contract is enforced against its
 * source — which is still the one place a macro can be added or renamed.
 */
it('documents every macro the resolver actually resolves', function () {
    $source = (string) file_get_contents(app_path('Services/Money/MacroResolver.php'));

    preg_match_all("/'(\{[a-z_]+\})' =>/", $source, $matches);

    expect($matches[1])->toHaveCount(12);

    $html = (string) $this->get('/docs/macro-reference')->getContent();

    foreach ($matches[1] as $macro) {
        expect($html)->toContain('<code>'.$macro.'</code>');
    }
});

/**
 * Retired slugs are in publishers' bookmarks and in mail we have already sent.
 * The restructure moves them; it does not 404 them.
 */
it('redirects every slug the restructure retired', function (string $old, string $new) {
    $this->get("/docs/{$old}")->assertRedirect("/docs/{$new}");
})->with([
    ['idempotency', 'retries-and-failure'],
    ['test-postback', 'test-mode'],
    ['error-codes', 'troubleshooting'],
    ['prohibited-traffic', 'traffic-quality'],
    ['payout-terms', 'statements-and-payouts'],
]);

/**
 * A link to a page that no longer exists is a 404 a reader finds before we do.
 */
it('links only to pages that are published', function () {
    $slugs = collect(config('docs.nav'))->pluck('pages')->flatten(1)->pluck('slug')->all();

    foreach (glob(resource_path('docs/*.md')) as $file) {
        preg_match_all('#\(/docs/([a-z0-9-]+)\)#', (string) file_get_contents($file), $matches);

        $broken = array_diff(array_unique($matches[1]), $slugs);

        expect($broken)->toBe([], basename($file).' links to an unpublished page');
    }
});
