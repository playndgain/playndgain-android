<?php

namespace App\Services\Reporting;

use App\Models\DailyStat;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use stdClass;

/**
 * The one way a panel reads reporting numbers — docs/06 §7.
 *
 * Everything here is a `daily_stats` read. No widget sums `conversions`
 * directly, because two pages summing the same thing two ways is how a
 * dashboard and a report end up disagreeing in front of a publisher.
 *
 * `daily_stats` already excludes test traffic, so nothing downstream has to
 * remember to filter it.
 */
final class StatsQuery
{
    /** @var Builder<DailyStat> */
    private Builder $query;

    private function __construct()
    {
        // withoutGlobalScopes so an admin widget sees the platform. The
        // publisher panel scopes explicitly through forPublisher() instead of
        // relying on the session, so both callers read the same way.
        $this->query = DailyStat::query()->withoutGlobalScopes();
    }

    public static function make(): self
    {
        return new self;
    }

    public function forPublisher(int $publisherId): self
    {
        $this->query->where('publisher_id', $publisherId);

        return $this;
    }

    public function forApp(?int $appId): self
    {
        if ($appId !== null) {
            $this->query->where('app_id', $appId);
        }

        return $this;
    }

    /**
     * Empty means every country, not none — a filter with nothing picked is an
     * unfiltered view.
     *
     * @param  list<string>  $countries
     */
    public function forCountries(array $countries): self
    {
        if ($countries !== []) {
            $this->query->whereIn('country', $countries);
        }

        return $this;
    }

    public function between(CarbonInterface $from, CarbonInterface $to): self
    {
        $this->query->whereBetween('date', [$from->toDateString(), $to->toDateString()]);

        return $this;
    }

    /**
     * @return array{clicks: int, conversions: int, reversals: int, revenue: string, payout: string, profit: string}
     */
    public function totals(): array
    {
        /** @var object{clicks: ?int, conversions: ?int, reversals: ?int, revenue: ?string, payout: ?string} $row */
        $row = (clone $this->query)
            ->selectRaw('COALESCE(SUM(clicks), 0) AS clicks')
            ->selectRaw('COALESCE(SUM(conversions), 0) AS conversions')
            ->selectRaw('COALESCE(SUM(reversals), 0) AS reversals')
            ->selectRaw('COALESCE(SUM(revenue), 0) AS revenue')
            ->selectRaw('COALESCE(SUM(payout), 0) AS payout')
            ->toBase()
            ->first();

        $revenue = (string) ($row->revenue ?? '0');
        $payout = (string) ($row->payout ?? '0');

        return [
            'clicks' => (int) ($row->clicks ?? 0),
            'conversions' => (int) ($row->conversions ?? 0),
            'reversals' => (int) ($row->reversals ?? 0),
            'revenue' => $revenue,
            'payout' => $payout,
            'profit' => bcsub($revenue, $payout, 6),
        ];
    }

    /**
     * One row per day, zero-filled across the range so a chart never draws a
     * gap where a quiet day should be a zero.
     *
     * @return Collection<string, object{date: string, clicks: int, conversions: int, revenue: string, payout: string}>
     */
    public function daily(CarbonInterface $from, CarbonInterface $to): Collection
    {
        $rows = (clone $this->query)
            ->selectRaw('DATE(date) AS date')
            ->selectRaw('SUM(clicks) AS clicks')
            ->selectRaw('SUM(conversions) AS conversions')
            ->selectRaw('SUM(revenue) AS revenue')
            ->selectRaw('SUM(payout) AS payout')
            ->groupByRaw('DATE(date)')
            ->toBase()
            ->get()
            ->keyBy(fn (object $row): string => (string) $row->date);

        $days = collect();

        for ($day = $from->copy()->startOfDay(); $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $key = $day->toDateString();

            $days->put($key, $rows->get($key) ?? (object) [
                'date' => $key,
                'clicks' => 0,
                'conversions' => 0,
                'revenue' => '0',
                'payout' => '0',
            ]);
        }

        return $days;
    }

    /**
     * One series per bucket, zero-filled per day. Feeds the stacked charts.
     *
     * @return Collection<string, array<string, float>> bucket => date => value
     */
    public function dailySeriesBy(string $column, string $metric, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $rows = (clone $this->query)
            ->selectRaw("{$column} AS bucket")
            ->selectRaw('DATE(date) AS day')
            ->selectRaw("SUM({$metric}) AS total")
            ->whereNotNull($column)
            ->groupByRaw("{$column}, DATE(date)")
            ->toBase()
            ->get();

        $skeleton = [];

        for ($day = $from->copy()->startOfDay(); $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $skeleton[$day->toDateString()] = 0.0;
        }

        $series = collect();

        foreach ($rows as $row) {
            $bucket = (string) $row->bucket;

            $series->put($bucket, [
                ...$series->get($bucket, $skeleton),
                (string) $row->day => (float) $row->total,
            ]);
        }

        return $series;
    }

    /**
     * Totals grouped by one dimension — country, app_id, network_id — ordered
     * by revenue.
     *
     * @return Collection<int, stdClass>
     */
    public function groupedBy(string $column, int $limit = 10): Collection
    {
        return (clone $this->query)
            ->selectRaw("{$column} AS bucket")
            ->selectRaw('SUM(clicks) AS clicks')
            ->selectRaw('SUM(conversions) AS conversions')
            ->selectRaw('SUM(revenue) AS revenue')
            ->selectRaw('SUM(payout) AS payout')
            ->whereNotNull($column)
            ->groupBy($column)
            ->orderByDesc('revenue')
            ->limit($limit)
            ->toBase()
            ->get();
    }
}
