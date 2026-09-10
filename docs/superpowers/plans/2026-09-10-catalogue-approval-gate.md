# Catalogue Approval Gate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Hide the live offer catalogue in the publisher panel until the account is Approved and the selected app is Active, matching `WallGate::isServable()`.

**Architecture:** `EligibleOffers::catalogueOpen(?int $appId): bool` is the single predicate. `EligibleOffers::query()` returns an empty builder when it is false, so Offers and the blacklist offer picker close together. The Offers empty-state copy reads the same helper. Create/edit wall preview keeps chrome and drops sample cards until the saved app is Active.

**Tech Stack:** Laravel 13, Filament v5, Livewire 4, Pest 5, MySQL.

## Global Constraints

- Account approved = `Publisher::isApproved()` (`status === approved`). App approved = `App::isActive()` (`status === active`).
- `EligibleOffers` is the only catalogue gate. Do not add a second `canAccess` / nav hide.
- `WallGate` and `/wall` do not change. Admin `/admin` does not change.
- Country and device blacklists still save. Only the offer picker / impact count go empty.
- Registration has no offer preview today. Do not add one.
- Copy is exact: heading **Waiting for approval**. Body with an app selected: **Offers appear after your account and this app are approved.** Body with `appId` null: **Offers appear after your account and an app are approved.** Gate-open empty inventory keeps **Nothing eligible**.
- Empty query uses `whereRaw('1 = 0')`, the same fail-closed pattern as `PublisherScope`.
- Run `vendor/bin/pint --dirty` before considering a task done. Tests: `php artisan test <path>`.
- Do not commit unless the user asks.

---

## File map

**Modify**

- `app/Filament/Publisher/Support/EligibleOffers.php` — `catalogueOpen()` + early return in `query()`
- `app/Filament/Publisher/Pages/Offers.php` — drop pending-app `mount()` fallback; empty-state copy from `catalogueOpen()`
- `app/Filament/Publisher/Resources/Apps/AppResource.php` — pass `showSamples` from the saved record
- `resources/views/filament/publisher/wall-preview.blade.php` — sample cards and note only when `showSamples`
- `tests/Feature/Panel/PublisherOffersTest.php` — gate + Offers page tests
- `tests/Feature/Panel/PublisherPanelTest.php` — create preview no longer shows Coin Master; edit pending vs Active
- `docs/08-publisher-panel.md` — §1, §5, §12, §16

**Do not touch**

- `app/Services/Wall/WallGate.php`
- `app/Filament/Publisher/Resources/Blacklists/BlacklistResource.php` (inherits the query)
- Admin offer resources

---

### Task 1: EligibleOffers gate

**Files:**
- Modify: `app/Filament/Publisher/Support/EligibleOffers.php`
- Test: `tests/Feature/Panel/PublisherOffersTest.php`

**Interfaces:**
- Consumes: `CurrentPublisher::get()`, `Publisher::isApproved()`, `App::isActive()`, existing `EligibleOffers::query()` filters
- Produces:
  - `EligibleOffers::catalogueOpen(?int $appId): bool`
  - `EligibleOffers::query(?int $appId): Builder<Offer>` — empty (`whereRaw('1 = 0')`) when `catalogueOpen` is false

- [ ] **Step 1: Write the failing tests**

Add these imports at the top of `tests/Feature/Panel/PublisherOffersTest.php` if missing:

```php
use App\Enums\PublisherStatus;
```

Append to the same file (after the existing tests, do not edit `inventoryFixture`):

```php
it('hides the catalogue from a pending publisher even with an active app', function () {
    [$publisher, $app, $offer] = inventoryFixture();
    $publisher->update([
        'status' => PublisherStatus::Pending,
        'approved_at' => null,
    ]);

    expect(EligibleOffers::catalogueOpen($app->id))->toBeFalse()
        ->and(EligibleOffers::query($app->id)->count())->toBe(0)
        ->and(EligibleOffers::impact($app->id, BlacklistType::Offer->value, (string) $offer->id))
        ->toContain('No eligible offers to measure against yet');
});

it('hides the catalogue for a non-active app', function (AppStatus $status) {
    [$publisher, $app] = inventoryFixture();
    $app->update(['status' => $status]);

    expect(EligibleOffers::catalogueOpen($app->id))->toBeFalse()
        ->and(EligibleOffers::query($app->id)->count())->toBe(0);
})->with([
    AppStatus::Pending,
    AppStatus::Paused,
    AppStatus::Rejected,
]);

it('hides the all-apps catalogue when the publisher has no active app', function () {
    [$publisher, $app] = inventoryFixture();
    $app->update(['status' => AppStatus::Pending]);

    expect(EligibleOffers::catalogueOpen(null))->toBeFalse()
        ->and(EligibleOffers::query(null)->count())->toBe(0);
});

it('keeps the catalogue open for an approved publisher and an active app', function () {
    [, $app, $offer] = inventoryFixture();

    expect(EligibleOffers::catalogueOpen($app->id))->toBeTrue()
        ->and(EligibleOffers::catalogueOpen(null))->toBeTrue()
        ->and(EligibleOffers::query($app->id)->pluck('id')->all())->toBe([$offer->id]);
});
```

