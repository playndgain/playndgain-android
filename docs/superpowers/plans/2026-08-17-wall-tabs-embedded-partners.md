# Wall Tabs and Embedded Partner Walls Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the hosted offerwall a header tab strip — Offers (today's wall, default) and Offerwall (a partner's own wall embedded in a nested iframe) — with partner conversions credited through the existing ledger.

**Architecture:** A partner's wall is framed, not synced. On tab open we write an `offer_clicks` row with a null `offer_id` and hand the partner its `click_id` as their sub-id; when they postback, the existing `PostbackController` → `ConversionProcessor::record()` path resolves that click and credits exactly as it does for a normal offer. No new money code, no new table, no new endpoint.

**Tech Stack:** Laravel · Blade + Alpine (the tabs are server-rendered links, no new JS) · Filament v5 admin · Pest · MySQL

**Spec:** `docs/superpowers/specs/2026-08-17-wall-tabs-embedded-partners-design.md`

## Global Constraints

- **Wall pages are stateless.** No session, no cookie, no CSRF. Requests authenticate by `appid` + `userid` + `sig`. New routes join the existing `wall` middleware group in `routes/web.php` and carry `throttle:wall`.
- **Every wall response carries `Cache-Control: private, no-store`** — use the `RendersWallPages` trait's `page()` / `notice()`, never a bare `view()`.
- **Every link out of a wall page carries `$context->params()`** (`appid`, `userid`, `sig`, `lang`, `country`). An app with `require_signed_wall` 403s without it.
- **`networks.priority` sorts descending** — higher wins. Matches `OfferFeed` and the admin table.
- **Every value substituted into a partner URL is `rawurlencode`d.** `user_id` is publisher-controlled input landing in a URL.
- **Unknown macros in a partner URL are left literal**, never blanked.
- **`wall_url` must be `https://`.** Checked in the admin form and again before substitution.
- **`offer_clicks` is append-only.** Rows are created carrying their verdict and never edited afterwards (the one existing exception is `converted_at`, stamped by `ConversionProcessor`).
- Run `vendor/bin/pint --dirty` before each commit. Tests: `php artisan test <path>`.

---

### Task 1: Schema and the embeddable scope

**Files:**
- Create: `database/migrations/2026_08_17_000001_add_wall_url_to_networks.php`
- Create: `database/migrations/2026_08_17_000002_make_offer_click_offer_id_nullable.php`
- Modify: `app/Models/Network.php`
- Test: `tests/Feature/Wall/PartnerWallSchemaTest.php`

**Interfaces:**
- Consumes: nothing
- Produces: `networks.wall_url` (nullable text), `networks.wall_new_tab` (bool, default false), a nullable `offer_clicks.offer_id`, and `Network::query()->embeddable()` — an Eloquent scope returning active networks with a `wall_url`, ordered by `priority` descending.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Wall/PartnerWallSchemaTest.php`:

```php
<?php

use App\Models\Network;
use App\Models\OfferClick;
use Database\Factories\OfferClickFactory;

require_once __DIR__.'/WallFixtures.php';

it('stores a click row with no offer on it', function () {
    [$app, $network] = wallFixture();

    // `offer_id` and `network_id` are both explicit: the factory derives the
    // network from the offer, and there is no offer here to derive it from.
    $click = OfferClickFactory::new()->create([
        'app_id' => $app->id,
        'publisher_id' => $app->publisher_id,
        'offer_id' => null,
        'network_id' => $network->id,
    ]);

    expect($click->fresh()->offer_id)->toBeNull()
        ->and(OfferClick::query()->count())->toBe(1);
});

it('lists embeddable networks by priority, and only the ones that qualify', function () {
    $low = Network::factory()->create(['wall_url' => 'https://a.test/w?u={sub_id}', 'priority' => 1]);
    $high = Network::factory()->create(['wall_url' => 'https://b.test/w?u={sub_id}', 'priority' => 9]);
    Network::factory()->create(['wall_url' => null, 'priority' => 20]);
    Network::factory()->disabled()->create(['wall_url' => 'https://c.test/w?u={sub_id}', 'priority' => 30]);

    expect(Network::query()->embeddable()->pluck('id')->all())->toBe([$high->id, $low->id]);
});

it('defaults a network to framing rather than a new tab', function () {
    expect(Network::factory()->create()->wall_new_tab)->toBeFalse();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/Wall/PartnerWallSchemaTest.php`
Expected: FAIL — `Unknown column 'wall_url'`, and `Call to undefined method ...::embeddable()`.

- [ ] **Step 3: Write the networks migration**

`database/migrations/2026_08_17_000001_add_wall_url_to_networks.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            // The partner's own hosted wall, as a macro template. Nullable
            // because most networks are API integrations with no wall of their
            // own to frame — see docs/03-offerwall.md §2.
            $table->text('wall_url')->nullable()->after('website');

            // Set when the partner's frame-ancestors refuses us. The wall then
            // renders a button instead of a grey box nobody can use.
            $table->boolean('wall_new_tab')->default(false)->after('wall_url');
        });
    }

    public function down(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            $table->dropColumn(['wall_url', 'wall_new_tab']);
        });
    }
};
```

- [ ] **Step 4: Write the offer_clicks migration**

`database/migrations/2026_08_17_000002_make_offer_click_offer_id_nullable.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

