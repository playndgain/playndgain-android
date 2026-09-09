# Admin Analytics Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship `/admin/analytics` so an admin can filter money, supply, chargebacks, and fraud by range / publisher / network / app / country, and rank leaking sources without auto-blocking.

**Architecture:** Query objects (`AnalyticsWindow`, `StatsQuery`, `RiskQuery`) own every aggregate. A second Filament dashboard page hosts `laboiteacode/filament-dashboard-widgets` classes that only format those results. Widgets live outside `discoverWidgets` so `/admin` stays the morning check.

**Tech Stack:** Laravel 13, Filament v5, Livewire 4, Pest 5, `laboiteacode/filament-dashboard-widgets`, MySQL `daily_stats` / `conversions` / `fraud_events`.

## Global Constraints

- KPI money never comes from `SUM(conversions.revenue)`. Chargeback dollars are `SUM(payout)` on `status = reversed` only, labeled **Chargebacks**.
- `daily_stats` shape and `stats:aggregate` do not change.
- Widget colours are only panel tokens: `primary`, `success`, `warning`, `danger`, `gray`, `gain`, `loss`. No extra hex. No `info`.
- No auto-block. Rank rows link to existing resources.
- Analytics widgets must not appear on `/admin`.
- Polling off. No new tables. No generated columns.
- Do not commit unless the user asks.

---

## File map

**Create**

- `app/Enums/SourceDimension.php`
- `app/Services/Reporting/AnalyticsWindow.php`
- `app/Services/Reporting/RiskQuery.php`
- `app/Filament/Admin/Pages/Analytics.php`
- `app/Filament/Admin/Analytics/ReadsAnalyticsFilters.php`
- `app/Filament/Admin/Analytics/SourcedBreakdownWidget.php`
- `app/Filament/Admin/Analytics/RevenueMetric.php`
- `app/Filament/Admin/Analytics/PayoutMetric.php`
- `app/Filament/Admin/Analytics/ProfitMetric.php`
- `app/Filament/Admin/Analytics/ClicksMetric.php`
- `app/Filament/Admin/Analytics/ConversionsMetric.php`
- `app/Filament/Admin/Analytics/ConversionRateMetric.php`
- `app/Filament/Admin/Analytics/ReversalRateMetric.php`
- `app/Filament/Admin/Analytics/ChargebackCountMetric.php`
- `app/Filament/Admin/Analytics/ChargebackPayoutMetric.php`
- `app/Filament/Admin/Analytics/RevenueVsPayoutChart.php`
- `app/Filament/Admin/Analytics/ClickConversionFunnel.php`
- `app/Filament/Admin/Analytics/ConversionsByStatus.php`
- `app/Filament/Admin/Analytics/RevenueByNetwork.php`
- `app/Filament/Admin/Analytics/RevenueByPublisher.php`
- `app/Filament/Admin/Analytics/RevenueByApp.php`
- `app/Filament/Admin/Analytics/RevenueByCountry.php`
- `app/Filament/Admin/Analytics/PublisherProfitVariance.php`
- `app/Filament/Admin/Analytics/ChargebackSources.php`
- `app/Filament/Admin/Analytics/FraudByAction.php`
- `app/Filament/Admin/Analytics/FraudByRule.php`
- `app/Filament/Admin/Analytics/FraudSources.php`
- `app/Filament/Admin/Analytics/LatestFraudEvents.php`
- `resources/views/filament/admin/analytics/sourced-breakdown.blade.php`
- `tests/Feature/Reporting/AnalyticsWindowTest.php`
- `tests/Feature/Reporting/RiskQueryTest.php`
- `tests/Feature/Panel/AdminAnalyticsTest.php`

**Modify**

- `app/Services/Reporting/StatsQuery.php` — add `forNetwork(?int $networkId): self`
- `tests/Feature/Money/AggregateDailyStatsTest.php` — one `forNetwork` assertion
- `tests/Feature/Panel/PanelSmokeTest.php` — `/admin/analytics` in the admin path list
- `docs/07-admin-panel.md` — nav + §3
- `docs/12-roadmap.md` — mark admin statistics done
- `docs/superpowers/specs/2026-09-09-admin-analytics-design.md` — status line only if needed

---

### Task 1: AnalyticsWindow

**Files:**
- Create: `app/Services/Reporting/AnalyticsWindow.php`
- Create: `app/Enums/SourceDimension.php`
- Test: `tests/Feature/Reporting/AnalyticsWindowTest.php`

**Interfaces:**
- Consumes: raw Filament `$pageFilters` array
- Produces:

```php
final readonly class AnalyticsWindow
{
    /**
     * @param  list<string>  $countries
     */
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public CarbonImmutable $previousFrom,
        public CarbonImmutable $previousTo,
        public ?int $publisherId,
        public ?int $networkId,
        public ?int $appId,
        public array $countries,
    ) {}

    /** @param  array<string, mixed>  $filters */
    public static function fromFilters(array $filters): self;

    public function stats(): StatsQuery;          // current + filters
    public function previousStats(): StatsQuery;  // previous + same filters
}

enum SourceDimension: string
{
    case Publisher = 'publisher';
    case App = 'app';
    case Offer = 'offer';
    case Country = 'country';
    case Ip = 'ip';
    case EndUser = 'end_user';
}
```

Range table (use `CarbonImmutable::today()`, so `Carbon::setTestNow` works):

| `range` | current | previous |
|---|---|---|
| `today` | today…today | yesterday…yesterday |
| `7d` (default, unknown → this) | today−6…today | today−13…today−7 |
| `30d` | today−29…today | today−59…today−30 |
| `month` | startOfMonth…today | last calendar month start…end |
| `last_month` | last calendar month | month before that |

`publisher_id` / `network_id` / `app_id`: keep only when `is_numeric`. `countries`: list of 2-letter codes, empty = all.

`stats()` / `previousStats()` call `StatsQuery::make()->between(...)->forApp()->forCountries()` and `forPublisher` / `forNetwork` only when those ids are set.

- [ ] **Step 1: Write the failing test**

```php
use App\Services\Reporting\AnalyticsWindow;
use App\Services\Reporting\StatsQuery;
use Carbon\CarbonImmutable;

it('defaults an unknown range to 7d', function () {
    CarbonImmutable::setTestNow('2026-09-09 12:00:00');

    $window = AnalyticsWindow::fromFilters(['range' => 'nope']);

    expect($window->from->toDateString())->toBe('2026-09-03')
        ->and($window->to->toDateString())->toBe('2026-09-09')
        ->and($window->previousFrom->toDateString())->toBe('2026-08-27')
        ->and($window->previousTo->toDateString())->toBe('2026-09-02');
});

it('drops non-numeric filter ids', function () {
    $window = AnalyticsWindow::fromFilters([
        'publisher_id' => 'abc',
        'network_id' => '',
        'app_id' => null,
        'countries' => ['us', 'EG'],
    ]);

    expect($window->publisherId)->toBeNull()
        ->and($window->networkId)->toBeNull()
        ->and($window->appId)->toBeNull()
        ->and($window->countries)->toBe(['US', 'EG']);
});
```

- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/Reporting/AnalyticsWindowTest.php` — expect FAIL (class missing)
- [ ] **Step 3: Implement** `SourceDimension` and `AnalyticsWindow` (call `forNetwork` only after Task 2 lands; until then omit that line or add the method in Task 2 first)
- [ ] **Step 4: Re-run the test** — expect PASS

---

### Task 2: StatsQuery::forNetwork

**Files:**
- Modify: `app/Services/Reporting/StatsQuery.php` (after `forApp`)
- Modify: `tests/Feature/Money/AggregateDailyStatsTest.php`

**Interfaces:**
- Produces: `public function forNetwork(?int $networkId): self` — no-op when null, else `where('network_id', $networkId)`

- [ ] **Step 1: Add this test to AggregateDailyStatsTest**

```php
it('scopes StatsQuery to one network', function () {
    [$publisher, $app, $network, $offer] = statsFixture();
    $other = Network::factory()->create();
    $otherOffer = Offer::factory()->create(['network_id' => $other->id]);

    conversionRow([], $publisher, $app, $network, $offer);
    conversionRow([], $publisher, $app, $other, $otherOffer);

    $this->artisan('stats:aggregate --today')->assertSuccessful();

    $totals = StatsQuery::make()
        ->forNetwork($network->id)
        ->between(now()->toImmutable()->startOfDay(), now()->toImmutable())
        ->totals();

    expect($totals['conversions'])->toBe(1);
});
```

- [ ] **Step 2: Run that test** — expect FAIL
- [ ] **Step 3: Add `forNetwork`**
- [ ] **Step 4: Re-run** — expect PASS
- [ ] **Step 5: Wire `AnalyticsWindow::stats()` / `previousStats()` to `forNetwork`**

---

### Task 3: RiskQuery

**Files:**
- Create: `app/Services/Reporting/RiskQuery.php`
- Test: `tests/Feature/Reporting/RiskQueryTest.php`

**Interfaces:**

```php
final class RiskQuery
{
    public static function make(AnalyticsWindow $window): self;