- [ ] **Step 2: Run the new tests and confirm they fail**

Run: `php artisan test tests/Feature/Panel/PublisherOffersTest.php --filter='hides the catalogue|keeps the catalogue open'`

Expected: FAIL because `EligibleOffers::catalogueOpen` does not exist.

- [ ] **Step 3: Implement `catalogueOpen` and the `query()` early return**

In `app/Filament/Publisher/Support/EligibleOffers.php`:

1. Add imports:

```php
use App\Enums\AppStatus;
```

`CurrentPublisher` is already imported.

2. Add this method above `query()`:

```php
/**
 * Same predicates as WallGate::isServable(): approved account, active app.
 * Offers empty-state copy must call this rather than invent a second rule.
 */
public static function catalogueOpen(?int $appId): bool
{
    $publisher = CurrentPublisher::get();

    if ($publisher === null || ! $publisher->isApproved()) {
        return false;
    }

    if ($appId !== null) {
        $app = App::query()
            ->where('publisher_id', $publisher->id)
            ->whereKey($appId)
            ->first();

        return $app !== null && $app->isActive();
    }

    return App::query()
        ->where('publisher_id', $publisher->id)
        ->where('status', AppStatus::Active)
        ->exists();
}
```

3. At the very top of `query()`, before the existing `$publisherId = CurrentPublisher::id()` block:

```php
if (! self::catalogueOpen($appId)) {
    return Offer::query()->whereRaw('1 = 0');
}
```

Leave every existing filter after that untouched.

- [ ] **Step 4: Re-run the file**

Run: `php artisan test tests/Feature/Panel/PublisherOffersTest.php`

Expected: PASS (new gate tests and the existing inventory tests).

- [ ] **Step 5: Pint**

Run: `vendor/bin/pint --dirty`

---

### Task 2: Offers page mount and empty state

**Files:**
- Modify: `app/Filament/Publisher/Pages/Offers.php:75-86` (`mount`) and `:246-247` (empty state)
- Modify: `docs/08-publisher-panel.md` §1 table, §5, §12, §16
- Test: `tests/Feature/Panel/PublisherOffersTest.php`

**Interfaces:**
- Consumes: `EligibleOffers::catalogueOpen(?int $appId): bool` from Task 1
- Produces: `Offers::mount()` sets `appId` to the first Active app or leaves it null. Empty-state heading/description follow the spec copy.

- [ ] **Step 1: Write the failing page tests**

Append to `tests/Feature/Panel/PublisherOffersTest.php`:

```php
it('does not default the offers page to a pending app', function () {
    $publisher = Publisher::factory()->create();
    App::factory()->create([
        'publisher_id' => $publisher->id,
        'status' => AppStatus::Pending,
        'name' => 'Still pending',
    ]);
    $network = Network::factory()->create(['status' => NetworkStatus::Active]);
    Offer::factory()->create([
        'network_id' => $network->id,
        'status' => OfferStatus::Active,
        'name' => 'Secret catalogue',
    ]);

    test()->actingAs($publisher->user);
    Filament::setCurrentPanel('publisher');

    Livewire::test(Offers::class)
        ->assertSet('appId', null)
        ->assertSee('Waiting for approval')
        ->assertSee('Offers appear after your account and an app are approved.')
        ->assertDontSee('Secret catalogue');
});

it('defaults the offers page to the first active app', function () {
    [$publisher, $app, $offer] = inventoryFixture(['name' => 'Live offer']);
    App::factory()->create([
        'publisher_id' => $publisher->id,
        'status' => AppStatus::Pending,
    ]);

    Filament::setCurrentPanel('publisher');

    Livewire::test(Offers::class)
        ->assertSet('appId', $app->id)
        ->assertSee('Live offer')
        ->assertDontSee('Waiting for approval');
});

it('shows the waiting empty state for a pending publisher on an active app', function () {
    [$publisher, $app, $offer] = inventoryFixture(['name' => 'Live offer']);
    $publisher->update([
        'status' => PublisherStatus::Pending,
        'approved_at' => null,
    ]);

    Filament::setCurrentPanel('publisher');

    Livewire::test(Offers::class, ['appId' => $app->id])
        ->assertSee('Waiting for approval')
        ->assertSee('Offers appear after your account and this app are approved.')
        ->assertDontSee('Live offer');
});
```

