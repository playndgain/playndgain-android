<?php

/*
 * The published doc set, in reading order — docs/10-developer-docs.md §2.
 *
 * Ordered, not alphabetical: prev/next walks this array, and the order is the
 * order an integration actually happens in. Every entry must have a matching
 * resources/docs/{slug}.md.
 */

return [
    [
        'section' => 'Getting started',
        'pages' => [
            ['slug' => 'introduction', 'title' => 'Introduction'],
            ['slug' => 'quick-start', 'title' => 'Quick start'],
            ['slug' => 'core-concepts', 'title' => 'Core concepts'],
        ],
    ],
    [
        'section' => 'Integration',
        'pages' => [
            ['slug' => 'offerwall-iframe', 'title' => 'Offerwall iframe'],
            ['slug' => 'url-parameters', 'title' => 'URL parameters'],
            ['slug' => 'signed-urls', 'title' => 'Signed URLs'],
            ['slug' => 'theming', 'title' => 'Theming'],
            ['slug' => 'multiple-placements', 'title' => 'Multiple placements'],
        ],
    ],
    [
        'section' => 'Callbacks',
        'pages' => [
            ['slug' => 'how-postbacks-work', 'title' => 'How postbacks work'],
            ['slug' => 'macro-reference', 'title' => 'Macro reference'],
            ['slug' => 'signature-verification', 'title' => 'Signature verification'],
            ['slug' => 'chargebacks-reversals', 'title' => 'Chargebacks & reversals'],
            ['slug' => 'retries-and-failure', 'title' => 'Retries & failure'],
            ['slug' => 'idempotency', 'title' => 'Idempotency'],
        ],
    ],
    [
        'section' => 'Testing',
        'pages' => [
            ['slug' => 'test-mode', 'title' => 'Test mode'],
            ['slug' => 'test-postback', 'title' => 'Sending a test postback'],
            ['slug' => 'troubleshooting', 'title' => 'Troubleshooting'],
        ],
    ],
    [
        'section' => 'Reference',
        'pages' => [
            ['slug' => 'countries-and-devices', 'title' => 'Countries & devices'],
            ['slug' => 'conversion-statuses', 'title' => 'Conversion statuses'],
            ['slug' => 'error-codes', 'title' => 'Error responses'],
            ['slug' => 'rate-limits', 'title' => 'Rate limits'],
        ],
    ],
    [
        'section' => 'Policies',
        'pages' => [
            ['slug' => 'traffic-quality', 'title' => 'Traffic quality'],
            ['slug' => 'prohibited-traffic', 'title' => 'Prohibited traffic'],
            ['slug' => 'payout-terms', 'title' => 'Payout terms'],
        ],
    ],
];
