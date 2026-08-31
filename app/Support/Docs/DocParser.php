<?php

namespace App\Support\Docs;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Inline\HtmlInline;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Node\Node;
use League\CommonMark\Node\StringContainerHelper;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Renderer\HtmlRenderer;
use Tempest\Highlight\CommonMark\CodeBlockRenderer;
use Tempest\Highlight\Highlighter;

/**
 * Markdown → the array a docs page renders from — docs/10-developer-docs.md §1.
 *
 * Everything here happens once per page per deploy: the controller caches the
 * result under the config('docs.version') key, so the cost of parsing, of
 * highlighting five languages, and of walking the tree twice is paid on the
 * first request after a deploy and never again.
 */
final class DocParser
{
    /**
     * @return array{title: string, description: string, html: string, toc: list<array{id: string, text: string, level: int}>}
     */
    public static function parse(string $raw): array
    {
        [$meta, $markdown] = self::splitFrontMatter($raw);

        $environment = new Environment([
            // Pages embed Alpine components — the postback URL builder, the
            // language tabs. Docs Markdown is written by us and version
            // controlled; it is not user input.
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
        ]);

        $environment
            ->addExtension(new CommonMarkCoreExtension)
            ->addExtension(new TableExtension)
            // Server-side highlighting for fenced blocks only. The client
            // bundle never learns a language grammar, and a reader with JS off
            // still sees colour.
            //
            // The renderer is registered directly instead of through
            // HighlightExtension because that extension also replaces the
            // *inline* code renderer, which reads a leading `{word}` as a
            // language tag — and this documentation is a macro reference where
            // every second inline code span is `{trans_id}`. Registering the
            // extension renders the entire macro table as empty <code> tags.
            ->addRenderer(FencedCode::class, new CodeBlockRenderer(new Highlighter), 10);

        $document = (new MarkdownParser($environment))->parse($markdown);

        $toc = self::anchorHeadings($document);

        $html = (new HtmlRenderer($environment))->renderDocument($document);

        return [
            'title' => $meta['title'] ?? 'Documentation',
            'description' => $meta['description'] ?? '',
            'html' => self::wrapBlocks((string) $html),
            'toc' => $toc,
        ];
    }

    /**
     * Prose of a page, for the search index.
     *
     * Code blocks and the copy buttons attached to them are dropped first: an
     * excerpt that opens with a shell snippet tells a searcher nothing, and the
     * word "Copy" would otherwise lead every page that starts with an example.
     */
    public static function toText(string $html): string
    {
        $prose = (string) preg_replace('#<(pre|button)\b.*?</\1>#s', ' ', $html);

        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($prose), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    /**
     * A flat `key: value` front matter reader.
     *
     * league/commonmark's FrontMatterExtension needs symfony/yaml, which is not
     * installed, and the front matter here is two scalar keys. Adding a YAML
     * dependency to read `title:` would be the more expensive of the two.
     *
     * @return array{0: array<string, string>, 1: string}
     */
    private static function splitFrontMatter(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);

        if (! preg_match('/^---\n(.*?)\n---\n?(.*)$/s', $raw, $matches)) {
            return [[], $raw];
        }

        $meta = [];

        foreach (explode("\n", $matches[1]) as $line) {
            if (preg_match('/^([A-Za-z_][\w-]*):\s*(.*)$/', trim($line), $pair)) {
                $meta[$pair[1]] = trim($pair[2], " \"'");
            }
        }

        return [$meta, $matches[2]];
    }

    /**
     * Gives every h2/h3 a stable id, appends its anchor link, and returns the
     * table of contents in document order.
     *
     * One walk builds both, so the ToC ids and the heading ids cannot drift —
     * two separate slug generators is how a docs site ends up with a sidebar
     * full of links that scroll nowhere.
     *
     * @return list<array{id: string, text: string, level: int}>
     */
    private static function anchorHeadings(Node $document): array
    {
        $toc = [];
        $seen = [];

        foreach ($document->iterator() as $node) {
            if (! $node instanceof Heading || $node->getLevel() > 3) {
                continue;
            }

            $text = StringContainerHelper::getChildText($node);
            $id = self::slug($text);

            $seen[$id] = ($seen[$id] ?? 0) + 1;

            if ($seen[$id] > 1) {
                $id .= '-'.$seen[$id];
            }

            $node->data->set('attributes/id', $id);
            $node->appendChild(new HtmlInline(
                '<a class="doc-anchor" href="#'.$id.'" aria-label="Link to this section">#</a>'
            ));

            $toc[] = ['id' => $id, 'text' => $text, 'level' => $node->getLevel()];
        }

        return $toc;
    }

    private static function slug(string $text): string
    {
        $slug = strtolower((string) preg_replace('/[^\pL\pN]+/u', '-', $text));

        return trim($slug, '-') ?: 'section';
    }

    /**
     * Wraps code blocks so a copy button has somewhere to live, and tables so a
     * wide one scrolls instead of stretching the page.
     *
     * Done here rather than in the client because the wrapper is part of the
     * cached HTML — injecting it from JS would mean the layout shifts once the
     * bundle boots, on every page, forever. Neither `<pre>` nor `<table>` nests
     * in this content, so replacing the open and close tags in sequence is sound.
     */
    private static function wrapBlocks(string $html): string
    {
        $code = <<<'HTML'
            <div class="doc-code" x-data="docsCopy">
            <button type="button" class="doc-copy" x-cloak @click="copy" x-text="label">Copy</button>
            <pre
            HTML;

        return str_replace(
            ['<pre', '</pre>', '<table>', '</table>'],
            [$code, '</pre></div>', '<div class="doc-table-wrap"><table>', '</table></div>'],
            $html
        );
    }
}