return new class extends Migration
{
    /**
     * A click on an embedded partner wall has no offer of ours to point at.
     *
     * Written against the raw column type rather than `foreignId()->change()`:
     * the column carries a foreign key, and MySQL keeps it across a MODIFY
     * COLUMN but not across a drop-and-recreate.
     */
    public function up(): void
    {
        Schema::table('offer_clicks', function (Blueprint $table) {
            $table->unsignedBigInteger('offer_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Reversing means declaring every click has an offer again, and the
        // embed rows are the counter-example. Deleting them silently would
        // orphan conversions that were paid for. docs/11 §deploy is explicit
        // that a migration is rolled back only when it is reversible; this one
        // says so out loud rather than destroying evidence.
        if (DB::table('offer_clicks')->whereNull('offer_id')->exists()) {
            throw new RuntimeException(
                'offer_clicks holds rows with no offer (embedded partner walls). '
                .'Rolling back would require deleting them; do it deliberately, not as a side effect.'
            );
        }

        Schema::table('offer_clicks', function (Blueprint $table) {
            $table->unsignedBigInteger('offer_id')->nullable(false)->change();
        });
    }
};
```

- [ ] **Step 5: Add the cast and the scope to the model**

In `app/Models/Network.php`, add to the `$casts` array:

```php
'wall_new_tab' => 'bool',
```

Add the import `use Illuminate\Database\Eloquent\Builder;` and this method below `offers()`:

```php
    /**
     * Partners whose own wall we frame — docs/03 §2.
     *
     * A network qualifies on two conditions, and both are load-bearing: it is
     * switched on, and an admin has pasted the wall URL. Priority sorts the
     * sub-strip, descending, the same direction OfferFeed reads it.
     *
     * @param  Builder<Network>  $query
     */
    public function scopeEmbeddable(Builder $query): void
    {
        $query->where('status', NetworkStatus::Active)
            ->whereNotNull('wall_url')
            ->orderByDesc('priority');
    }
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Wall/PartnerWallSchemaTest.php`
Expected: PASS (3 tests)

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty
git add database/migrations app/Models/Network.php tests/Feature/Wall/PartnerWallSchemaTest.php
git commit -m "Let a network carry a wall of its own, and a click carry no offer"
```

---

### Task 2: Macro substitution

**Files:**
- Create: `app/Services/Wall/PartnerWall.php`
- Modify: `tests/Feature/Wall/WallFixtures.php` (add `partnerContext()`)
- Test: `tests/Feature/Wall/PartnerWallUrlTest.php`

**Interfaces:**
- Consumes: `Network` (Task 1), `WallContext` (exists)
- Produces:
  - `PartnerWall::isEmbeddable(Network $network): bool` — active, `wall_url` filled, and the URL is `https://`
  - `PartnerWall::url(Network $network, WallContext $context, string $subId): string` — the substituted URL
  - `PartnerWall::MACROS` — `list<string>` of macro names, for the admin helper text

- [ ] **Step 1: Add the shared context helper**

`partnerContext()` lives in `WallFixtures.php` and not in a test file — Pest would re-register every `it()` in a test file that another test file `require_once`s. Append to `tests/Feature/Wall/WallFixtures.php`, with `use App\Services\Wall\WallContext;` and `use Illuminate\Http\Request;` added to its imports:

```php
/**
 * A real WallContext, built the way a wall controller builds one.
 *
 * @param  array<string, string>  $extra
 */
function partnerContext(App $app, string $user = 'USER_1', array $extra = []): WallContext
{
    $request = Request::create(wallUrl($app, $user, $extra), 'GET', server: ['HTTP_USER_AGENT' => WALL_UA]);

    return app(WallGate::class)->context($request, $app);
}
```

`WallFixtures.php` already imports `App`; add `use App\Services\Wall\WallGate;` as well.

- [ ] **Step 2: Write the failing test**

`tests/Feature/Wall/PartnerWallUrlTest.php`:

```php
<?php

use App\Models\Network;
use App\Services\Wall\PartnerWall;

require_once __DIR__.'/WallFixtures.php';

it('substitutes every macro it knows', function () {
    wallGeo('US');
    [$app] = wallFixture();

    $network = Network::factory()->create([
        'wall_url' => 'https://p.test/w?s={sub_id}&u={user_id}&a={app_id}&c={country}&d={device}&l={lang}',
    ]);

    $url = PartnerWall::url($network, partnerContext($app, 'USER_1', ['lang' => 'ar']), 'SUB-42');

    expect($url)->toBe(
        "https://p.test/w?s=SUB-42&u=USER_1&a={$app->app_id}&c=US&d=android&l=ar"
    );
});

it('url-encodes what it substitutes', function () {
    wallGeo('US');
    [$app] = wallFixture();

    $network = Network::factory()->create(['wall_url' => 'https://p.test/w?u={user_id}']);

    // `|` and `:` are legal in an end user ID (WallGate::endUserId) and both
    // have meaning in a URL.
    $url = PartnerWall::url($network, partnerContext($app, 'a|b:c'), 'SUB-1');

    expect($url)->toBe('https://p.test/w?u=a%7Cb%3Ac');
});

it('leaves a macro it does not know exactly where it found it', function () {
    wallGeo('US');
    [$app] = wallFixture();

    $network = Network::factory()->create(['wall_url' => 'https://p.test/w?s={sub_id}&x={clickid}']);

    // Blanking it would turn a setup mistake into a payout mystery weeks later.
    expect(PartnerWall::url($network, partnerContext($app), 'SUB-1'))
        ->toBe('https://p.test/w?s=SUB-1&x={clickid}');
});

it('empties a macro whose value we never established', function () {
    wallGeo(null);
    [$app] = wallFixture();

    $network = Network::factory()->create(['wall_url' => 'https://p.test/w?c={country}']);

    expect(PartnerWall::url($network, partnerContext($app), 'SUB-1'))->toBe('https://p.test/w?c=');
});

it('refuses a wall url that is not https', function () {
    expect(PartnerWall::isEmbeddable(Network::factory()->create(['wall_url' => 'http://p.test/w'])))->toBeFalse()
        ->and(PartnerWall::isEmbeddable(Network::factory()->create(['wall_url' => 'javascript:alert(1)'])))->toBeFalse()
        ->and(PartnerWall::isEmbeddable(Network::factory()->create(['wall_url' => null])))->toBeFalse()
        ->and(PartnerWall::isEmbeddable(Network::factory()->disabled()->create(['wall_url' => 'https://p.test/w'])))->toBeFalse()
        ->and(PartnerWall::isEmbeddable(Network::factory()->create(['wall_url' => 'https://p.test/w'])))->toBeTrue();
});
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `php artisan test tests/Feature/Wall/PartnerWallUrlTest.php`
Expected: FAIL — `Class "App\Services\Wall\PartnerWall" not found`

- [ ] **Step 4: Write the implementation**

`app/Services/Wall/PartnerWall.php`:

```php
<?php

namespace App\Services\Wall;

use App\Models\Network;

/**
 * A partner's own hosted wall, addressed by macro template — docs/03 §2.
 *
 * The template is admin input; the values substituted into it are end-user
 * input. That asymmetry is the whole of this class: the shape of the URL is
 * trusted, everything poured into it is not.
 */
final class PartnerWall
{
    /** The public contract. Named in the admin helper text and in the spec. */
    public const MACROS = ['sub_id', 'user_id', 'app_id', 'country', 'device', 'lang'];

    public static function isEmbeddable(Network $network): bool
    {
        return $network->isActive()
            && is_string($network->wall_url)
            // Not `str_contains`: `javascript:alert('https://')` contains it.
            && str_starts_with(strtolower($network->wall_url), 'https://');
    }

    public static function url(Network $network, WallContext $context, string $subId): string
    {
        $values = [
            'sub_id' => $subId,
            'user_id' => $context->endUserId,
            'app_id' => (string) $context->app->app_id,
            'country' => (string) $context->country,
            'device' => $context->device->value,
            'lang' => $context->locale,
        ];

        $search = [];
        $replace = [];

        // Built from the whitelist rather than from the template, so a macro
        // nobody defined survives the substitution untouched. A silently
        // emptied `{clickid}` is an integration that fails at payout time
        // instead of at setup time.
        foreach (self::MACROS as $macro) {
            $search[] = '{'.$macro.'}';
            $replace[] = rawurlencode($values[$macro]);
        }

        return str_replace($search, $replace, (string) $network->wall_url);
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Wall/PartnerWallUrlTest.php`
Expected: PASS (5 tests)

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add app/Services/Wall/PartnerWall.php tests/Feature/Wall/WallFixtures.php tests/Feature/Wall/PartnerWallUrlTest.php
git commit -m "Pour the wall's own values into a partner's URL, encoded"
```

---

### Task 3: The embed click row

**Files:**
- Create: `app/Services/Wall/EmbedClick.php`
- Test: `tests/Feature/Wall/EmbedClickTest.php`

**Interfaces:**
- Consumes: `PartnerWall` (Task 2), `WallContext`, `FraudPipeline`, `IpAnonymizer` (all exist)
- Produces: `EmbedClick::open(WallContext $context, Network $network): array{OfferClick, bool}` — the click row and whether this open is blocked. Destructure as `[$click, $blocked]`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Wall/EmbedClickTest.php`:

```php
<?php

use App\Models\Network;
use App\Models\OfferClick;
use App\Services\Wall\EmbedClick;

require_once __DIR__.'/WallFixtures.php';

it('writes one row per app, partner and end user, and reuses it', function () {
    wallGeo('US');
    [$app] = wallFixture();
    $partner = Network::factory()->create(['wall_url' => 'https://p.test/w?s={sub_id}']);

    [$first] = app(EmbedClick::class)->open(partnerContext($app), $partner);
    [$second] = app(EmbedClick::class)->open(partnerContext($app), $partner);

    expect(OfferClick::query()->count())->toBe(1)
        ->and($second->id)->toBe($first->id)
        ->and($first->offer_id)->toBeNull()
        ->and($first->network_id)->toBe($partner->id)
        ->and($first->app_id)->toBe($app->id)
        ->and($first->publisher_id)->toBe($app->publisher_id)
        ->and($first->end_user_id)->toBe('USER_1')
        ->and($first->country)->toBe('US')
        ->and($first->click_id)->not->toBeNull();
});

it('gives a different end user a different row', function () {
    wallGeo('US');
    [$app] = wallFixture();
    $partner = Network::factory()->create(['wall_url' => 'https://p.test/w?s={sub_id}']);

    app(EmbedClick::class)->open(partnerContext($app, 'USER_1'), $partner);
    app(EmbedClick::class)->open(partnerContext($app, 'USER_2'), $partner);

    expect(OfferClick::query()->count())->toBe(2);
});

it('writes the row even when the open is blocked', function () {
    wallGeo('US');
    [$app] = wallFixture();
    $partner = Network::factory()->create(['wall_url' => 'https://p.test/w?s={sub_id}']);

    // A refusal with no row is a support question nobody can answer —
    // ClickController states the principle, this is the same path.
    app()->instance(App\Fraud\FraudPipeline::class, new class(app(App\Fraud\RuleRegistry::class)) extends App\Fraud\FraudPipeline
    {
        public function evaluate(App\Enums\FraudStage $stage, App\Fraud\FraudContext $context): App\Fraud\Decision
        {
            return new App\Fraud\Decision(true, 100, 'stubbed_block');
        }
    });

    [$click, $blocked] = app(EmbedClick::class)->open(partnerContext($app), $partner);

    expect($blocked)->toBeTrue()
        ->and($click->exists)->toBeTrue()
        ->and($click->blocked)->toBeTrue()
        ->and($click->blocked_reason)->toBe('stubbed_block')
        ->and($click->risk_score)->toBe(100);
});

it('masks the ip when the app asks for it', function () {
    wallGeo('US');
    [$app] = wallFixture(['anonymize_ip' => true]);
    $partner = Network::factory()->create(['wall_url' => 'https://p.test/w?s={sub_id}']);

    [$click] = app(EmbedClick::class)->open(partnerContext($app), $partner);

    expect($click->ip)->toBe('127.0.0.0');
});

it('marks the open as test earnings only when the country was overridden', function () {
    wallGeo('US');
    [$live] = wallFixture();
    [$testing] = wallFixture(['test_mode' => true]);
    $partner = Network::factory()->create(['wall_url' => 'https://p.test/w?s={sub_id}']);

    [$plain] = app(EmbedClick::class)->open(partnerContext($live), $partner);
    [$overridden] = app(EmbedClick::class)->open(partnerContext($testing, 'USER_9', ['country' => 'DE']), $partner);

    expect($plain->is_test)->toBeFalse()
        ->and($overridden->is_test)->toBeTrue()
        ->and($overridden->country)->toBe('DE');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/Wall/EmbedClickTest.php`
Expected: FAIL — `Target class [App\Services\Wall\EmbedClick] does not exist.`

- [ ] **Step 3: Write the implementation**

`app/Services/Wall/EmbedClick.php`:

```php
<?php

namespace App\Services\Wall;

use App\Enums\FraudStage;
use App\Fraud\FraudPipeline;
use App\Models\Network;
use App\Models\OfferClick;

/**
 * The click row behind an embedded partner wall — docs/03 §2, steps 10–11.
 *
 * There is no offer to click, but `ConversionProcessor` attributes a
 * conversion entirely off a click: app, publisher, end user, country, IP,
 * is_test. So the row is what a partner's postback resolves back to, and its
 * `click_id` is the sub-id we hand the partner.
 *
 * One row per (app, partner, end user), reused on every later open. Three
 * reasons: `offer_clicks` stays append-only, the partner keeps a stable sub-id
 * across sessions, and the busiest table we own does not gain a row every time
 * someone taps between tabs.
 *
 * The rule is best effort, not a constraint — no unique index can cover that
 * tuple while real clicks share the table, so two simultaneous first opens race
 * into two rows. Harmless: attribution keys on whichever click_id the partner
 * echoes back, and both point at the same app and end user.
 */
final class EmbedClick
{
    public function __construct(private readonly FraudPipeline $pipeline) {}

    /**
     * @return array{OfferClick, bool} The row, and whether this open is blocked.
     */
    public function open(WallContext $context, Network $network): array
    {
        // Evaluated on every open, not only on the first: the row is a log of
        // first contact, the verdict is about now.
        $decision = $this->pipeline->evaluate(FraudStage::PreClick, $context->fraudContext());

        // `withoutGlobalScopes()` because `OfferClick` carries
        // `BelongsToPublisher`, and the wall is stateless — there is no
        // authenticated publisher for that scope to resolve against.
        // `ConversionProcessor` resolves clicks the same way.
        $click = OfferClick::query()->withoutGlobalScopes()->firstOrCreate(
            [
                'app_id' => $context->app->id,
                'network_id' => $network->id,
                'end_user_id' => $context->endUserId,
                'offer_id' => null,
            ],
            [
                // `click_id` is left to the model — OfferClick::booted() mints
                // the UUID on create.
                'publisher_id' => $context->app->publisher_id,
                'ip' => $context->app->anonymize_ip ? IpAnonymizer::mask($context->ip) : $context->ip,
                'country' => $context->country,
                'user_agent' => $context->userAgent,
                'device_type' => $context->device,
                'os' => $context->os,
                'browser' => $context->browser,
                'referrer' => $context->referrer,
                'risk_score' => $decision->score,
                'blocked' => $decision->blocked,
                'blocked_reason' => $decision->reason,
                // The reasoning from ClickController applies unchanged: the
                // country override is honoured only in test mode, but test mode
                // is a publisher-editable toggle on a live app and the parameter
                // is readable off the iframe URL. Without this, an app left in
                // test mode lets anyone earn real money from a country the
                // targeting never allowed. There is no test offer on this path,
                // so the override is the whole rule.
                'is_test' => $context->testMode && $context->countryOverridden,
                'created_at' => now(),
            ],
        );

        return [$click, $decision->blocked];
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Wall/EmbedClickTest.php`
Expected: PASS (5 tests)

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Services/Wall/EmbedClick.php tests/Feature/Wall/EmbedClickTest.php
git commit -m "Give an embedded partner one click row per user to postback against"
```

---

### Task 4: Crediting a partner postback

**Files:**
- Modify: `app/Services/Money/ConversionProcessor.php:112` (the `offer_name` line inside `record()`)
- Test: `tests/Feature/Money/EmbeddedWallPostbackTest.php`

**Interfaces:**
- Consumes: the nullable `offer_clicks.offer_id` from Task 1
- Produces: nothing new — this proves the existing money path already carries an offer-less conversion end to end.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Money/EmbeddedWallPostbackTest.php`:

```php
<?php

use App\Enums\ConversionStatus;
use App\Enums\OutgoingPostbackKind;
use App\Models\App;
use App\Models\Conversion;
use App\Models\LedgerEntry;
use App\Models\Network;
use App\Models\OfferClick;
use App\Models\Publisher;
use App\Networks\Drivers\FakeNetworkDriver;
use App\Networks\DTO\PostbackDTO;
use App\Networks\NetworkDriverManager;
use App\Services\Money\MacroResolver;
use Database\Factories\OfferClickFactory;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/MoneyFixtures.php';

/** The embed equivalent of moneyFixture(): a click with no offer on it. */
function embedFixture(array $app = []): OfferClick
{
    app(NetworkDriverManager::class)->register('fake', FakeNetworkDriver::class);
    FakeNetworkDriver::reset();

    $network = Network::factory()->create(['driver' => 'fake', 'name' => 'Partner Wall Co']);
    $owner = Publisher::factory()->create();
    $placement = App::factory()->create(['publisher_id' => $owner->id, ...$app]);

    return OfferClickFactory::new()->create([
        'app_id' => $placement->id,
        'publisher_id' => $owner->id,
        'offer_id' => null,
        'network_id' => $network->id,
        'end_user_id' => 'USER_1',
    ]);
}

it('credits a partner postback that carries an embed click id', function () {
    Queue::fake();

    $click = embedFixture();

    firePostback(new PostbackDTO(
        networkTxId: 'PTX-1',
        clickId: $click->click_id,
        payout: 0.40,
        status: 'credited',
        offerName: 'Survey 4 minutes',
        endUserId: 'USER_1',
    ))->assertOk();

    $conversion = Conversion::query()->sole();

    expect($conversion->status)->toBe(ConversionStatus::Approved)
        ->and($conversion->offer_id)->toBeNull()
        ->and($conversion->offer_click_id)->toBe($click->id)
        ->and($conversion->end_user_id)->toBe('USER_1')
        ->and($conversion->offer_name)->toBe('Survey 4 minutes')
        ->and(LedgerEntry::query()->count())->toBe(1);
});

it('names the partner when the postback carries no offer name', function () {
    Queue::fake();

    $click = embedFixture();

    firePostback(new PostbackDTO(
        networkTxId: 'PTX-2',
        clickId: $click->click_id,
        payout: 0.40,
        status: 'credited',
    ))->assertOk();

    // `conversions.offer_name` is NOT NULL, and there is no offer to fall back
    // to. The partner's name is the only true thing left to say.
    expect(Conversion::query()->sole()->offer_name)->toBe('Partner Wall Co');
});

it('writes one conversion when the partner sends the same postback twice', function () {
    Queue::fake();

    $click = embedFixture();

    $dto = new PostbackDTO(
        networkTxId: 'PTX-3',
        clickId: $click->click_id,
        payout: 0.40,
        status: 'credited',
    );

    firePostback($dto)->assertOk();
    firePostback($dto)->assertOk();

    expect(Conversion::query()->count())->toBe(1)
        ->and(LedgerEntry::query()->count())->toBe(1);
});

it('resolves every publisher macro on a conversion with no offer', function () {
    Queue::fake();

    $click = embedFixture(['postback_url' => 'https://pub.test/cb']);

    firePostback(new PostbackDTO(
        networkTxId: 'PTX-4',
        clickId: $click->click_id,
        payout: 0.40,
        status: 'credited',
        offerName: 'Survey 4 minutes',
    ))->assertOk();

    $url = MacroResolver::resolve(
        'https://pub.test/cb?u={user_id}&o={offer_id}&n={offer_name}&t={trans_id}&p={payout_usd}',
        Conversion::query()->sole(),
        OutgoingPostbackKind::Credit,
    );

    // `{offer_id}` reaches through a null relation and resolves to empty. It
    // must not throw, and it must not leave the macro un-substituted — a
    // publisher parsing that URL would read `{offer_id}` as an offer.
    expect($url)->toContain('o=&')
        ->and($url)->toContain('n=Survey%204%20minutes')
        ->and($url)->not->toContain('{');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/Money/EmbeddedWallPostbackTest.php`
Expected: the second test FAILS — `offer_name` comes back as `''`, not `Partner Wall Co`. (The others should already pass; if the first one errors on a null `offer_id`, Task 1's migration has not run — run `php artisan migrate`.)

- [ ] **Step 3: Write the implementation**

In `app/Services/Money/ConversionProcessor.php`, inside `record()`, change:

```php
'offer_name' => $dto->offerName ?? (string) $offer?->name,
```

to:

```php
// An embedded partner wall has no offer of ours, and the column is NOT
// NULL. The partner's name is the only true thing left to say.
'offer_name' => $dto->offerName ?? $offer?->name ?? $network->name,
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Money/EmbeddedWallPostbackTest.php`
Expected: PASS (4 tests)

- [ ] **Step 5: Run the whole money suite for regressions**

Run: `php artisan test tests/Feature/Money`
Expected: PASS — the fallback only fires where both earlier values are null, which no existing test produces.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add app/Services/Money/ConversionProcessor.php tests/Feature/Money/EmbeddedWallPostbackTest.php
git commit -m "Name the partner on a conversion that never had an offer"
```

---

### Task 5: The partner page

**Files:**
- Create: `app/Http/Controllers/Wall/PartnerWallController.php`
- Create: `resources/views/wall/partner.blade.php`
- Modify: `routes/web.php` (inside the `wall` middleware group, after the `wall.offer` route)
- Modify: `lang/en/wall.php`, `lang/ar/wall.php`
- Modify: `resources/css/wall.css`
- Test: `tests/Feature/Wall/PartnerWallTest.php`

**Interfaces:**
- Consumes: `PartnerWall::isEmbeddable()` / `PartnerWall::url()` (Task 2), `EmbedClick::open()` (Task 3), `Network::query()->embeddable()` (Task 1), `WallGate` and `RendersWallPages` (exist)
- Produces: named routes `wall.partners` (no parameter) and `wall.partner` (parameter `network`), and the view `wall.partner` which receives `context`, `partner`, `partners`, `src`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Wall/PartnerWallTest.php`:

```php
<?php

use App\Models\Network;
use App\Models\OfferClick;

require_once __DIR__.'/WallFixtures.php';

function partnerNetwork(array $attributes = []): Network
{
    return Network::factory()->create([
        'wall_url' => 'https://p.test/w?s={sub_id}&u={user_id}',
        ...$attributes,
    ]);
}

function partnerGet(App\Models\App $app, ?Network $network = null, string $user = 'USER_1', array $extra = []): Illuminate\Testing\TestResponse
{
    $query = http_build_query(['appid' => $app->app_id, 'userid' => $user, ...$extra]);
    $path = $network === null ? '/wall/partners' : "/wall/partners/{$network->id}";

    return test()->withHeaders(['User-Agent' => WALL_UA])->get("{$path}?{$query}");
}

it('frames the highest-priority partner and hands it the click id', function () {
    wallGeo('US');
    [$app] = wallFixture();
    partnerNetwork(['priority' => 1, 'name' => 'Second']);
    $first = partnerNetwork(['priority' => 9, 'name' => 'First']);

    $response = partnerGet($app)->assertOk();

    $click = OfferClick::query()->whereNull('offer_id')->sole();

    expect($click->network_id)->toBe($first->id);

    $response->assertSee("https://p.test/w?s={$click->click_id}&amp;u=USER_1", false)
        ->assertSee('sandbox="allow-scripts allow-same-origin allow-popups allow-forms allow-top-navigation-by-user-activation"', false)
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('carries the wall parameters into every partner link', function () {
    wallGeo('US');
    [$app] = wallFixture(['require_signed_wall' => true]);
    $other = partnerNetwork(['priority' => 1]);
    partnerNetwork(['priority' => 9]);

    // The sub-strip links have to survive a signed app, or switching partner
    // is a 403 the publisher cannot debug.
    partnerGet($app, null, 'USER_1', ['sig' => wallSig($app)])
        ->assertOk()
        ->assertSee("/wall/partners/{$other->id}?appid={$app->app_id}&amp;userid=USER_1&amp;sig=".wallSig($app), false);

    partnerGet($app)->assertForbidden();
});

it('opens a partner in a new tab when it refuses to be framed', function () {
    wallGeo('US');
    [$app] = wallFixture();
    partnerNetwork(['wall_new_tab' => true]);

    partnerGet($app)->assertOk()
        ->assertSee(__('wall.partner.open'))
        ->assertDontSee('<iframe', false);
});

it('404s when there is no partner to show', function () {
    wallGeo('US');
    [$app] = wallFixture();

    partnerGet($app)->assertNotFound();

    partnerGet($app, partnerNetwork(['wall_url' => null]))->assertNotFound();
    partnerGet($app, Network::factory()->disabled()->create(['wall_url' => 'https://p.test/w']))->assertNotFound();
    partnerGet($app, partnerNetwork(['wall_url' => 'http://p.test/w']))->assertNotFound();
});

it('holds a partner behind the consent gate', function () {
    wallConsentCountries(['DE']);
    wallGeo('DE');
    [$app] = wallFixture();
    $partner = partnerNetwork();

    // Deep-linking a partner must not walk past the gate — an embedded wall
    // sets third-party cookies, which is the processing the gate exists for.
    partnerGet($app, $partner)->assertOk()->assertDontSee('<iframe', false);

    expect(OfferClick::query()->count())->toBe(0);

    // Through the endpoint, not a raw create: `consents.ip` and
    // `user_agent_hash` are NOT NULL, and the POST is the path that fills them.
    test()->withHeaders(['User-Agent' => WALL_UA])
        ->post('/wall/consent', ['appid' => $app->app_id, 'userid' => 'USER_1', 'action' => 'decline']);

    partnerGet($app, $partner)->assertOk()->assertSee(__('wall.notice.declined.title'));

    expect(OfferClick::query()->count())->toBe(0);
});

it('refuses a banned end user before it resolves a partner', function () {
    wallGeo('US');
    [$app] = wallFixture();
    $partner = partnerNetwork();

    // `banned_by` is NOT NULL — see tests/Feature/Fraud/PreClickRulesTest.php
    // for the canonical shape of this row.
    App\Models\BannedEndUser::query()->create([
        'publisher_id' => $app->publisher_id,
        'app_id' => $app->id,
        'end_user_id' => 'USER_1',
        'banned_by' => App\Enums\BannedBy::Publisher,
        'reason' => 'test',
        'expires_at' => null,
    ]);

    partnerGet($app, $partner)->assertOk()
        ->assertSee(__('wall.notice.banned.title'))
        ->assertDontSee('<iframe', false);

    expect(OfferClick::query()->count())->toBe(0);
});

it('answers with the notice, not a frame, when fraud blocks the open', function () {
    wallGeo('US');
    [$app] = wallFixture();
    $partner = partnerNetwork();

    // The ban gate above refuses at step 8, before a partner is resolved. This
    // is the other refusal: the pre-click pipeline blocking at step 10, which
    // still writes its row.
    app()->instance(App\Fraud\FraudPipeline::class, new class(app(App\Fraud\RuleRegistry::class)) extends App\Fraud\FraudPipeline
    {
        public function evaluate(App\Enums\FraudStage $stage, App\Fraud\FraudContext $context): App\Fraud\Decision
        {
            return new App\Fraud\Decision(true, 100, 'stubbed_block');
        }
    });

    partnerGet($app, $partner)->assertForbidden()
        ->assertSee(__('wall.notice.blocked.title'))
        ->assertDontSee('<iframe', false);

    expect(OfferClick::query()->whereNull('offer_id')->sole()->blocked)->toBeTrue();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/Wall/PartnerWallTest.php`
Expected: FAIL — every case 404s, no route matches `/wall/partners`.

- [ ] **Step 3: Add the translation keys**

In `lang/en/wall.php`, add at the top level:

```php
    'tabs' => [
        'offers' => 'Offers',
        'partners' => 'Offerwall',
    ],

    'partner' => [
        // Shown instead of the frame when the partner refuses to be embedded.
        'open' => 'Open :name',
    ],
```

In `lang/ar/wall.php`, the same keys:

```php
    'tabs' => [
        'offers' => 'العروض',
        'partners' => 'جدار العروض',
    ],

    'partner' => [
        'open' => 'افتح :name',
    ],
```

- [ ] **Step 4: Write the controller**

`app/Http/Controllers/Wall/PartnerWallController.php`:

```php
<?php

namespace App\Http\Controllers\Wall;

use App\Http\Controllers\Controller;
use App\Models\Network;
use App\Services\Wall\EmbedClick;
use App\Services\Wall\PartnerWall;
use App\Services\Wall\WallGate;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A partner's own hosted wall, framed inside ours — docs/03-offerwall.md §2.
 *
 * Steps 1–8 are `WallGate`, in the same order and for the same reasons as
 * `WallController`. Step 7 is the one worth naming: an embedded third-party
 * wall sets third-party cookies, so a European user who declined must not
 * reach one, including by deep link.
 *
 *   9.  Resolve the partner — active, https wall URL   → 404
 *   10. Pre-click fraud + the embed click row
 *   11. Blocked                                        → notice
 *   12. Substitute macros, render the frame
 */
class PartnerWallController extends Controller
{
    use RendersWallPages;

    public function __construct(
        private readonly WallGate $gate,
        private readonly EmbedClick $clicks,
    ) {}

    /** The tab itself: whichever partner sorts first. */
    public function index(Request $request): Response
    {
        return $this->render($request, null);
    }

    public function show(Request $request, Network $network): Response
    {
        return $this->render($request, $network);
    }

    private function render(Request $request, ?Network $network): Response
    {
        $app = $this->gate->app($request);

        if (! $this->gate->isServable($app)) {
            return $this->notice('unavailable', status: Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $context = $this->gate->context($request, $app);

        if ($this->gate->consentRequired($context->country)) {
            $consent = $request->input('consent') === 'review'
                ? null
                : $this->gate->consent($app, $context->endUserId);

            if ($consent === null) {
                return $this->page('consent', ['context' => $context]);
            }

            if (! $consent->granted) {
                return $this->notice('declined', $context);
            }
        }

        if ($this->gate->isBanned($app, $context->endUserId)) {
            return $this->notice('banned', $context);
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, Network> $partners */
        $partners = Network::query()->embeddable()->get()
            ->filter(PartnerWall::isEmbeddable(...))
            ->values();

        // The route-bound network still has to be one of them: `embeddable()`
        // is a query, and a hand-typed ID is not.
        $partner = $network === null
            ? $partners->first()
            : $partners->firstWhere('id', $network->id);

        abort_if($partner === null, Response::HTTP_NOT_FOUND);

        [$click, $blocked] = $this->clicks->open($context, $partner);

        if ($blocked) {
            return $this->notice('blocked', $context, Response::HTTP_FORBIDDEN);
        }

        return $this->page('partner', [
            'context' => $context,
            'partner' => $partner,
            'partners' => $partners,
            'src' => PartnerWall::url($partner, $context, (string) $click->click_id),
        ]);
    }
}
```

- [ ] **Step 5: Add the routes**

In `routes/web.php`, add the import beside the other wall controllers:

```php
use App\Http\Controllers\Wall\PartnerWallController;
```

and inside the `Route::middleware('wall')->group(...)` block, directly after the `wall.offer` route:

```php
    // A partner's own wall, framed. Server-rendered with its own URL rather
    // than a pane swap, for the same reason `wall.offer` is: the back button
    // behaves, it survives JS being off, and a publisher can deep-link one.
    Route::get('/wall/partners', [PartnerWallController::class, 'index'])
        ->middleware('throttle:wall')
        ->name('wall.partners');

    Route::get('/wall/partners/{network}', [PartnerWallController::class, 'show'])
        ->middleware('throttle:wall')
        ->name('wall.partner');
```

- [ ] **Step 6: Write the view**

`resources/views/wall/partner.blade.php`:

```blade
@extends('wall.layout')

@php
    /** @var \App\Services\Wall\WallContext $context */
    /** @var \App\Models\Network $partner */
    /** @var \Illuminate\Support\Collection<int, \App\Models\Network> $partners */
    /** @var string $src */
    $params = $context->params();
@endphp

@section('title', $partner->name)

@section('content')
<header class="wall-header">
    <div class="wall-shell">
        @include('wall.partials.tabs', ['active' => 'partners'])

        {{-- Only when there is a choice to make. One partner needs no picker,
             and a strip with a single permanently-selected chip is furniture. --}}
        @if ($partners->count() > 1)
            <div class="wall-chips" role="group" aria-label="{{ __('wall.tabs.partners') }}">
                @foreach ($partners as $option)
                    <a class="wall-chip @if ($option->is($partner)) is-on @endif"
                       @if ($option->is($partner)) aria-current="page" @endif
                       href="{{ route('wall.partner', $option) }}?{{ $params }}">{{ $option->name }}</a>
                @endforeach
            </div>
        @endif
    </div>
</header>

<main id="offers" class="wall-main">
    <div class="wall-shell">
        @if ($partner->wall_new_tab)
            {{-- The partner's frame-ancestors refuses us. A button is honest;
                 a frame that renders grey is a support ticket. --}}
            <div class="wall-empty">
                <a class="wall-button" href="{{ $src }}" target="_blank" rel="noopener">
                    {{ __('wall.partner.open', ['name' => $partner->name]) }}
                </a>
            </div>
        @else
            {{-- The same permissions our own embed docs ask of publishers, for
                 the same reason: a partner's offers end in store links, and a
                 frame that cannot top-navigate converts at zero. --}}
            <iframe class="wall-partner__frame"
                    src="{{ $src }}"
                    title="{{ $partner->name }}"
                    sandbox="allow-scripts allow-same-origin allow-popups allow-forms allow-top-navigation-by-user-activation"
                    allow="clipboard-write"
                    referrerpolicy="no-referrer"></iframe>
        @endif
    </div>
</main>
@endsection
```

- [ ] **Step 7: Write the tab partial the view includes**

`resources/views/wall/partials/tabs.blade.php` — Task 6 wires it into the offers page too, but the partner view cannot render without it:

```blade
@php
    /** @var \App\Services\Wall\WallContext $context */
    /** @var string $active */
    $params = $context->params();

    // ponytail: one indexed EXISTS per wall render. Steps 1–8 of the render
    // pipeline are cheap by contract (docs/03 §2) and this sits alongside them;
    // cache it per request if it ever shows up in a trace.
    $hasPartners = \App\Models\Network::query()->embeddable()->exists();
@endphp

@if ($hasPartners)
    <nav class="wall-tabs" aria-label="{{ __('wall.title') }}">
        <a class="wall-tab @if ($active === 'offers') is-on @endif"
           @if ($active === 'offers') aria-current="page" @endif
           href="{{ route('wall') }}?{{ $params }}">{{ __('wall.tabs.offers') }}</a>

        <a class="wall-tab @if ($active === 'partners') is-on @endif"
           @if ($active === 'partners') aria-current="page" @endif
           href="{{ route('wall.partners') }}?{{ $params }}">{{ __('wall.tabs.partners') }}</a>
    </nav>
@endif
```

- [ ] **Step 8: Add the styles**

In `resources/css/wall.css`, directly after the `.wall-chip.is-on` rule:

```css
/* ── Tabs ───────────────────────────────────────────────────────────────── */

/* The wall's top-level sections. Underlined rather than filled, so the strip
   reads as navigation and the filled chips below it stay unambiguously
   filters — two rows of pills would be one row of pills too many. */
.wall-tabs {
  display: flex;
  gap: 18px;
  border-block-end: 1px solid var(--wall-line);
  margin-block-end: 4px;
}

.wall-tab {
  padding-block: 10px;
  font-size: 14px;
  color: var(--wall-muted);
  text-decoration: none;
  border-block-end: 2px solid transparent;
  margin-block-end: -1px;
  white-space: nowrap;
  transition: color 0.16s ease, border-color 0.16s ease;
}

.wall-tab:hover {
  color: var(--wall-text);
}

.wall-tab.is-on {
  color: var(--wall-text);
  font-weight: 700;
  border-block-end-color: var(--wall-primary);
}

/* The partner's wall fills what is left of ours. It scrolls internally, the
   same contract we ask of our own publishers. */
.wall-partner__frame {
  inline-size: 100%;
  block-size: calc(100vh - 128px);
  min-block-size: 420px;
  border: 0;
  border-radius: var(--wall-radius);
  background: var(--wall-card);
}
```

- [ ] **Step 9: Stop the loader promising a grid the partner page will not render**

`resources/views/wall/layout.blade.php` paints a skeleton of the offer cards before the body arrives. On a partner page what arrives is one frame, so the card skeleton is a promise the page breaks. The layout receives the same data the view does — wrap the search, chips and card skeleton (lines 44–64, the three elements after `wall-loader__brand`) in:

```blade
        @unless (isset($partner))
            ... existing search / chips / card skeleton ...
        @endunless
```

The brand mark and the shell stay for both — that is the part that is true either way.

- [ ] **Step 10: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Wall/PartnerWallTest.php`
Expected: PASS (7 tests)

- [ ] **Step 11: Commit**

```bash
vendor/bin/pint --dirty
git add app/Http/Controllers/Wall/PartnerWallController.php resources/views/wall routes/web.php lang resources/css/wall.css tests/Feature/Wall/PartnerWallTest.php
git commit -m "Frame a partner's wall behind the same gate as our own"
```

---

### Task 6: The tab strip on the offers page

**Files:**
- Modify: `resources/views/wall/index.blade.php:27-43` (the `wall-header` block)
- Test: `tests/Feature/Wall/PartnerWallTest.php` (append)

**Interfaces:**
- Consumes: `resources/views/wall/partials/tabs.blade.php` (Task 5), `wall.partners` route (Task 5)
- Produces: nothing — this is the last wiring step.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Wall/PartnerWallTest.php`:

```php
it('shows the tab strip on the offers page once a partner exists', function () {
    wallGeo('US');
    [$app, $network] = wallFixture();
    wallOffer($network, ['countries' => [], 'devices' => []]);

    // Nothing to switch to yet — a tab strip with one tab is furniture.
    wallGet($app)->assertOk()->assertDontSee(__('wall.tabs.partners'));

    partnerNetwork();

    wallGet($app)->assertOk()
        ->assertSee(__('wall.tabs.partners'))
        ->assertSee('/wall/partners?appid='.$app->app_id.'&amp;userid=USER_1', false);
});

it('keeps offers as the default tab', function () {
    wallGeo('US');
    [$app, $network] = wallFixture();
    $offer = wallOffer($network, ['countries' => [], 'devices' => []]);
    partnerNetwork();

    // The strip is navigation around the wall, not a change to it.
    wallGet($app)->assertOk()->assertSee($offer->name)->assertDontSee('<iframe', false);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/Wall/PartnerWallTest.php --filter="tab strip"`
Expected: FAIL — the offers page renders no tabs.

- [ ] **Step 3: Include the partial in the offers header**

In `resources/views/wall/index.blade.php`, inside `<div class="wall-shell">` in the header, immediately before `<div class="wall-header__row">`:

```blade
            @include('wall.partials.tabs', ['active' => 'offers'])
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Wall/PartnerWallTest.php`
Expected: PASS (9 tests)

- [ ] **Step 5: Run the whole wall suite for regressions**

Run: `php artisan test tests/Feature/Wall`
Expected: PASS. The existing wall tests create no embeddable network, so the strip stays hidden and no assertion about the offers page changes.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add resources/views/wall/index.blade.php tests/Feature/Wall/PartnerWallTest.php
git commit -m "Put the tab strip above the offers, where a second wall now lives"
```

---

### Task 7: Admin fields

**Files:**
- Modify: `app/Filament/Admin/Resources/Networks/NetworkResource.php` (a new `Section` after `Postback`, and one table column)
- Modify: `app/Filament/Publisher/Resources/OfferClicks/OfferClickResource.php:53` (the `offer.name` column)
- Test: `tests/Feature/Panel/NetworkWallUrlTest.php`

**Interfaces:**
- Consumes: `PartnerWall::MACROS` (Task 2), the `wall_url` / `wall_new_tab` columns (Task 1)
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Panel/NetworkWallUrlTest.php`. The admin arrangement is copied from `tests/Feature/Panel/NetworkLogoTest.php` — `PLAYNDGAIN_REQUIRE_ADMIN_2FA` is on in `phpunit.xml`, so the config has to be switched off and the user needs its two-factor columns filled:

```php
<?php

use App\Filament\Admin\Resources\Networks\Pages\EditNetwork;
use App\Models\Network;
use App\Models\User;
use Illuminate\Support\Facades\Config;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Config::set('playndgain.require_admin_2fa', false);

    $this->actingAs(User::factory()->admin()->create([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_recovery_codes' => ['aaaa-bbbb'],
    ]));
});

it('saves a partner wall url and refuses one that is not https', function () {
    $network = Network::factory()->create();

    livewire(EditNetwork::class, ['record' => $network->getRouteKey()])
        ->fillForm(['wall_url' => 'https://p.test/w?s={sub_id}', 'wall_new_tab' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($network->fresh()->wall_url)->toBe('https://p.test/w?s={sub_id}')
        ->and($network->fresh()->wall_new_tab)->toBeTrue();

    livewire(EditNetwork::class, ['record' => $network->getRouteKey()])
        ->fillForm(['wall_url' => 'http://p.test/w'])
        ->call('save')
        ->assertHasFormErrors(['wall_url']);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/Panel/NetworkWallUrlTest.php`
Expected: FAIL — the form has no `wall_url` field.

- [ ] **Step 3: Add the form section**

In `app/Filament/Admin/Resources/Networks/NetworkResource.php`, add `use App\Services\Wall\PartnerWall;` to the imports and this section directly after the `Postback` section:

```php
            Section::make('Embedded wall')
                ->description('Set this only for a partner whose own wall we frame. Leaving it empty keeps the network an API integration.')
                ->columns(1)
                ->schema([
                    TextInput::make('wall_url')
                        ->label('Partner wall URL')
                        ->maxLength(2048)
                        ->helperText('Macros: '.collect(PartnerWall::MACROS)->map(fn (string $m): string => '{'.$m.'}')->implode(', ').'. {sub_id} is the one the partner must echo in their postback.')
                        ->placeholder('https://partner.test/wall?apikey=...&sub_id={sub_id}&user={user_id}')
                        // Not `->url()`: the template holds `{macros}`, which
                        // no URL validator accepts. The scheme is the part that
                        // matters — the wall is embedded in someone else's
                        // https page, and it is also where injection would land.
                        ->rule('starts_with:https://'),
                    Toggle::make('wall_new_tab')
                        ->label('Opens in a new tab')
                        ->helperText('For a partner whose frame-ancestors refuses us. The wall then shows a button instead of an empty frame.'),
                ]),
```

Add `use Filament\Forms\Components\Toggle;` to the imports.

- [ ] **Step 4: Add the table column**

In the same file, in `table()`, after the `offers_count` column:

```php
                IconColumn::make('wall_url')
                    ->label('Wall')
                    ->boolean()
                    ->tooltip('This partner’s own wall is embedded in ours')
                    ->getStateUsing(fn (Network $record): bool => filled($record->wall_url)),
```

Add `use Filament\Tables\Columns\IconColumn;` to the imports.

- [ ] **Step 5: Name the partner where the publisher's click list expects an offer**

In `app/Filament/Publisher/Resources/OfferClicks/OfferClickResource.php`, the offer column already renders `—` for a null offer, which is honest but says nothing. An embed row has a partner instead. Change line 53 from:

```php
TextColumn::make('offer.name')->label('Offer')->limit(28)->placeholder('—'),
```

to:

```php
// A click with no offer is an embedded partner wall open (docs/03 §2).
// The partner is the true answer to "what was this click on".
TextColumn::make('offer.name')->label('Offer')->limit(28)->placeholder('—')
    ->getStateUsing(fn (OfferClick $record): ?string => $record->offer?->name ?? $record->network?->name),
```

Add `use App\Models\OfferClick;` to the imports if it is not there, and add `'network:id,name'` to the eager-load list on line 48:

```php
->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['app:id,name,anonymize_ip', 'offer:id,name', 'network:id,name']))
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Panel/NetworkWallUrlTest.php`
Expected: PASS

- [ ] **Step 7: Run the panel suite for regressions**

Run: `php artisan test tests/Feature/Panel`
Expected: PASS — the click column change only alters what a null offer renders as, and no existing test creates a click without one.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty
git add app/Filament tests/Feature/Panel/NetworkWallUrlTest.php
git commit -m "Let an admin paste the partner wall we are going to frame"
```

---

### Task 8: Documentation and the full suite

**Files:**
- Modify: `docs/03-offerwall.md` (§2, the render pipeline)
- Modify: `docs/01-domain-model.md` (the `offer_clicks` and `networks` tables)

**Interfaces:**
- Consumes: everything above
- Produces: nothing

- [ ] **Step 1: Update the offerwall doc**

In `docs/03-offerwall.md` §2, after the existing 12-step render order, add a subsection. Match the document's voice — it explains why, not only what:

```markdown
### The Offerwall tab

The wall's header carries a tab strip: **Offers**, which is everything above,
and **Offerwall**, which frames a partner's own hosted wall. The strip is
hidden entirely when no partner qualifies — a tab that leads to an empty page
is worse than no tab.

A partner qualifies when `networks.status` is active and `networks.wall_url`
holds an `https://` template. `GET /wall/partners` frames the highest-priority
one; `GET /wall/partners/{network}` frames a named one. Both run steps 1–8
above unchanged, and step 7 is the reason they do rather than taking a
shortened gate: an embedded third-party wall sets third-party cookies, which is
exactly the processing the consent gate exists to prevent. A European user who
declined does not reach a partner frame, including by deep link.

Then:

  9.  Resolve the partner — active, https wall URL     → 404
  10. Pre-click fraud, and the embed click row
  11. Blocked                                          → notice
  12. Substitute macros, render the frame

**The embed click row** is an `offer_clicks` row with a null `offer_id`, one per
`(app, partner, end user)`, reused on every later open. Its `click_id` is the
sub-id we hand the partner, and it is what their postback resolves back to —
`ConversionProcessor` attributes a conversion entirely off a click, so without
it there is nothing to credit. The row is written even when the open is
blocked, for the same reason a blocked offer click is: a refusal with no row is
a support question nobody can answer.

Macros in `wall_url`: `{sub_id}`, `{user_id}`, `{app_id}`, `{country}`,
`{device}`, `{lang}`. Every substituted value is URL-encoded — `user_id` is
publisher input landing in a URL — and a macro we do not know is left literal
rather than blanked, because a silently emptied `{clickid}` is an integration
that fails at payout time instead of at setup time.
```

- [ ] **Step 2: Update the domain model doc**

In `docs/01-domain-model.md`, find the `offer_clicks` table description and note that `offer_id` is nullable, and what a null means:

```markdown
`offer_id` is nullable. A null means the click is an **embedded partner wall
open** rather than a click on one of our offers — there is no offer of ours
involved. `offer_id IS NULL` is what keeps those rows out of offer-level
reporting.
```

In the `networks` description, add:

```markdown
`wall_url` and `wall_new_tab` describe the second integration mode: instead of
syncing a catalogue and redirecting clicks, we frame the partner's own wall.
Both are null/false for an API network, which is most of them.
```

- [ ] **Step 3: Run the full suite**

Run: `php artisan test`
Expected: PASS. Investigate any failure before committing — a green suite is the gate on this plan, not a formality.

- [ ] **Step 4: Run static analysis**

Run: `vendor/bin/phpstan analyse`
Expected: no new errors. The likely one is `$offer?->name` narrowing in `ConversionProcessor` or the `Builder` generic on `scopeEmbeddable` — fix by annotating, not by loosening the types.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add docs/03-offerwall.md docs/01-domain-model.md
git commit -m "Write down the tab strip and the click row that has no offer"
```

---

## Notes for the implementer

- **`OfferClickFactory` derives `network_id` from `offer_id`.** Passing `'offer_id' => null` alone throws inside the factory closure — always pass `network_id` explicitly alongside it.
- **`Network::factory()` uses a slugged company name as the driver**, and `networks.driver` is unique. Tests that need a specific driver (`fake`) must say so.
- **`wallFixture()` reuses an existing network with the same driver.** For partner networks, use `Network::factory()` directly so each one is distinct.
- **`FraudContext::$offer` is already nullable** and `WallContext::fraudContext()` defaults it to null — no change is needed there.
- **`MacroResolver` is already null-safe** on `{offer_id}` (`$conversion->offer?->external_id`). Task 4 proves it rather than fixing it.
- **Accepting consent from a partner page returns to the Offers tab.** `ConsentController` redirects to `route('wall')`, full stop. Leave it: the tab is one tap away, and threading a return-to parameter through a stateless form to save that tap is more surface than the seam is worth. Say so if a reviewer asks; do not build it silently.
- **The fraud pipeline is already null-offer safe.** `GeoMismatchRule` and `RepeatOfferCompletionRule` are the only rules that reach into the offer, and both return `RuleResult::pass()` when it is null. `ConversionProcessor::evaluateFraudRules()` calls `Offer::query()->find(null)`, which returns null rather than throwing. Task 4's credited postback runs that path — if you want it proven rather than reasoned, add `$this->seed(Database\Seeders\FraudRuleSeeder::class);` to the first test in `EmbeddedWallPostbackTest.php` and confirm it still credits.
- **Do not add a driver for a new partner in this plan.** Onboarding a partner — its driver class, credentials, postback contract, IP allowlist — is separate work, done per partner. This plan builds the mechanism and leaves the seeded networks unchanged.
