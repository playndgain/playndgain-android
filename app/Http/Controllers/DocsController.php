<?php

namespace App\Http\Controllers;

use App\Support\Docs\DocParser;
use App\Support\Docs\ReaderApp;
use App\Support\Identity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;

/**
 * Public developer documentation — docs/10-developer-docs.md.
 *
 * One line here is a security control rather than a feature: the `..` guard on
 * the slug. It is a user-supplied path segment that reaches resource_path(), so
 * it is checked **before** the cache lookup and before anything touches the
 * filesystem.
 *
 * The app secret is never rendered into a snippet. Docs link to the panel to
 * reveal it — a docs page is cacheable and shareable, and a secret in one is a
 * secret in a proxy log.
 */
class DocsController extends Controller
{
    /**
     * Slugs the restructure retired, and the page that absorbed each one.
     *
     * A retired slug is in bookmarks, in mails we have already sent and in the
     * search engines, so it moves rather than 404s. Permanent, because the
     * merge is not something we intend to undo.
     */
    private const MOVED = [
        'idempotency' => 'retries-and-failure',
        'test-postback' => 'test-mode',
        'error-codes' => 'troubleshooting',
        'prohibited-traffic' => 'traffic-quality',
        'payout-terms' => 'statements-and-payouts',
    ];

    public function __invoke(string $slug = 'introduction'): View|RedirectResponse
    {
        abort_if(str_contains($slug, '..'), 404);

        if (isset(self::MOVED[$slug])) {
            return redirect()->route('docs', ['slug' => self::MOVED[$slug]], 301);
        }

        // A file not in the nav is not published. Resolving against the nav
        // before the filesystem also means an unlisted draft cannot be reached
        // by guessing its filename.
        abort_unless($this->isPublished($slug), 404);

        /** @var array{title: string, description: string, html: string, toc: list<array{id: string, text: string, level: int}>} $page */
        $page = Cache::rememberForever("docs.{$slug}.".config('docs.version'), function () use ($slug): array {
            $path = resource_path("docs/{$slug}.md");

            abort_unless(File::exists($path), 404);

            return DocParser::parse(File::get($path));
        });

        $credentials = ReaderApp::credentials();

        return view('docs.show', [
            'slug' => $slug,
            'page' => $page,
            // Substituted after parsing, on the cached HTML, so one cache entry
            // serves every reader — the page a logged-in publisher gets differs
            // from an anonymous one by a string replacement, not a second parse.
            'html' => str_replace(
                ['YOUR_APP_ID', '{APP_URL}/wall', '{APP_URL}'],
                [$credentials['app_id'] ?? 'YOUR_APP_ID', Identity::wallUrl().'/wall', Identity::url()],
                $page['html'],
            ),
            'nav' => $this->nav(),
            'credentials' => $credentials,
            'neighbours' => $this->neighbours($slug),
        ]);
    }

    /**
     * @return list<array{section: string, pages: list<array{slug: string, title: string}>}>
     */
    private function nav(): array
    {
        /** @var list<array{section: string, pages: list<array{slug: string, title: string}>}> $nav */
        $nav = config('docs.nav', []);

        return $nav;
    }

    /**
     * @return list<array{slug: string, title: string}>
     */
    private function pages(): array
    {
        return array_merge(...array_column($this->nav(), 'pages'));
    }

    private function isPublished(string $slug): bool
    {
        return in_array($slug, array_column($this->pages(), 'slug'), true);
    }

    /**
     * Prev/next, walking the nav in reading order across section boundaries.
     *
     * @return array{prev: array{slug: string, title: string}|null, next: array{slug: string, title: string}|null}
     */
    private function neighbours(string $slug): array
    {
        $pages = $this->pages();
        $index = array_search($slug, array_column($pages, 'slug'), true);

        return [
            'prev' => is_int($index) ? ($pages[$index - 1] ?? null) : null,
            'next' => is_int($index) ? ($pages[$index + 1] ?? null) : null,
        ];
    }
}
