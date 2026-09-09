# 10 — Developer Documentation

Public docs at `/docs`. The KooAds documentation layout is the reference: clear quick start, copyable snippets, macro reference, testing tools, and logs.

**The measure of success:** a competent developer integrates without contacting support. Every section below exists because its absence generates a ticket.

---

## 1. Stack

Same Blade + Alpine + Tailwind app as the landing site. No separate generator, no second build, no Node in production.

| Concern | Choice |
|---|---|
| Content | Markdown files in `resources/docs/**/*.md`, YAML front matter |
| Parsing | `league/commonmark` server-side, cached |
| Highlighting | `tempest/highlight` — **server-side**, zero client JS |
| Rendering | Blade view, HTML passed straight through |
| Navigation | `resources/docs/nav.php` — an explicit ordered array |
| Interactivity | Alpine — search modal, copy buttons, ToC scroll-spy, mobile nav |
| Search | Build-time JSON index + Fuse.js, fetched on first focus |

### Why Markdown-in-Laravel and not a docs generator

Fumadocs, Docusaurus, and every other generator is its own framework with its own build and its own deploy. This app already has a server that can read a file, parse it, and echo HTML. Adding a second static-site pipeline to render nine pages of Markdown is a second thing to maintain for no gain.

```php
class DocsController
{
    public function show(string $slug = 'introduction')
    {
        abort_if(str_contains($slug, '..'), 404);

        $page = Cache::rememberForever("docs.{$slug}." . config('docs.version'), function () use ($slug) {
            $path = resource_path("docs/{$slug}.md");

            abort_unless(File::exists($path), 404);

            return DocParser::parse(File::get($path));   // → ['title', 'html', 'toc', 'description']
        });

        return view('docs.show', [
            'page'        => $page,
            'nav'         => config('docs.nav'),
            'credentials' => $this->readerCredentials(),
        ]);
    }
}
```

The `..` guard runs **before** the cache lookup and before anything touches the filesystem. It is on a user-supplied path segment reaching `resource_path()` — the one line in this file that is a security control rather than a feature.

Cache is keyed on a version string bumped at deploy, so editing a `.md` file and deploying is the whole publishing workflow.

### The payoff of rendering server-side

**Snippets are pre-filled with the reader's real credentials when they are logged in.**

```php
protected function readerCredentials(): ?array
{
    $app = auth()->user()?->publisher?->apps()->where('status', 'active')->first();

    return $app ? [
        'app_id'   => $app->app_id,
        'app_name' => $app->name,
        // The secret is NEVER sent here. Docs link to the panel to reveal it.
    ] : null;
}
```

A logged-in publisher reading the quick start sees `appid=10042` — their actual app — instead of `YOUR_APP_ID`. An anonymous reader sees the placeholder. Same page, same Markdown, one variable.

Placeholders are substituted after parsing, on the cached HTML, so the cache stays shared across all readers:

```php
str_replace(
    ['YOUR_APP_ID', '{APP_URL}'],
    [$credentials['app_id'] ?? 'YOUR_APP_ID', Identity::url()],
    $page['html'],
)
```

**The secret key is never sent to the docs page**, logged in or not. Docs are cacheable, shareable, and screenshot-able. The snippet shows `YOUR_SECRET` with an inline link to the app's Integration tab where the real value lives behind an explicit reveal.

A banner above the first snippet reads: *"Showing values for **Just rewards #10042**. Switch app →"* — with a selector when they have several.

---

## 2. Structure

`resources/docs/nav.php` is the single ordered source. A file not in the nav is not published — no orphan pages.

