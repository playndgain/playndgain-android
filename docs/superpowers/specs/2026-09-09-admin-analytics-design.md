# Admin Analytics — Design

**Date:** 2026-09-09
**Status:** Implemented 2026-09-09
**Supersedes nothing.** Extends `docs/07-admin-panel.md` §3 and `docs/06-ledger-payouts.md` §7. Those rules still hold.

---

## 1. Goal

Give an admin one filtered page to answer: what earned, what converted, what charged back, and **which source** is leaking so they can decide whether to block it.

The page ranks sources. It does not auto-block. Existing actions (pause app, block offer, ban user, suspend publisher) stay on their current resources, reached by a link from the rank row.

## 2. What this is not

| Left alone | Why |
|---|---|
| `/admin` dashboard | Morning check: today, needs attention, latest activity. Unchanged. |
| Profit history | P&L export by day/month, network, publisher. Unchanged. |
| Review queue, fraud events, protection | Case lists and rule tuning. Unchanged. |
| `daily_stats` shape / `stats:aggregate` | No new dimensions. Offer, IP, and end user stay off that table. |
| Publisher Statistics | Still scoped, still no network names. |
| CSV on this page | Profit history already exports P&L. |
| Goal or usage widgets | The product has no invented targets. |
| `leek/filament-header-filters` on this page | That package is table-column filters (Offers). Page filters use Filament `HasFiltersForm`. |

## 3. Surface

| | |
|---|---|
| URL | `/admin/analytics` |
| Class | `App\Filament\Admin\Pages\Analytics` |
| Extends | `Filament\Pages\Dashboard` with `HasFiltersForm` |
| Route path | `/analytics` (home dashboard stays `/`) |
| Nav | Overview, sort 2, label **Analytics** |
| Access | Admin panel only. A publisher hitting the URL is 403. |

`getWidgets()` returns **only** the widgets listed in §5. It must not call `Filament::getWidgets()`.

Widgets live in `app/Filament/Admin/Analytics/` under `App\Filament\Admin\Analytics`. That directory is **not** under `discoverWidgets` (`app/Filament/Admin/Widgets`), so they never appear on `/admin`.

## 4. Filters

One form, shared by every widget via `InteractsWithPageFilters`. Persist in the query string.

| Field | Values | Default |
|---|---|---|
| Range | `today` · `7d` · `30d` · `month` · `last_month` | `7d` |
| Publisher | Searchable select, empty = all | all |
| Network | Searchable select, empty = all | all |
| App | Searchable select, empty = all | all |
| Country | Multi-select of ISO-2 codes that appear in `daily_stats`, empty = all | all |

Unknown range string → `7d`. Unknown publisher / network / app id → ignore that filter (do not 500).

### Windows

Let *current* be the selected range (same bounds as publisher Statistics).

*Previous* is the equal-length window immediately before:

| Range | Current | Previous |
|---|---|---|
| `today` | today | yesterday |
| `7d` | today − 6 … today | today − 13 … today − 7 |
| `30d` | today − 29 … today | today − 59 … today − 30 |
| `month` | start of this month … today | last calendar month |
| `last_month` | last calendar month | the month before that |

## 5. Widgets

All widgets extend `laboiteacode/filament-dashboard-widgets` (already registered on the admin panel). Polling stays off.

Colours are only panel tokens: `primary`, `success`, `warning`, `danger`, `gray`, `gain`, `loss`. No extra hex palette. No purple.

### 5.1 Money and supply

| Widget | Reader | Shows |
|---|---|---|
| `MetricWidget` × 6 | `StatsQuery` | Revenue, publisher payout, profit, clicks, conversions, conversion rate |
| `MetricWidget` | `StatsQuery` | Reversal rate: `reversals / (conversions + reversals)` for conversions that **landed** in current. `lowerIsBetter()`. Both zero → `—`. |
| `MetricWidget` | `RiskQuery` | Chargeback **count** in current (`reversed_at`). Label **Chargebacks**. |
| `ComparisonChartWidget` | `StatsQuery` | Revenue vs payout, one point per day in current |
| `FunnelWidget` | `StatsQuery` | Clicks → approved conversions |
| `BreakdownWidget` | `RiskQuery` | Conversion **counts** by status (approved / pending / reversed / rejected). `created_at` in current. Not money. |
| `BreakdownWidget` × 4 | `StatsQuery` | Revenue by network, publisher, app, country (top 10) |
| `VarianceWidget` | `StatsQuery` | Top publishers by profit change vs previous |

Metric trends compare current totals to previous. Sparkline is the current daily series.

Conversion rate is `conversions / clicks`. Zero clicks → `—`.