    /** @return array{pending: int, approved: int, reversed: int, rejected: int} */
    public function statusCounts(): array;

    /** @return array{count: int, payout: string} */
    public function chargebacks(): array;

    /**
     * Top 10. Chargebacks ordered by payout desc, then count desc.
     *
     * @return list<object{key: string, label: string, count: int, payout: string}>
     */
    public function chargebacksBy(SourceDimension $dimension): array;

    /** @return array{blocked: int, flagged: int, scored: int} */
    public function fraudActions(): array;

    /** @return list<object{key: string, count: int}> */
    public function fraudByRule(int $limit = 10): array;

    /**
     * Top 10 by count desc. Missing context keys label Unknown.
     *
     * @return list<object{key: string, label: string, count: int}>
     */
    public function fraudBy(SourceDimension $dimension): array;

    /** @return list<FraudEvent> */
    public function latestFraud(int $limit = 8): array;
}
```

Rules:

- Status counts: `conversions` where `is_test = false` and `created_at` in current (inclusive day bounds). Apply publisher/network/app/country column filters.
- Chargebacks: same table, `status = reversed`, `reversed_at` in current. `payout` is `COALESCE(SUM(payout), 0)` formatted with `Money::format`.
- Chargeback group columns: `publisher_id`, `app_id`, `offer_id` (label = `MAX(offer_name)`), `country`, `ip`, `CONCAT(publisher_id, ':', end_user_id)`.
- Null/empty country or ip → key `unknown`, label `Unknown`.
- End-user label later in the widget (`#pub / user`). RiskQuery label can be the raw `publisher_id:end_user_id` key.
- Fraud: `fraud_events.created_at` in current. Publisher/app filters on columns. Country filter is `context->country` only (`whereIn` of JSON extract). Network filter: skip fraud rows unless we can join — **do not join**. If `networkId` is set, fraud methods return empty (network is not on the event). Spec: page filters apply; network is not a fraud_events column. Empty is honest.
- Fraud offer/country/ip: `JSON_UNQUOTE(JSON_EXTRACT(context, '$.offer_id'|country|ip))`. Null → Unknown.
- Test traffic: conversions `is_test = false` only. Fraud events have no test flag; include all.

- [ ] **Step 1: Write RiskQueryTest** covering: two publishers’ reversals ranked by payout; source switch changes the group key; publisher filter hides the other; event without `context.ip` is Unknown; status counts sum to conversion count not revenue; `is_test` reversal is ignored.

Seed with `Conversion::factory()` + `FraudEvent::query()->create([...])` as in `FraudEventViewTest`.

- [ ] **Step 2: Run** — expect FAIL
- [ ] **Step 3: Implement RiskQuery**
- [ ] **Step 4: Re-run** — expect PASS

---

### Task 4: Analytics page + smoke

**Files:**
- Create: `app/Filament/Admin/Pages/Analytics.php`
- Modify: `tests/Feature/Panel/PanelSmokeTest.php` (add `'/admin/analytics'` to the admin dataset)
- Create: `tests/Feature/Panel/AdminAnalyticsTest.php` (isolation + publisher 403)

Page:

```php
namespace App\Filament\Admin\Pages;

class Analytics extends Dashboard
{
    use HasFiltersForm;

    protected static string $routePath = '/analytics';
    protected static ?string $title = 'Analytics';
    protected static ?string $navigationLabel = 'Analytics';
    protected static string|UnitEnum|null $navigationGroup = 'Overview';
    protected static ?int $navigationSort = 2;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    public function filtersForm(Schema $schema): Schema { /* range, publisher, network, app, countries */ }

    public function getWidgets(): array { /* only Analytics\* classes, never Filament::getWidgets() */ }

    public function getColumns(): int|array
    {
        return ['default' => 1, 'md' => 2, 'xl' => 4];
    }
}
```

Filters: `Select` native false, searchable, placeholder All. Range options match publisher Statistics. Countries: distinct `daily_stats.country` without global scopes, same flag labels as `Statistics::countryFlagLabel` if cheap — otherwise `"US — United States"` via `Statistics::countryLabel`. Persist via HasFiltersForm (Filament query string).

- [ ] **Step 1: Add smoke path + isolation test** (page can render with `getWidgets(): []` first)
- [ ] **Step 2: Run smoke for `/admin/analytics`** — expect FAIL (404)
- [ ] **Step 3: Add the page with empty widget list**
- [ ] **Step 4: Re-run** — expect PASS
- [ ] **Step 5: Publisher `get('/admin/analytics')->assertForbidden()`**