```
/docs
  Start here
    ├── Introduction              What PLAYNDGAIN is, where the money comes from
    ├── Quick start               Six steps, each verifiable on its own
    └── Core concepts             App, end user, click, conversion, postback, rate

  The wall
    ├── Embedding the wall        The iframe and the four sandbox attributes
    ├── URL parameters            Every parameter, typed
    ├── Signed wall URLs          HMAC, when and how
    ├── Theming                   Colours, radius, dark mode, live preview
    └── One app or several        When to split an integration

  The callback
    ├── How the callback works    The lifecycle, with a diagram
    ├── Macro reference           The full table and the URL builder
    ├── Verifying the signature   Complete handlers in 5 languages
    ├── Reversals                 ← THE important page
    └── Retries and duplicates    Schedule, dedupe, why SELECT-first is broken

  Testing and going live
    ├── Testing your integration  Test mode, the button, and the two curl cases
    ├── Troubleshooting           Symptom → cause → fix, plus every status code
    └── Going live                What approval means and the pre-launch checklist

  Money
    ├── Revenue share and rate    The two numbers and why they are unrelated
    ├── Statements and payouts    The hold, the month-end run, the skip reasons
    └── The ledger                Append-only, and how to reconcile against it

  Reference and policies
    ├── Countries and devices
    ├── Conversion statuses
    ├── Rate limits
    └── Traffic rules             What is measured, and what voids a conversion
```

Six sections, 23 pages.

Every page also has a **Copy page** menu beside its title: copy the page as
Markdown (`/docs/{slug}.md`), copy the integration prompt (`/docs/prompt.txt`,
App ID pre-filled for a signed-in publisher), copy an MCP config pointing at
`/mcp`, copy the `/docs/llms.txt` link, or open the page in Claude or ChatGPT.
`/mcp` is a read-only MCP server (Streamable HTTP, JSON-RPC) with three tools:
`list_pages`, `read_page`, `search_docs`. All of it resolves through
`DocSource`, so an unpublished draft is invisible on every surface. The order follows one conversion from the player's tap
to the money in the publisher's account, because that is the order the questions
arrive in.

Pages that answer the same question live on one page rather than cross-
referencing each other: retries and duplicates are one problem seen from both
ends, a test offer and a test postback are one job, a symptom and its status
code are one lookup.

Slugs retired when this was restructured are 301'd in `DocsController::MOVED`,
never deleted: they are in publishers' bookmarks and in mail already sent.

| Retired | Absorbed by |
|---|---|
| `idempotency` | `retries-and-failure` |
| `test-postback` | `test-mode` |
| `error-codes` | `troubleshooting` |
| `prohibited-traffic` | `traffic-quality` |
| `payout-terms` | `statements-and-payouts` |

---

## 3. Quick Start

The most important page on the site. Five steps, each verifiable, no prose between them that isn't load-bearing. Values shown are the reader's own when logged in.

> **1 · Create an app**
> Dashboard → Apps → New. You get an **App ID** and a **Secret key**. The secret is shown once — store it.
>
> **2 · Embed the offerwall**
> ```html
> <iframe
>   src="{APP_URL}/wall?appid=YOUR_APP_ID&userid=USER_ID"
>   width="100%" height="800" frameborder="0"></iframe>
> ```
> `USER_ID` is **your** identifier for the user. We echo it back on every conversion. Keep it stable — if it changes, the reward goes to the wrong account.
>
> **3 · Set your currency rate**
> Apps → Currency. If `1000 Coins = $1`, a $0.50 offer at 70% rev-share credits your user **350 Coins**.
>
> **4 · Add your callback URL**
> ```
> https://yoursite.com/playndgain/callback?user_id={user_id}&trans_id={trans_id}&amount={currency_amount}&payout={payout_usd}&status={status}&sig={signature}
> ```
>
> **5 · Verify the signature and credit the user**
> ```php
> $expected = hash('sha256', $_GET['trans_id'] . $_GET['payout'] . PLAYNDGAIN_SECRET);
>
> if (! hash_equals($expected, $_GET['sig'])) {
>     http_response_code(403);
>     exit('invalid signature');
> }
>
> // Dedupe: we retry failed callbacks, so you WILL see the same trans_id twice.
> if (Transaction::where('trans_id', $_GET['trans_id'])->exists()) {
>     exit('ok');
> }
>
> // Amount is NEGATIVE on a reversal. Handle it.
> User::find($_GET['user_id'])->increment('coins', (int) $_GET['amount']);
> Transaction::create(['trans_id' => $_GET['trans_id'], 'amount' => $_GET['amount']]);
>
> echo 'ok';   // we need a 2xx, or we retry
> ```
>
> **6 · Test it**
> Apps → Postback → **Send test postback**. Check Postback Reports for the response.

