<?php

namespace App\Support\Docs;

use App\Support\Identity;
use Illuminate\Support\Facades\File;

/**
 * The published Markdown, read straight from disk — shared by the text
 * routes and the docs MCP server, so the two never disagree about which
 * pages exist or what a page is called.
 *
 * Everything resolves against the nav first. A file that is not in the nav
 * is not published, on any surface.
 */
final class DocSource
{
    /**
     * @return list<array{section: string, pages: list<array{slug: string, title: string}>}>
     */
    public static function nav(): array
    {
        /** @var list<array{section: string, pages: list<array{slug: string, title: string}>}> $nav */
        $nav = config('docs.nav', []);

        return $nav;
    }

    /**
     * @return list<array{slug: string, title: string, section: string}>
     */
    public static function pages(): array
    {
        $pages = [];

        foreach (self::nav() as $group) {
            foreach ($group['pages'] as $page) {
                $pages[] = $page + ['section' => $group['section']];
            }
        }

        return $pages;
    }

    public static function isPublished(string $slug): bool
    {
        return ! str_contains($slug, '..')
            && in_array($slug, array_column(self::pages(), 'slug'), true);
    }

    /**
     * The file as written, front matter included. Null when unpublished.
     */
    public static function raw(string $slug): ?string
    {
        if (! self::isPublished($slug)) {
            return null;
        }

        $path = resource_path("docs/{$slug}.md");

        return File::exists($path) ? str_replace("\r\n", "\n", File::get($path)) : null;
    }

    /**
     * @return array{title: string, description: string, body: string}
     */
    public static function split(string $slug, string $raw): array
    {
        $title = $slug;
        $description = '';
        $body = $raw;

        if (preg_match('/^---\n(.*?)\n---\n?(.*)$/s', $raw, $matches)) {
            foreach (explode("\n", $matches[1]) as $line) {
                if (preg_match('/^title:\s*(.*)$/', trim($line), $pair)) {
                    $title = trim($pair[1], " \"'");
                } elseif (preg_match('/^description:\s*(.*)$/', trim($line), $pair)) {
                    $description = trim($pair[1], " \"'");
                }
            }

            $body = $matches[2];
        }

        // Inline Alpine builders render nothing without a browser.
        $body = (string) preg_replace('/^<div class="doc-builder".*$/m', '', $body);

        return ['title' => $title, 'description' => $description, 'body' => trim($body)];
    }

    /**
     * One page as a standalone Markdown document.
     */
    public static function asPage(string $slug, string $raw): string
    {
        $page = self::split($slug, $raw);

        return '# '.$page['title']."\n\n"
            .($page['description'] !== '' ? $page['description']."\n\n" : '')
            .'Source: {APP_URL}/docs/'.$slug."\n\n"
            .$page['body'];
    }

    /**
     * One page as a section of the full set: headings drop one level so the
     * section names above them stay the only h1s.
     */
    public static function asSection(string $slug, string $raw): string
    {
        $page = self::split($slug, $raw);

        return '## '.$page['title']."\n\n"
            .($page['description'] !== '' ? $page['description']."\n\n" : '')
            .'Source: {APP_URL}/docs/'.$slug."\n\n"
            .(string) preg_replace('/^(#{1,5}) /m', '#$1 ', $page['body']);
    }

    public static function substitute(string $text): string
    {
        return str_replace('{APP_URL}', Identity::url(), $text);
    }

    public static function brand(): string
    {
        return Identity::name();
    }
}
