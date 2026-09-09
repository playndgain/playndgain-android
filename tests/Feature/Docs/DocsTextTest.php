<?php

use App\Enums\AppStatus;
use App\Models\App;
use App\Models\Publisher;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * The text routes feed the Copy page menu and tools that read documentation.
 * Their contract is the pages' contract: every published page, the wall
 * origin from config, and never a secret.
 */
beforeEach(function () {
    Cache::flush();
    config()->set('app.url', 'https://docs.example.test');
});

it('serves every published page as one plain-text Markdown file', function () {
    $body = (string) $this->get('/docs/llms.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
        ->getContent();

    foreach (array_merge(...array_column(config('docs.nav'), 'pages')) as $page) {
        expect($body)
            ->toContain('## '.$page['title'])
            ->toContain('https://docs.example.test/docs/'.$page['slug']);
    }

    expect($body)
        ->toContain('https://docs.example.test/wall?appid=YOUR_APP_ID')
        ->not->toContain('{APP_URL}')
        ->not->toContain("---\ntitle:")
        ->not->toContain('doc-builder')
        ->not->toContain('https://playndgain.com');
});

it('serves one page as Markdown and 404s an unpublished one', function () {
    $body = (string) $this->get('/docs/macro-reference.md')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
        ->getContent();

    expect($body)
        ->toStartWith('# Macro reference')
        ->toContain('Source: https://docs.example.test/docs/macro-reference')
        ->toContain('## The signature')
        ->not->toContain('doc-builder')
        ->not->toContain('{APP_URL}');

    $this->get('/docs/nav.md')->assertNotFound();
    $this->get('/docs/prompts.md')->assertNotFound();
});

it('serves the integration prompt with the placeholder app id to an anonymous reader', function () {
    $body = (string) $this->get('/docs/prompt.txt')
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=3600, public')
        ->getContent();

    expect($body)
        ->toContain('sha256( trans_id + payout_usd + app_secret )')
        ->toContain('PLAYNDGAIN_SECRET')
        ->toContain('https://docs.example.test/wall?appid=YOUR_APP_ID');
});

it('pre-fills the prompt with a signed-in publisher\'s app id and never caches it', function () {
    $user = User::factory()->create();
    $publisher = Publisher::factory()->for($user)->create();
    $app = App::factory()->for($publisher)->create(['status' => AppStatus::Active]);

    $body = (string) $this->actingAs($user)->get('/docs/prompt.txt')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->getContent();

    expect($body)
        ->toContain("appid={$app->app_id}")
        ->not->toContain('YOUR_APP_ID')
        ->not->toContain($app->secret_key ?? "\0never");
});

it('puts the Copy page menu on every page, hidden until Alpine boots', function () {
    $html = (string) $this->get('/docs/retries-and-failure')->assertOk()->getContent();

    expect($html)
        ->toContain('x-data="docsActions(\'retries-and-failure\')" x-cloak')
        ->toContain('/docs/retries-and-failure.md')
        ->toContain('/docs/prompt.txt')
        ->toContain('/docs/llms.txt')
        ->toContain("-docs': { type: 'http', url: '")->toContain("/mcp' } } }")
        ->toContain('https://claude.ai/new?q=')
        ->toContain('https://chatgpt.com/?q=');
});