The dedupe check and the negative-amount comment are in the quick start, not buried in a later page, because those two mistakes account for most integration failures in this vertical.

---

## 4. Macro Reference

Rendered from `config/postback-macros.php` — **the same array the `MacroResolver` uses at runtime.** The docs page loops over it. Adding a macro to the resolver publishes it to the docs in the same commit, and there is no way for the two to disagree.

| Macro | Type | Example | Notes |
|---|---|---|---|
| `{user_id}` | string | `USER_123` | Exactly what you passed as `userid` |
| `{trans_id}` | string | `OFFER19624243-Gw2CpY` | Unique. **Dedupe on this.** |
| `{offer_id}` | int | `19624243` | |
| `{offer_name}` | string | `Badoo%3A%20Dating` | URL-encoded |
| `{payout_usd}` | decimal | `0.130000` | Your share, USD, 6 dp. **Negative on reversal.** |
| `{currency_amount}` | decimal | `130.00` | `payout_usd × your rate`. **Negative on reversal.** |
| `{app_id}` | int | `10042` | |
| `{country}` | string | `US` | ISO-2 |
| `{ip}` | string | `167.224.159.233` | User's IP at click time |
| `{status}` | string | `credited` \| `reversed` | |
| `{timestamp}` | int | `1754179200` | Unix seconds |
| `{signature}` | string | `a3f9…` | `sha256(trans_id + payout_usd + secret)` |

**Postback URL builder** — an Alpine component on this page. The reader ticks the macros they want, the URL assembles live, one button copies it. ~25 lines of `x-model` and a computed getter; no server round trip and no framework.

---

## 5. Chargebacks & Reversals

Its own page, prominently linked, because it is where integrations quietly break.

> ### Reversals send a negative amount
>
> When an advertiser rejects a conversion — a refunded purchase, a fraudulent install, a duplicate — the network reverses it and we pass that on:
>
> ```
> ?user_id=USER_123&trans_id=OFFER19624243-Gw2CpY&amount=-130.00
> &payout=-0.130000&status=reversed&sig=…
> ```
>
> - `trans_id` is **the original transaction**, so you can find what to undo.
> - `amount` and `payout` are **negative**.
> - The signature covers the negative payout, so a reversal cannot be replayed as a credit.
>
> **What your code must do:** deduct the currency you credited. If the user already spent it, your policy decides — go negative, cap at zero, or flag the account. What you must not do is ignore reversals, because you will be paying out rewards on revenue you never received.
>
> ```php
> $amount = (float) $_GET['amount'];
>
> if ($amount < 0) {
>     $user->decrement('coins', abs($amount));   // your policy on going negative
>     Transaction::create([...]);                 // record it either way
>     exit('ok');
> }
> ```
>
> Reversals typically arrive within 30 days. They can arrive later.

---

## 6. Troubleshooting

Symptom → cause → fix. The table support links to instead of retyping.

| Symptom | Likely cause | Fix |
|---|---|---|
| Wall shows "No offers available" | Country has no eligible inventory, or a blacklist is too broad | Check Blacklists; use Test mode with a country override |
| Wall shows "Unavailable" | App or account not approved yet | Check status in the dashboard |
| No postback received | Callback URL blank, or your server rejected it | Postback Reports shows every attempt and the response |
| Postbacks return 500 | Your endpoint threw | Response body is logged, truncated to 2 KB |
| Signature always fails | Signing the wrong string, or wrong secret | Concatenate `trans_id + payout_usd` exactly as received, no rounding |
| User credited twice | Not deduping on `trans_id` | We retry failures. Dedupe. |
| Balance dropped unexpectedly | A conversion reversed | Transactions → filter by reversed |
| Earnings pending, not available | Inside the calendar-month hold | Dashboard shows the clearing date per conversion |
| 429 responses | Rate limit | See Reference → Rate limits |

