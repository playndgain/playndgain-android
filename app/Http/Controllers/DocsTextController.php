<?php

namespace App\Http\Controllers;

use App\Support\Docs\DocSource;
use App\Support\Docs\ReaderApp;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * The docs as plain text, for the "Copy page" menu and for tools that read
 * documentation — docs/10-developer-docs.md §2.
 *
 *   /docs/llms.txt      every published page, in nav order
 *   /docs/{slug}.md     one page, as the Markdown it renders from
 *   /docs/prompt.txt    the integration prompt for a coding assistant
 *
 * The set and the pages carry no reader substitution beyond the wall origin,
 * so one cached body serves everyone. The prompt does pre-fill the App ID for
 * a signed-in publisher, because it is meant to be pasted straight into an
 * assistant, and it is small enough to build per request. The secret is in
 * none of them.
 */
class DocsTextController extends Controller
{
    public function all(): Response
    {
        $body = Cache::rememberForever('docs.llms.'.config('docs.version'), function (): string {
            $sections = [];

            foreach (DocSource::nav() as $group) {
                $pages = [];

                foreach ($group['pages'] as $page) {
                    $raw = DocSource::raw($page['slug']);

                    if ($raw === null) {
                        continue;
                    }

                    $pages[] = DocSource::asSection($page['slug'], $raw);
                }

                $sections[] = '# '.$group['section']."\n\n".implode("\n\n---\n\n", $pages);
            }

            $head = '# '.DocSource::brand()." developer documentation\n\n"
                ."> Every published page, in reading order. Pages: {APP_URL}/docs\n\n";

            return DocSource::substitute($head.implode("\n\n---\n\n", $sections))."\n";
        });

        return self::text($body);
    }

    public function page(string $slug): Response
    {
        abort_unless(DocSource::isPublished($slug), 404);

        $body = Cache::rememberForever("docs.md.{$slug}.".config('docs.version'), function () use ($slug): string {
            $raw = DocSource::raw($slug);

            abort_if($raw === null, 404);

            return DocSource::substitute(DocSource::asPage($slug, $raw))."\n";
        });

        return self::text($body);
    }

    public function prompt(): Response
    {
        $prompt = DocSource::substitute((string) File::get(resource_path('docs/prompts/integration.txt')));

        $app = ReaderApp::credentials();

        if ($app) {
            $prompt = str_replace('YOUR_APP_ID', $app['app_id'], $prompt);
        }

        return self::text($prompt, private: $app !== null);
    }

    private static function text(string $body, bool $private = false): Response
    {
        return response($body, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'X-Robots-Tag' => 'noindex',
            'Cache-Control' => $private ? 'private, no-store' : 'public, max-age=3600',
        ]);
    }
}
