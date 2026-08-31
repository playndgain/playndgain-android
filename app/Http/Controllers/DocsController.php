<?php

namespace App\Http\Controllers;

use App\Enums\AppStatus;
use App\Models\User;
use App\Support\Docs\DocParser;
use App\Support\Identity;
use Illuminate\Support\Facades\Auth;
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
    public function __invoke(string $slug = 'introduction'): View
    {
        abort_if(str_contains($slug, '..'), 404);

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

        $credentials = $this->readerCredentials();

        return view('docs.show', [
            'slug' => $slug,
            'page' => $page,
            // Substituted after parsing, on the cached HTML, so one cache entry
            // serves every reader — the page a logged-in publisher gets differs
            // from an anonymous one by a string replacement, not a second parse.
            'html' => str_replace(
                ['YOUR_APP_ID', '{APP_URL}'],
                [$credentials['app_id'] ?? 'YOUR_APP_ID', Identity::url()],
                $page['html'],
            ),
            'nav' => $this->nav(),
            'credentials' => $credentials,
            'neighbours' => $this->neighbours($slug),
        ]);
    }

    /**
     * The app whose values pre-fill the snippets on this page.
     *
     * The secret is NEVER sent here. Docs link to the panel to reveal it.
     *
     * @return array{app_id: string, app_name: string}|null
     */
    protected function readerCredentials(): ?array
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return null;
        }

        $app = $user->publisher?->apps()->where('status', AppStatus::Active)->first();

        return $app ? [
            'app_id' => (string) $app->app_id,
            'app_name' => (string) $app->name,
        ] : null;
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