---

## 7. Snippets

Signature verification and the credit handler in **PHP, Node.js, Python, Ruby, and Go**, each complete and runnable — imports included, no `// ...` gaps. A snippet a developer must finish themselves is a snippet that produces a support ticket.

Every snippet includes the dedupe check and the negative-amount branch. Not as a comment — as code.

Language tabs are Alpine `x-show`. **Every language is highlighted server-side and already in the DOM**, so switching tabs toggles visibility — no parse, no fetch, no highlighter in the client bundle. Five hidden `<pre>` blocks cost a few KB of HTML and save 40 KB of JavaScript.

---

## 8. Search

Build-time index, client-side query. No search server, no third-party service.

```bash
php artisan docs:index      # → public/docs-index.json
```

Runs in the deploy script after asset build. Emits `{slug, title, section, headings[], excerpt}` per page — roughly 30 KB for the full doc set.

The client fetches it **on first focus of the search box**, not on page load, and queries it with Fuse.js. Nothing is downloaded by a reader who never searches.

`⌘K` / `Ctrl+K` opens it. Arrow keys navigate, Enter selects, Escape closes, focus returns to the trigger.

---

## 9. Docs Layout

Three columns on desktop, matching the reference: **nav sidebar** (from `nav.php`, current section expanded) · **content** (max 72ch) · **table of contents** (from `toc`, scroll-spy highlighting the active heading).

Below 1024px the ToC collapses into a dropdown above the content; below 768px the nav becomes a slide-over.

**Light theme by default**, dark toggle in the header, persisted in `localStorage` and read before paint by an inline script — a docs site that flashes white on every navigation for dark-theme readers is worse than not offering the toggle.

**Code surfaces stay obsidian in both themes.** Code reads better dark and the contrast makes snippets scannable at a glance.

Every code block gets a copy button (Alpine, `navigator.clipboard`, label swaps to *Copied* for 1.5s). Every heading gets an anchor link. Every page ends with prev/next navigation and an *Edit this page* link to the Markdown file.

Navigation between doc pages is a plain full page load. Cached HTML plus a warm connection makes it fast enough that a client-side router would be complexity for a few hundred milliseconds. Sidebar scroll position is restored by Alpine from `sessionStorage` — six lines, and it fixes the only thing a full reload actually loses.

---

## 10. Writing Rules

- **Second person, imperative.** "Verify the signature", not "the signature should be verified".
- **Every claim testable.** "Retries at 1m, 5m, 30m, 2h, 6h" — not "retries several times".
- **No marketing voice.** Docs are not the landing page.
- **Every code block complete and copyable.**
- **Warn where people fail**, in a callout, at the point of failure — dedupe, negative amounts, stable user IDs, returning 2xx.
- **Never claim compliance we do not have.** We are not a registered IAB TCF vendor and the docs say so plainly on the privacy page.

---

## 11. Tests

- Every slug in `nav.php` resolves to an existing file and returns 200.
- `/docs/../../.env` returns 404 — the traversal guard holds.
- Docs content renders with JS disabled — the page is HTML, and search, tabs, and copy buttons are the only things that degrade.
- Anonymous reader sees `YOUR_APP_ID`; a logged-in publisher with an active app sees their real app ID.
- **The app secret never appears in any docs response**, authenticated or not. Assert against the full response body.
- Macro table renders every key in `config/postback-macros.php` — a macro added to the resolver cannot be missing from the docs.
- `docs:index` emits one entry per nav page.
