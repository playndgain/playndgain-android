<?php

namespace App\Support;

/**
 * The words on the landing page, as data.
 *
 * One place for the copy, the step list, the feature cards and the example
 * playbooks, so a sentence is edited where every other sentence lives rather
 * than found by grep across nine partials. Figures are not here: they come
 * from LandingController, which only ships what it can defend.
 */
final class LandingContent
{
    /**
     * Header navigation. Section links come from section(): a bare fragment
     * on the landing page, the same fragment on the home path everywhere
     * else the header renders.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function nav(): array
    {
        return [
            [self::section('publishers'), 'Publishers'],
            [self::section('inventory'), 'Inventory'],
            [self::section('how'), 'How it works'],
            [url('/docs/introduction'), 'Docs'],
            [self::section('terms'), 'Terms'],
        ];
    }

    /**
     * A link to a section of the landing page. On the landing page itself a
     * bare `#id`: the browser treats the click as an in-document move (the
     * hash scroller in site.js eases it), and the response cache can never
     * bake another host's origin into it. On the docs, the legal pages and
     * the contact form — where the header and footer also render and a bare
     * fragment would go nowhere — the same fragment on the home path.
     */
    private static function section(string $id): string
    {
        return (request()->routeIs('landing') ? '' : '/').'#'.$id;
    }

    /**
     * The band under the hero: four things that are true of the product, not
     * four counters.
     *
     * It used to print live figures — offers, countries, partners, callback
     * delivery — with a capability statement standing in wherever a figure was
     * below its sample floor. The figures were real, and they still read badly:
     * on a young install "13 offers · 2 countries · 2 demand partners" invites
     * a publisher to size the network by four numbers that describe how long it
     * has been running rather than what it does for them, and a delivery
     * percentage printed to a decimal advertises the failures in its own
     * denominator. Scale is not the promise being made here, so the band states
     * the promise instead. The operational figures stay where they belong: the
     * dashboard, the statistics page and the postback report, per placement and
     * per day, for the publisher whose traffic produced them.
     *
     * Nothing here needs a sample floor, and nothing here can drift.
     *
     * @return list<array{icon: string, value: string, label: string, note: string}>
     */
    public static function proofs(string $minPayout): array
    {
        return [
            [
                'icon' => 'i-offer',
                'value' => 'One integration',
                'label' => 'Games, surveys, installs',
                'note' => 'One iframe and one catalogue, whoever the demand behind it came from.',
            ],
            [
                'icon' => 'i-palette',
                'value' => 'Your brand',
                'label' => 'On every placement',
                'note' => 'Your colours, your radius, your currency. Nothing in front of your players is ours.',
            ],
            [
                'icon' => 'i-code',
                'value' => 'Signed callbacks',
                'label' => 'On every conversion',
                'note' => 'sha256 over trans_id, payout and your secret. Retried until your server answers.',
            ],
            [
                'icon' => 'i-coin',
                'value' => 'Paid monthly',
                'label' => 'Clearing on the 1st',
                'note' => "Earnings clear on the 1st of the following month, from \${$minPayout}.",
            ],
        ];
    }

    /**
     * The kinds of offer the wall carries, as the page may describe them.
     * Demand partners are internal infrastructure (AGENTS.md): the page speaks
     * of them only in aggregate, never by name or mark, so inventory is
     * presented by category — what a player does, not who pays for it.
     *
     * @return list<array{name: string, icon: string, desc: string}>
     */
    public static function demandCategories(): array
    {
        return [
            ['name' => 'Mobile games', 'icon' => 'i-game', 'desc' => 'Install, finish the tutorial, reach a level — paid per milestone.'],
            ['name' => 'Surveys', 'icon' => 'i-survey', 'desc' => 'Qualify, answer honestly, get credited in minutes.'],
            ['name' => 'Apps & trials', 'icon' => 'i-offer', 'desc' => 'Install, sign up, start a trial or a subscription.'],
            ['name' => 'Fintech', 'icon' => 'i-bank', 'desc' => 'Open an account, verify it, make a first transaction.'],
            ['name' => 'Shopping', 'icon' => 'i-coin', 'desc' => 'Create an account, place a first order, earn cashback.'],
            ['name' => 'Video & engagement', 'icon' => 'i-bolt', 'desc' => 'Watch, return for a week, keep playing.'],
        ];
    }

    /**
     * Nodes on the integration diagram: the demand behind the wall, by kind.
     *
     * @return list<array{label: string, icon: string}>
     */
    public static function orbitNodes(): array
    {
        return [
            ['label' => 'Mobile games', 'icon' => 'i-game'],
            ['label' => 'Surveys', 'icon' => 'i-survey'],
            ['label' => 'App installs', 'icon' => 'i-offer'],
            ['label' => 'Fintech', 'icon' => 'i-bank'],
            ['label' => 'Shopping', 'icon' => 'i-coin'],
            ['label' => 'Video & engagement', 'icon' => 'i-bolt'],
            ['label' => 'Direct brands', 'icon' => 'i-megaphone'],
            ['label' => 'Global demand', 'icon' => 'i-globe'],
        ];
    }

