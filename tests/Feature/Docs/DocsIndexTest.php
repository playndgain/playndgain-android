<?php

use Illuminate\Support\Facades\File;

/**
 * The search index is built at deploy time and fetched by the client on first
 * focus of the search box. An index missing a page is a page nobody can find.
 */
it('emits one entry per nav page, with the fields the client queries', function () {
    $path = storage_path('framework/testing/docs-index.json');

    $this->artisan('docs:index', ['--path' => $path])->assertSuccessful();

    /** @var list<array{slug: string, title: string, section: string, headings: list<string>, excerpt: string}> $entries */
    $entries = json_decode((string) File::get($path), true);

    $slugs = array_column(array_merge(...array_column(config('docs.nav'), 'pages')), 'slug');

    expect(array_column($entries, 'slug'))->toBe($slugs);

    foreach ($entries as $entry) {
        expect($entry)->toHaveKeys(['slug', 'title', 'section', 'headings', 'excerpt']);
        expect($entry['title'])->not->toBe('Documentation');   // front matter was read
        expect($entry['excerpt'])->not->toBeEmpty();
        expect($entry['headings'])->not->toBeEmpty();
    }

    File::delete($path);
});

it('fails rather than shipping an index with a missing page', function () {
    config()->set('docs.nav', [[
        'section' => 'Broken',
        'pages' => [['slug' => 'does-not-exist', 'title' => 'Nope']],
    ]]);

    $this->artisan('docs:index', ['--path' => storage_path('framework/testing/broken.json')])
        ->assertFailed();
});
