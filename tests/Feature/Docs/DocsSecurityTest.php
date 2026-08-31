<?php

use App\Enums\AppStatus;
use App\Http\Controllers\DocsController;
use App\Models\App;
use App\Models\Publisher;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The `..` guard is a security control rather than a feature: a user-supplied
 * path segment reaches resource_path(). These tests assert the property that
 * makes it one — that it runs *before* the cache lookup and *before* anything
 * touches the filesystem, not merely that the response is a 404.
 */
it('never touches the cache or the filesystem for a traversing slug', function () {
    File::shouldReceive('exists')->never();
    File::shouldReceive('get')->never();
    Cache::shouldReceive('rememberForever')->never();

    expect(fn () => app(DocsController::class)('../../.env'))
        ->toThrow(NotFoundHttpException::class);
});

it('404s a traversal attempt over HTTP', function () {
    $this->get('/docs/../../.env')->assertNotFound();
    $this->get('/docs/'.rawurlencode('../../.env'))->assertNotFound();
});

/**
 * docs/10 §1: the secret is NEVER sent to a docs page. Docs are cacheable,
 * shareable and screenshot-able; a secret in one is a secret in a proxy log.
 * Asserted against the full body of every page, in both auth states.
 */
it('never renders an app secret into any docs response', function () {
    $slugs = array_column(array_merge(...array_column(config('docs.nav'), 'pages')), 'slug');

    $secret = Str::random(40);

    $user = User::factory()->create();
    $publisher = Publisher::factory()->for($user)->create();
    App::factory()->for($publisher)->create([
        'secret_key' => $secret,
        'status' => AppStatus::Active,
    ]);

    // Anonymous first: actingAs() outlives the request it is chained to, so a
    // logged-out assertion after a logged-in one would not be logged out.
    foreach ($slugs as $slug) {
        $body = (string) $this->get("/docs/{$slug}")->assertOk()->getContent();

        expect($body)->not->toContain($secret);
    }

    $this->actingAs($user);

    foreach ($slugs as $slug) {
        $body = (string) $this->get("/docs/{$slug}")->assertOk()->getContent();

        expect($body)->not->toContain($secret);
        // A truncated leak is still a leak.
        expect($body)->not->toContain(substr($secret, 0, 12));
    }
});
