<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cache version
    |--------------------------------------------------------------------------
    |
    | Every parsed page is cached forever under a key that carries this string.
    | Bumping it at deploy invalidates the whole doc set at once, which is what
    | makes "edit a .md file and deploy" the entire publishing workflow — no
    | cache:clear step to forget, and no per-file mtime check on every request.
    |
    */

    'version' => env('DOCS_VERSION', '1'),

    /*
    |--------------------------------------------------------------------------
    | Edit this page
    |--------------------------------------------------------------------------
    |
    | Base URL of the Markdown source, e.g. a GitHub blob URL ending in
    | `resources/docs/`. Null hides the link — a broken "Edit this page" link is
    | worse than no link, so this stays unset until a real repository exists.
    |
    */

    'edit_base' => env('DOCS_EDIT_BASE'),

    /*
    |--------------------------------------------------------------------------
    | Navigation
    |--------------------------------------------------------------------------
    |
    | One explicit ordered array. A Markdown file that is not in the nav is not
    | published — the controller resolves slugs against this list before it
    | touches the filesystem, so there are no orphan pages and no way to reach
    | a draft by guessing its filename.
    |
    */

    'nav' => require __DIR__.'/../resources/docs/nav.php',

];