    /**
     * The three steps of the integration.
     *
     * @return list<array{title: string, body: string}>
     */
    public static function steps(): array
    {
        return [
            [
                'title' => 'Embed one iframe',
                'body' => 'Point a WebView or an iframe at the wall URL with your app id and the player\'s id. No SDK, no build step, no app-store review.',
            ],
            [
                'title' => 'Players complete offers',
                'body' => 'Installs, levels, surveys, sign-ups. Each render is filtered to what that player, on that device, in that country, can actually finish.',
            ],
            [
                'title' => 'A signed postback pays you',
                'body' => 'Every completion hits your server signed with your secret. Dedupe on trans_id, return 200, credit the player. Reversals arrive the same way, negative.',
            ],
        ];
    }

    /**
     * What sits between a publisher's first click and their first withdrawal.
     *
     * Two cards left this list. "Signed callbacks" and "Filtered on every
     * render" were each already stated twice elsewhere — the first in the proof
     * band and in step three of the integration, beside the handler it is
     * describing; the second in the product section, next to the capture that
     * shows it happening. A page that says a thing three times has not said it
     * three times as convincingly; it has taught the reader to skim. So each
     * claim keeps the one place where it is load-bearing, and this section is
     * the two things a publisher cannot check for themselves before they
     * integrate: what happens to a click we refuse, and what happens to money
     * after we credit it.
     *
     * @return list<array{icon: string, title: string, body: string, visual: string}>
     */
    public static function features(int $rules): array
    {
        return [
            [
                'icon' => 'i-shield',
                'title' => 'Every blocked click says why',
                'body' => "VPN and proxy checks, device fingerprinting and velocity windows — {$rules} rules run before a click counts. Each block records the rule that fired and the inputs that tripped it, so a dispute has an answer.",
                'visual' => 'verdicts',
            ],
            [
                'icon' => 'i-ledger',
                'title' => 'Nothing is edited after the fact',
                'body' => 'Your balance is the sum of rows that are never changed or deleted. A chargeback is a new negative row carrying the original trans_id — so a reversal is something you can see, not a number that quietly moved.',
                'visual' => 'ledger',
            ],
        ];
    }

    /**
     * Where publishers put the wall. Worked placements, not case studies —
     * each card says "Example", and will until there are accounts to name.
     *
     * The bodies were three sentences each and are now one: the card's job is
     * to let a publisher recognise their own product in it and read on, and a
     * paragraph is not how recognition happens. The captures below them carry
     * the detail the sentences used to.
     *
     * @return list<array{category: string, icon: string, title: string, body: string, image: string, alt: string, note: string, points: list<string>}>
     */
    public static function results(): array
    {
        return [
            [
                'category' => 'Casual game',
                'icon' => 'i-game',
                'title' => 'Coins without a paywall',
                'body' => 'Players earn your currency by finishing offers, and you earn on every completion.',
                'image' => 'wall-dark',
                'alt' => 'The offerwall in a game\'s dark theme, listing game, shopping and survey offers priced in the app\'s own coins',
                'note' => 'Demo placement, real catalogue.',
                'points' => ['Coins per completion', 'Your colours, your currency'],
            ],
            [
                'category' => 'Fintech app',
                'icon' => 'i-bank',
                'title' => 'A rewards tab that pays for itself',
                'body' => 'Cashback for installs and sign-ups, cleared monthly into a ledger that shows reversals too.',
                'image' => 'dashboard',
                'alt' => 'The publisher dashboard: available, pending and lifetime balances beside seven days of clicks and conversions',
                'note' => 'Demo account, seeded traffic.',
                'points' => ['Cashback in cents', 'Paid monthly'],
            ],
            [
                'category' => 'Survey site',
                'icon' => 'i-survey',
                'title' => 'Four SDKs down to one iframe',
                'body' => 'One catalogue and one callback format in place of an integration per demand source.',
                'image' => 'postbacks',
                'alt' => 'The postback report listing signed callbacks with their status and round-trip time',
                'note' => 'Demo account, seeded traffic.',
                'points' => ['One postback format', 'Deduplicated inventory'],
            ],
        ];
    }

    /**
     * Said under every capture on the page, because every capture is of the
     * local demo account.
     *
     * A figure on a marketing page that a reader could take for a live account
     * has to say which it is, and the panels are full of figures. The wall
     * capture says something slightly different — see results(), where its note
     * is "Demo placement, real catalogue": the placement is a demo app, and the
     * rows on it are the catalogue as it really is, each with the artwork its
     * own app publishes. Both sentences are the truth about what is in frame,
     * and neither of them is the same sentence, so they are not shared.
     */
    public static function demoNote(): string
    {
        return 'Demo account, seeded traffic.';
    }

    /**
     * Footer link columns. Section links as in nav().
     *
     * @return array<string, list<array{0: string, 1: string}>>
     */
    public static function footerColumns(): array
    {
        return [
            'Publishers' => [
                [self::section('publishers'), 'The wall'],
                [self::section('how'), 'How it works'],
                [self::section('terms'), 'Terms'],
                ['/dashboard/login', 'Sign in'],
            ],
            'Developers' => [
                [url('/docs/quick-start'), 'Quick start'],
                [url('/docs/macro-reference'), 'Macro reference'],
                [url('/docs/how-postbacks-work'), 'Callbacks'],
                [url('/docs/chargebacks-reversals'), 'Chargebacks'],
            ],
            'Company' => [
                [route('about'), 'About'],
                [route('status'), 'Status'],
                [route('contact'), 'Contact'],
                [url('/legal/privacy'), 'Privacy'],
            ],
        ];
    }
}
