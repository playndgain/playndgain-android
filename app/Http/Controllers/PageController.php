<?php

namespace App\Http\Controllers;

use App\Support\Docs\DocParser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;

/**
 * The written pages — legal, about, advertise, status.
 *
 * docs/09 §6 puts legal on the same Markdown → Blade pipeline as the docs, and
 * once that pipeline is being reused for three legal pages there is no argument
 * for hand-writing the other three in Blade. They are prose with headings and a
 * table; a second way of producing prose with headings and a table is a second
 * thing to keep looking the same.
 *
 * There is no allowlist array here because the routes already are one: every
 * page is either a literal path with a `page` default or a `where()` on the
 * legal slug, so nothing user-supplied reaches resource_path() in the first
 * place and the `..` guard the docs controller needs has nothing to guard.
 */
class PageController extends Controller
{
    public function __invoke(string $page): View
    {
        /** @var array{title: string, description: string, html: string, toc: list<array{id: string, text: string, level: int}>} $parsed */
        $parsed = Cache::rememberForever("page.{$page}.".config('docs.version'), function () use ($page): array {
            $path = resource_path("pages/{$page}.md");

            // A route can only be registered for a page that exists, so this is
            // a deploy-time mistake rather than a request-time one — but a 404
            // beats a stack trace on the public site either way.
            abort_unless(File::exists($path), 404);

            return DocParser::parse(File::get($path));
        });

        return view('page', ['page' => $parsed]);
    }
}