- [ ] **Step 2: Run the new tests and confirm they fail**

Run: `php artisan test tests/Feature/Panel/PublisherOffersTest.php --filter='does not default the offers page|defaults the offers page|shows the waiting empty state'`

Expected: FAIL. `does not default` currently sets `appId` to the pending app (the `??` fallback) and may show the catalogue. `shows the waiting empty state` still lists `Live offer`.

- [ ] **Step 3: Change `mount()` and the empty state**

Replace `Offers::mount()` with:

```php
public function mount(): void
{
    $this->appId ??= App::query()
        ->where('publisher_id', CurrentPublisher::id())
        ->where('status', AppStatus::Active)
        ->orderBy('id')
        ->value('id');
}
```

Delete the `?? App::query()->where('publisher_id', ...)->orderBy('id')->value('id')` fallback.

Replace the two empty-state lines at the end of `table()` with:

```php
->emptyStateHeading(fn (): string => EligibleOffers::catalogueOpen($this->appId)
    ? 'Nothing eligible'
    : 'Waiting for approval')
->emptyStateDescription(fn (): string => EligibleOffers::catalogueOpen($this->appId)
    ? 'No active offers match this app right now. Check your blacklists and blocked countries.'
    : ($this->appId === null
        ? 'Offers appear after your account and an app are approved.'
        : 'Offers appear after your account and this app are approved.'));
```

`EligibleOffers` is already imported.

- [ ] **Step 4: Update `docs/08-publisher-panel.md`**

In §1 account states, change the `pending` row so the last sentence is: App creation allowed, wall serves nothing, Offers catalogue and blacklist offer picker are empty.

In §5, after “Defaults to their first active app, never to "all".” add:

A pending, paused, or rejected app is never the default. Until the account is Approved and the selected app is Active the table is empty: heading **Waiting for approval**, body **Offers appear after your account and this app are approved.** (or **…and an app are approved.** when no app is selected). The page stays in the nav.

In §12, after the Offers bullet, add: The picker is empty until the account is Approved and the chosen app (or at least one app, for “all apps”) is Active. Country and device blocks still save.

In §16, add:

- `EligibleOffers` is empty for a pending publisher, a non-active app, and `query(null)` when the publisher has no Active app.
- The Offers page does not default `appId` to a pending app.

- [ ] **Step 5: Re-run Offers tests**

Run: `php artisan test tests/Feature/Panel/PublisherOffersTest.php`

Expected: PASS.

- [ ] **Step 6: Pint**

Run: `vendor/bin/pint --dirty`

---

### Task 3: Create/edit preview hides sample cards until Active

**Files:**
- Modify: `app/Filament/Publisher/Resources/Apps/AppResource.php` `previewPane()` / `preview()`
- Modify: `resources/views/filament/publisher/wall-preview.blade.php`
- Test: `tests/Feature/Panel/PublisherPanelTest.php`

**Interfaces:**
- Consumes: saved `?App $record` on the form (null on create)
- Produces: `wall-preview` view receives `showSamples: bool` (`$record?->isActive() ?? false`). Sample cards and the “Sample inventory…” note render only when true. Chrome always renders.

- [ ] **Step 1: Point the existing create-preview test at chrome, not sample cards**

In `tests/Feature/Panel/PublisherPanelTest.php`, the test `it('paints the create-app preview with the wall\'s own chrome'` currently asserts `Coin Master` and `+2,940`. Change those two assertions to `assertDontSee`. Keep the chrome / lockup / Earn Coins assertions.

Replace:

```php
        ->assertSee('Rewards Hub')
        ->assertSee('Coin Master')
        ->assertSee('+2,940')
```

with:

```php
        ->assertSee('Rewards Hub')
        ->assertDontSee('Coin Master')
        ->assertDontSee('+2,940')
        ->assertDontSee('Sample inventory')
```

Add after that test:

