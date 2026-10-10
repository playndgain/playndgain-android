<?php

/*
 * The published doc set, in reading order — docs/10-developer-docs.md §2.
 *
 * Ordered, not alphabetical: prev/next walks this array, and the order is the
 * order an integration actually happens in. Every entry must have a matching
 * resources/docs/{slug}.md.
 *
 * The sections follow one conversion from the player's tap to the money in
 * your account, because that is the order the questions arrive in. Pages that
 * answer the same question share a page rather than cross-referencing each
 * other: retries and duplicates are one problem, a test offer and a test
 * postback are one job, a symptom and its status code are one lookup.
 *
 * Slugs retired in the restructure are redirected in DocsController, not
 * deleted from the world — they are in publishers' bookmarks and in mails we
 * have already sent.
 */

return [
    [
        'section' => 'Start here',
        'pages' => [
            ['slug' => 'introduction', 'title' => 'Introduction'],
            ['slug' => 'quick-start', 'title' => 'Quick start'],
            ['slug' => 'core-concepts', 'title' => 'Core concepts'],
        ],
    ],
    [
        'section' => 'The wall',
        'pages' => [
            ['slug' => 'offerwall-iframe', 'title' => 'Embedding the wall'],
            ['slug' => 'mobile-sdk', 'title' => 'Mobile SDKs'],
            ['slug' => 'url-parameters', 'title' => 'URL parameters'],
            ['slug' => 'signed-urls', 'title' => 'Signed wall URLs'],
            ['slug' => 'theming', 'title' => 'Theming'],
            ['slug' => 'multiple-placements', 'title' => 'One app or several'],
        ],
    ],
    [
        'section' => 'The callback',
        'pages' => [
            ['slug' => 'how-postbacks-work', 'title' => 'How the callback works'],
            ['slug' => 'macro-reference', 'title' => 'Macro reference'],
            ['slug' => 'signature-verification', 'title' => 'Verifying the signature'],
            ['slug' => 'chargebacks-reversals', 'title' => 'Reversals'],
            ['slug' => 'retries-and-failure', 'title' => 'Retries and duplicates'],
        ],
    ],
    [
        'section' => 'Testing and going live',
        'pages' => [
            ['slug' => 'test-mode', 'title' => 'Testing your integration'],
            ['slug' => 'troubleshooting', 'title' => 'Troubleshooting'],
            ['slug' => 'going-live', 'title' => 'Going live'],
        ],
    ],
    [
        'section' => 'Money',
        'pages' => [
            ['slug' => 'your-payout', 'title' => 'Your payout and your rate'],
            ['slug' => 'statements-and-payouts', 'title' => 'Statements and payouts'],
            ['slug' => 'the-ledger', 'title' => 'The ledger'],
        ],
    ],
    [
        'section' => 'Reference and policies',
        'pages' => [
            ['slug' => 'countries-and-devices', 'title' => 'Countries and devices'],
            ['slug' => 'conversion-statuses', 'title' => 'Conversion statuses'],
            ['slug' => 'rate-limits', 'title' => 'Rate limits'],
            ['slug' => 'traffic-quality', 'title' => 'Traffic rules'],
        ],
    ],
];
