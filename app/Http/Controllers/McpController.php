<?php

namespace App\Http\Controllers;

use App\Support\Docs\DocSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * A read-only MCP server over the developer docs — docs/10-developer-docs.md §2.
 *
 * Model Context Protocol, Streamable HTTP transport, JSON-RPC 2.0. A coding
 * assistant configured with `{APP_URL}/mcp` can list, read and search the
 * published pages instead of being pasted them. It has three tools and no
 * state: no sessions, no server-initiated stream (GET is 405, as the
 * specification says it should be when there is nothing to stream), and no
 * authentication, because the pages are public. It is throttled per IP for
 * the same reason the contact form is.
 *
 * The docs are the only thing it can reach. It resolves every slug against
 * the nav through DocSource, so an unpublished draft is as invisible here as
 * it is on the site.
 */
class McpController extends Controller
{
    private const PROTOCOL = '2025-06-18';

    /** Protocol revisions this server speaks; an unknown one gets the latest. */
    private const PROTOCOLS = ['2024-11-05', '2025-03-26', '2025-06-18'];

    private const SEARCH_LIMIT = 5;

    public function __invoke(Request $request): Response|JsonResponse
    {
        if (! $request->isMethod('POST')) {
            return response('', 405, ['Allow' => 'POST']);
        }

        $message = json_decode((string) $request->getContent(), true);

        if (! is_array($message)) {
            return $this->error(null, -32700, 'Parse error');
        }

        $method = $message['method'] ?? null;
        $id = $message['id'] ?? null;
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        if (($message['jsonrpc'] ?? null) !== '2.0' || ! is_string($method)) {
            return $this->error($id, -32600, 'Invalid request');
        }

        // A notification has no id and expects no body.
        if ($id === null) {
            return response('', 202);
        }

        return match ($method) {
            'initialize' => $this->result($id, $this->initialize($params)),
            'ping' => $this->result($id, (object) []),
            'tools/list' => $this->result($id, ['tools' => $this->tools()]),
            'tools/call' => $this->call($id, $params),
            default => $this->error($id, -32601, "Method not found: {$method}"),
        };
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        $requested = $params['protocolVersion'] ?? null;

        return [
            'protocolVersion' => in_array($requested, self::PROTOCOLS, true) ? $requested : self::PROTOCOL,
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => DocSource::brand().' docs', 'version' => (string) config('docs.version')],
            'instructions' => DocSource::brand().' developer documentation: embedding the offerwall, the '
                .'conversion callback, signature verification, reversals, retries, testing and payouts. '
                .'Use search_docs to find a page, read_page to read it in full, list_pages to see the set.',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tools(): array
    {
        return [
            [
                'name' => 'list_pages',
                'description' => 'List every documentation page with its slug, title and section, in reading order.',
                'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false],
            ],
            [
                'name' => 'read_page',
                'description' => 'Read one documentation page as Markdown. Pass the slug from list_pages or search_docs.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['slug' => ['type' => 'string', 'description' => 'The page slug, e.g. "signature-verification".']],
                    'required' => ['slug'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'search_docs',
                'description' => 'Search the documentation. Returns the best-matching pages with an excerpt around the match.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['query' => ['type' => 'string', 'description' => 'Words to look for.']],
                    'required' => ['query'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function call(mixed $id, array $params): JsonResponse
    {
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        $text = match ($params['name'] ?? null) {
            'list_pages' => $this->listPages(),
            'read_page' => $this->readPage((string) ($arguments['slug'] ?? '')),
            'search_docs' => $this->search((string) ($arguments['query'] ?? '')),
            default => null,
        };

        if ($text === null) {
            return $this->error($id, -32602, 'Unknown tool: '.(string) ($params['name'] ?? ''));
        }

        return $this->result($id, [
            'content' => [['type' => 'text', 'text' => $text]],
            'isError' => false,
        ]);
    }

    private function listPages(): string
    {
        $lines = [];

        foreach (DocSource::nav() as $group) {
            $lines[] = '## '.$group['section'];

            foreach ($group['pages'] as $page) {
                $lines[] = "- {$page['slug']}: {$page['title']} ({APP_URL}/docs/{$page['slug']})";
            }

            $lines[] = '';
        }

        return DocSource::substitute(trim(implode("\n", $lines)));
    }

    private function readPage(string $slug): string
    {
        $raw = DocSource::raw($slug);

        if ($raw === null) {
            return "No page with slug \"{$slug}\". Call list_pages for the published set.";
        }

        return DocSource::substitute(DocSource::asPage($slug, $raw));
    }

    private function search(string $query): string
    {
        $terms = array_values(array_filter(
            preg_split('/[^\pL\pN]+/u', mb_strtolower(trim($query))) ?: [],
            fn (string $term): bool => mb_strlen($term) > 1,
        ));

        if ($terms === []) {
            return 'Give search_docs at least one word to look for.';
        }

        $hits = [];

        foreach (DocSource::pages() as $page) {
            $raw = DocSource::raw($page['slug']);

            if ($raw === null) {
                continue;
            }

            $split = DocSource::split($page['slug'], $raw);
            $text = self::plain($split['body']);
            $haystack = mb_strtolower($page['title'].' '.$text);
            $score = 0;
            $first = null;

            foreach ($terms as $term) {
                $count = mb_substr_count($haystack, $term);

                if ($count === 0) {
                    continue;
                }

                // A title hit outranks any number of body hits.
                $score += $count + (str_contains(mb_strtolower($page['title']), $term) ? 10 : 0);

                $position = mb_stripos($text, $term);

                if ($position !== false && ($first === null || $position < $first)) {
                    $first = $position;
                }
            }

            if ($score > 0) {
                $hits[] = ['page' => $page, 'score' => $score, 'excerpt' => self::excerpt($text, $first ?? 0)];
            }
        }

        if ($hits === []) {
            return "Nothing in the documentation matches \"{$query}\".";
        }

        usort($hits, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $lines = [];

        foreach (array_slice($hits, 0, self::SEARCH_LIMIT) as $hit) {
            $lines[] = "## {$hit['page']['title']} ({$hit['page']['slug']})";
            $lines[] = "{APP_URL}/docs/{$hit['page']['slug']}";
            $lines[] = $hit['excerpt'];
            $lines[] = '';
        }

        return DocSource::substitute(trim(implode("\n", $lines)));
    }

    /**
     * Markdown to something an excerpt can be cut from: code fences, tables
     * and link syntax out, whitespace collapsed.
     */
    private static function plain(string $markdown): string
    {
        $text = (string) preg_replace('/```.*?```/s', ' ', $markdown);
        $text = (string) preg_replace('/^\|.*$/m', ' ', $text);
        $text = (string) preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $text);
        $text = (string) preg_replace('/[#*`>_]+/', '', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function excerpt(string $text, int $at): string
    {
        $start = max(0, $at - 80);
        $slice = mb_substr($text, $start, 260);

        return ($start > 0 ? '…' : '').Str::limit(trim($slice), 240);
    }

    /**
     * @param  array<string, mixed>|object  $result
     */
    private function result(mixed $id, array|object $result): JsonResponse
    {
        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    private function error(mixed $id, int $code, string $message): JsonResponse
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ], $code === -32700 || $code === -32600 ? 400 : 200);
    }
}