```php
it('hides sample preview cards on a pending app and shows them once active', function () {
    $publisher = Publisher::factory()->create(['rev_share' => 70]);
    $app = App::factory()->create([
        'publisher_id' => $publisher->id,
        'status' => AppStatus::Pending,
        'name' => 'Rewards Hub',
        'currency_name' => 'Coins',
        'currency_rate' => 1000,
    ]);

    $this->actingAs($publisher->user);
    Filament::setCurrentPanel('publisher');

    Livewire::test(EditApp::class, ['record' => $app->getRouteKey()])
        ->assertSee('Rewards Hub')
        ->assertSee('wall-filters', false)
        ->assertDontSee('Coin Master')
        ->assertDontSee('Sample inventory');

    $app->update(['status' => AppStatus::Active]);

    Livewire::test(EditApp::class, ['record' => $app->getRouteKey()])
        ->assertSee('Coin Master')
        ->assertSee('Sample inventory');
});
```

`AppStatus`, `EditApp`, and `Filament` are already imported in this file.

- [ ] **Step 2: Run the preview tests and confirm they fail**

Run: `php artisan test tests/Feature/Panel/PublisherPanelTest.php --filter='paints the create-app preview|hides sample preview cards'`

Expected: FAIL. Create still sees Coin Master. Pending edit still sees Coin Master.

- [ ] **Step 3: Pass `showSamples` and gate the view**

In `app/Filament/Publisher/Resources/Apps/AppResource.php`, change the `Html::make` inside `previewPane()` from `fn (Get $get): HtmlString => self::preview($get)` to:

```php
Html::make(fn (Get $get, ?App $record): HtmlString => self::preview($get, $record)),
```

Change `preview()` signature and the view payload:

```php
private static function preview(Get $get, ?App $record): HtmlString
{
    $publisher = CurrentPublisher::get();
    $radius = $get('theme.radius');

    return new HtmlString(view('filament.publisher.wall-preview', [
        'vars' => WallContext::vars(WallContext::sanitiseTheme([
            'primary' => $get('theme.primary'),
            'background' => $get('theme.background'),
            'text' => $get('theme.text'),
            'radius' => filled($radius) ? ((int) $radius).'px' : null,
            'dark' => $get('theme.dark'),
        ])),
        'appName' => (string) ($get('name') ?: 'Your app'),
        'currencyName' => (string) ($get('currency_name') ?: 'Coins'),
        'rate' => (float) ($get('currency_rate') ?: 1000),
        'revShare' => $publisher === null ? 70.0 : (float) $publisher->rev_share,
        'dark' => (bool) ($get('theme.dark') ?? true),
        'showSamples' => $record?->isActive() ?? false,
    ])->render());
}
```

In `resources/views/filament/publisher/wall-preview.blade.php`:

1. Add `@param bool $showSamples` to the file docblock.
2. At the top of the `@php` block, after `$dark ??= true;`:

```php
    $showSamples ??= false;
```

3. Wrap the `$samples = [ ... ];` assignment so a closed gate uses an empty list. After `$currency = $currencyName;` keep the `$samples` array definition, then immediately:

```php
    if (! $showSamples) {
        $samples = [];
    }
```

The existing `array_map` that adds `reward_label` still runs on the (possibly empty) list.

4. Wrap the note:

```blade
    @if ($showSamples)
        <p class="wall-preview__note">
            Sample inventory at {{ number_format($rate) }} {{ $currencyName }} per $1.
            Real offers are whatever is eligible for your traffic.
        </p>
    @endif
```

Leave the featured rail heading, catalogue title, search, and footer in place. An empty `@foreach` is the empty catalogue.

- [ ] **Step 4: Re-run the preview tests and the Offers file**

Run:

```
php artisan test tests/Feature/Panel/PublisherPanelTest.php --filter='paints the create-app preview|hides sample preview cards'
php artisan test tests/Feature/Panel/PublisherOffersTest.php
```

Expected: PASS.

- [ ] **Step 5: Pint**

Run: `vendor/bin/pint --dirty`

---

## Spec coverage

| Spec section | Task |
|---|---|
| §1 Goal / §2 Rule / `catalogueOpen` + empty `query()` | 1 |
| §2 “all apps” requires an Active app | 1 |
| §3 Offers nav stays, mount, empty-state copy | 2 |
| §4 Blacklist picker / impact inherit `query()` | 1 (impact assertion) |
| §5 Preview chrome without cards until Active | 3 |
| §6 Tests listed in the spec | 1–3 |
| §7 Out of scope (`WallGate`, nav hide, admin) | Global Constraints |

## Self-review

- No TBD / “implement later”. Method names match across tasks: `catalogueOpen(?int $appId): bool`, `showSamples`.
- Existing create-preview test is updated in Task 3 so it does not keep asserting Coin Master after the gate lands.
- `PublisherScope` already uses `whereRaw('1 = 0')`; the empty catalogue uses the same SQL.