Isolation test (after widgets exist):

```php
Livewire::test(\Filament\Pages\Dashboard::class)
    ->assertDontSeeLivewire(\App\Filament\Admin\Analytics\ChargebackSources::class);
```

---

### Task 5: Widgets

**Files:** everything under `app/Filament/Admin/Analytics/` plus `resources/views/filament/admin/analytics/sourced-breakdown.blade.php`

**Interfaces:**
- Consumes: `ReadsAnalyticsFilters` → `AnalyticsWindow::fromFilters($this->pageFilters)` and `RiskQuery::make($window)`
- `$sort` 1–20 in spec order. Metrics `columnSpan = 1`. Charts/funnel `columnSpan = 2`. Breakdowns `columnSpan = 2`. Variance/sources `columnSpan` full (`'full'` or 4).

`ReadsAnalyticsFilters`:

```php
trait ReadsAnalyticsFilters
{
    use InteractsWithPageFilters;

    protected function window(): AnalyticsWindow
    {
        return AnalyticsWindow::fromFilters($this->pageFilters);
    }
}
```

Metrics: compare current vs previous with `((now - then) / then) * 100`. When previous is 0, `trend(null)` and `description('first data')`. Money values use `Money::display`. Chargeback count / payout colour `loss`. Reversal rate `lowerIsBetter()`, format `xx.xx%` or `—`. Sparklines from `$window->stats()->daily($from, $to)` mapped to the field (chargeback count has no daily_stats sparkline — omit).

`SourcedBreakdownWidget` extends `BreakdownWidget`, `#[Url] public string $source = 'publisher'`, custom view with `<select wire:model.live="source">` over `SourceDimension` cases. Invalid source → publisher.

Chargeback rows: `formatUsing` → `Money::display($payout)` plus count in description. `url()` as spec §5.2. View all → `ConversionResource::getUrl('index', ['tableFilters' => ['status' => ['value' => 'reversed']]])`.

Fraud unknown: label `Unknown`. View all → `FraudEventResource::getUrl('index')`.

Latest fraud: `RecentItem::make($rule_key, $reason)->badge($action)->badgeColor(...)->url(view)`.

Revenue breakdowns: `groupedBy` on `network_id` / `publisher_id` / `app_id` / `country`, value = revenue, `Money::display`.

Variance: current vs previous `groupedBy('publisher_id')` profit (`revenue - payout`), `VarianceItem::make(label, current)->previous(previous)`.

Comparison chart: `ChartSeries::make('Revenue')->values(...)->color('primary')->filled()` and payout `color('gray')`. Labels `M j`.

Funnel: `FunnelStage::make('Clicks', $clicks)`, `FunnelStage::make('Approved', $conversions)`.

Status breakdown colours from `ConversionStatus::getColor()`.

Network names: `Network::query()->pluck('name', 'id')`. Publishers: `#{$pub_id} {$user?->name}`. Apps: name.

- [ ] **Step 1: Implement trait + metric/chart/funnel/breakdown/variance/source/recent widgets**
- [ ] **Step 2: Register them in `Analytics::getWidgets()` in sort order**
- [ ] **Step 3: Feature tests in AdminAnalyticsTest** — seeded daily_stats → revenue metric equals StatsQuery; two reversed publishers → ChargebackSources order; `set('source', 'offer')` changes labels; publisher page filter hides the other; fraud without ip → Unknown; status counts sum to conversion count
- [ ] **Step 4: Run** `php artisan test --compact tests/Feature/Panel/AdminAnalyticsTest.php tests/Feature/Panel/PanelSmokeTest.php tests/Feature/Reporting/` — expect PASS

---

### Task 6: Docs

**Files:**
- Modify: `docs/07-admin-panel.md` §2 add Analytics under Overview; §3 keep dashboard as today/ops and point money investigation at Analytics
- Modify: `docs/12-roadmap.md` Phase 4 first checkbox to done

- [ ] **Step 1: Update those two docs**
- [ ] **Step 2: Run** `vendor/bin/pint --dirty` and `php artisan test --compact` on the new/changed tests

---

## Spec coverage

| Spec | Task |
|---|---|
| §3 surface / nav / widgets not discovered | 4, 5 |
| §4 filters + windows | 1, 4 |
| §5.1 money widgets + `forNetwork` | 2, 5 |
| §5.2 chargebacks + source switch | 3, 5 |
| §5.3 fraud | 3, 5 |
| §6 query layer | 1–3 |
| §7 empty / 403 | 1, 4, 5 |
| §8 tests | 1–5 |
| §10 docs | 6 |