Chargeback count uses `reversed_at` in current, not `daily_stats.reversals`. Those two numbers can differ: a conversion created last month and reversed today is a chargeback today and a `daily_stats` reversal on the create day. Both are correct. The `reversed_at` figures are labeled **Chargebacks** so they are not read as platform profit.

`StatsQuery` gains `forNetwork(?int $networkId): self` (no-op when null), matching `forApp()`.

### 5.2 Chargebacks (`conversions`)

One `MetricWidget`: `SUM(payout)` on reversed rows in current (original publisher payout, still stored positive after reverse). Label **Chargebacks**. Colour `loss`. The count already sits in the metric strip (§5.1).

One `BreakdownWidget` with a **source switcher** on the widget (not a page filter):

`publisher` · `app` · `offer` · `country` · `ip` · `end_user`

Default: `publisher`. Persist the switch in the query string. Top 10. Order: reversed payout descending, then count descending.

Each row links out. No block / ban / pause action on this page.

| Source | Group | Link |
|---|---|---|
| Publisher | `publisher_id` | Publisher view |
| App | `app_id` | App view |
| Offer | `offer_id` (label = snapshotted `offer_name`) | Offer resource |
| Country | `country` | Conversions index, country filter |
| IP | `ip` | Offerwall clicks index |
| End user | `(publisher_id, end_user_id)` | Offerwall clicks index filtered by that pair if those filters exist; otherwise the publisher view. Label `#pub_id / end_user_id`. IDs are not global. |

Missing country or IP → label **Unknown**.

“View all” → conversions index with `status = reversed`.

### 5.3 Fraud (`fraud_events`)

| Widget | Shows |
|---|---|
| `SegmentBarWidget` | Blocked / flagged / scored (`action_taken`) |
| `BreakdownWidget` | By `rule_key`, top 10 |
| `BreakdownWidget` | Same six-source switcher as chargebacks |
| `RecentItemsWidget` | Latest 8 events, link to the fraud event view |

Fraud source columns: `publisher_id`, `app_id`, `end_user_id` are real columns. Offer, country, and IP are `context.offer_id`, `context.country`, `context.ip` when the rule stored them. Missing key → **Unknown**, never coerced to zero.

“View all” → fraud events index.

## 6. Query layer

Widgets do not write their own aggregates.

| Reader | Responsibility |
|---|---|
| `App\Services\Reporting\AnalyticsWindow` | Parse page filters → current bounds, previous bounds, publisher / network / app / countries. Invalid ids omitted. |
| `StatsQuery` | Money, supply, funnel, variance. Add `forNetwork()`. |
| `App\Services\Reporting\RiskQuery` | Status counts, chargebacks (`status = reversed`, `is_test = false`, `reversed_at` in window), fraud events (`created_at` in window), source rankings. |

Page filters apply to all three readers. On fraud, the country filter matches `context.country` only. Events with no country in context drop out of a country-filtered view rather than appearing as platform-wide.

No new tables. No generated columns on `fraud_events`. No cross-request cache. No polling. Do not add `(status, reversed_at)` unless a measured range scan needs it.

KPI money never comes from `SUM(conversions.revenue)`. Status and chargeback widgets may count rows and sum **reversed** `payout` only, and only under the Chargebacks label.

## 7. Empty and error states

| Case | UI |
|---|---|
| No rows in current | Package empty state on charts / breakdowns / recent items. Metrics show `$0.00` / `0` / `—`. |
| Previous window is zero | Description `first data`, same as `PlatformStats`. Never `+∞`. |
| Source ranking empty | Empty state, not a fake “all clear” badge. |
| Publisher opens the URL | 403 from the panel. |

## 8. Tests

| Test | Asserts |
|---|---|
| Smoke | `/admin/analytics` in `PanelSmokeTest`: admin 200, publisher 403. |
| Isolation | Visiting `/admin` does not render an analytics widget class. |
| Money | Seeded `daily_stats` → metric totals equal `StatsQuery` for the same window and filters. |
| Chargebacks | Two publishers with reversals in window → ranking order and payout; switching source changes the group key. |
| Filters | Publisher filter hides the other publisher on money, chargebacks, and fraud. |
| Fraud unknown | Event without `context.ip` is **Unknown** when ranked by IP. |
| Status | Breakdown counts sum to the conversion count in the window, not to revenue. |

Reuse the `stats:aggregate` fixture style in `tests/Feature/Money/AggregateDailyStatsTest.php`. New tests live under `tests/Feature/Panel/`.

## 9. Out of scope (later slices)

Hourly series. Offer or device columns on `daily_stats`. Inline block actions. Network reconciliation table (`docs/06` § network compare). Publisher-facing analytics. Exports from this page.

## 10. Docs to update when this ships

- `docs/07-admin-panel.md` §2 (nav) and §3 (dashboard vs Analytics).
- `docs/12-roadmap.md` if a statistics checkbox is still open.
