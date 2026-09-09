<?php

namespace App\Http\Controllers;

use App\Models\FraudRule;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Tempest\Highlight\Highlighter;

/**
 * The landing page. Only real numbers ship — a fabricated counter is the
 * fastest way to lose a publisher who checks.
 *
 * There used to be more of them here: a live offer count, a count of countries
 * across the catalogue, a count of active demand partners and the share of
 * publisher callbacks that reached their destination, each with a sample floor
 * and a capability statement to fall back on. They were real and they were
 * still the wrong things to put on a front door. The counts described how long
 * the install had been running rather than what it does for a publisher, the
 * partner count is the one number this surface must never invite anyone to
 * pick apart (AGENTS.md), and a delivery percentage advertises the failures in
 * its own denominator. The proof band states capabilities instead, and every
 * one of those figures is still computed, per placement and per day, on the
 * surfaces that exist to report them — the dashboard, the statistics page and
 * the postback report — for the publisher whose traffic produced it.
 *
 * What is left is the rule count, which the fraud card states, and the terms,
 * which are settings rather than statistics.
 */
class LandingController extends Controller
{
    public function __invoke(): View
    {
        return view('landing', [
            'stats' => $this->stats(),
            'terms' => $this->terms(),
            'snippet' => $this->snippet(),
        ]);
    }

    /**
     * Cached for an hour: the rule count moves when an admin changes a rule,
     * not per request, and the homepage should not open a table scan for every
     * visitor.
     *
     * A missing key is a figure the page does not show. The page is the front
     * door — it stays up when the database does not, and it renders before
     * anyone has run a migration.
     *
     * @return array<string, int|float>
     */
    private function stats(): array
    {
        try {
            return Cache::remember('landing.stats', 3600, fn (): array => [
                'fraud_rules' => FraudRule::query()->where('enabled', true)->count(),
            ]);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * The numbers the terms section states, read the way the panels read them:
     * the settings table first, the config default behind it. Formatted here
     * so "20.00" never reaches the page as anything but "20".
     *
     * The revenue share is deliberately not among them. It is a per-publisher
     * commercial term, and a rate on a public page anchors every negotiation
     * that follows it and tells a publisher on a different rate where they
     * stand. The strip states the capability instead.
     *
     * @return array{hold_days: string, min_payout: string}
     */
    private function terms(): array
    {
        $figure = fn (string $key, mixed $fallback): string => rtrim(rtrim(number_format((float) ($this->setting($key) ?? $fallback), 2, '.', ''), '0'), '.');

        return [
            'hold_days' => $figure('default_hold_days', config('playndgain.default_hold_days')),
            'min_payout' => $figure('default_min_payout', config('playndgain.default_min_payout')),
        ];
    }

    private function setting(string $key): mixed
    {
        try {
            return Setting::get($key);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The callback handler on the integration panel, highlighted on the server
     * with the same highlighter the documentation uses: the client bundle never
     * learns a grammar, and a reader with JavaScript off still sees colour.
     *
     * The parameter names are the macro names from the docs, not shorthand —
     * a visitor who copies this into a file has copied something that works
     * against the callback URL the quick start builds. The dedupe key is
     * (trans_id, status) because a reversal carries the same trans_id as the
     * credit it undoes; dedupe on the id alone and the chargeback is swallowed.
     *
     * @return array{html: string, lines: int}
     */
    private function snippet(): array
    {
        $code = <<<'PHP'
        $expected = hash('sha256',
            $_GET['trans_id'] . $_GET['payout_usd'] . PLAYNDGAIN_SECRET);

        if (! hash_equals($expected, $_GET['signature'])) {
            http_response_code(403);
            exit;
        }

        // we retry — you WILL see this twice
        if (Tx::where('trans_id', $_GET['trans_id'])
              ->where('status', $_GET['status'])->exists()) {
            exit('ok');
        }

        // negative on a reversal — handle it
        $user->increment('coins', (int) $_GET['currency_amount']);

        echo 'ok';
        PHP;

        return [
            'html' => (new Highlighter)->parse($code, 'php'),
            'lines' => substr_count($code, "\n") + 1,
        ];
    }
}
