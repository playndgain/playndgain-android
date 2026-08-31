<?php

namespace App\Console\Commands;

use App\Support\Docs\DocParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Build-time search index — docs/10-developer-docs.md §8.
 *
 * Runs in the deploy script after the asset build. The client fetches the
 * result on first focus of the search box, so a reader who never searches
 * downloads none of it, and there is no search server to operate.
 */
class DocsIndexCommand extends Command
{
    protected $signature = 'docs:index {--path= : Where to write the index, defaults to public/docs-index.json}';

    protected $description = 'Build the docs search index into public/docs-index.json';

    public function handle(): int
    {
        $entries = [];

        /** @var list<array{section: string, pages: list<array{slug: string, title: string}>}> $nav */
        $nav = config('docs.nav', []);

        foreach ($nav as $group) {
            foreach ($group['pages'] as $page) {
                $path = resource_path("docs/{$page['slug']}.md");

                // A nav entry without a file is a broken link on every page of
                // the site, so the build fails rather than shipping the index.
                if (! File::exists($path)) {
                    $this->error("Missing Markdown for nav entry '{$page['slug']}' (expected {$path}).");

                    return self::FAILURE;
                }

                $parsed = DocParser::parse(File::get($path));

                $entries[] = [
                    'slug' => $page['slug'],
                    'title' => $parsed['title'],
                    'section' => $group['section'],
                    'headings' => array_column($parsed['toc'], 'text'),
                    'excerpt' => Str::limit(DocParser::toText($parsed['html']), 180),
                ];
            }
        }

        $target = (string) ($this->option('path') ?? '') ?: public_path('docs-index.json');

        File::ensureDirectoryExists(dirname($target));
        File::put($target, (string) json_encode($entries, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->info(sprintf('Indexed %d pages → %s (%s KB).', count($entries), $target, number_format(File::size($target) / 1024, 1)));

        return self::SUCCESS;
    }
}
