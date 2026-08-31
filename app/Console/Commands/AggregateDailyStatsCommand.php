<?php

namespace App\Console\Commands;

use App\Enums\ConversionStatus;
use App\Models\DailyStat;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds `daily_stats` for a date — docs/06 §7.
 *
 * Every chart in both panels reads this table and never sums `conversions`
 * directly, so two pages cannot disagree about the same number. Rebuilt hourly
 * for today and once nightly for yesterday, because postbacks arrive late.
 *
 * Rebuild, not upsert: the unique index spans two nullable columns and MySQL
 * treats NULLs as distinct, so an upsert would quietly duplicate every row with
 * no country. Deleting the day first is both correct and simpler.
 */
class AggregateDailyStatsCommand extends Command
{
    protected $signature = 'stats:aggregate
        {--today : Rebuild today}
        {--yesterday : Rebuild yesterday}
        {--date= : Rebuild a specific Y-m-d}
        {--days=1 : Rebuild this many days back from the chosen date}';

    protected $description = 'Rebuild pre-aggregated daily reporting stats';

    public function handle(): int
    {
        $anchor = match (true) {
            (bool) $this->option('yesterday') => CarbonImmutable::yesterday(),
            $this->option('date') !== null => CarbonImmutable::parse((string) $this->option('date')),
            default => CarbonImmutable::today(),
        };

        $days = max(1, (int) $this->option('days'));

        for ($offset = 0; $offset < $days; $offset++) {
            $date = $anchor->subDays($offset);
            $rows = $this->rebuild($date);

            $this->components->info("{$date->toDateString()}: {$rows} rows");
        }

        return self::SUCCESS;
    }

    private function rebuild(CarbonImmutable $date): int
    {
        $start = $date->startOfDay();
        $end = $date->endOfDay();
        $rows = [];

        // DB::table, not Eloquent: these are aggregate rows, not models, and the
        // query builder also sidesteps PublisherScope — a publisher triggering a
        // rebuild through a panel action must not aggregate only their own rows.
        $clicks = DB::table('offer_clicks')
            ->selectRaw('publisher_id, app_id, network_id, country, COUNT(*) AS clicks')
            ->whereBetween('created_at', [$start, $end])
            ->where('is_test', false)
            ->groupBy('publisher_id', 'app_id', 'network_id', 'country')
            ->get();

        foreach ($clicks as $click) {
            $rows[$this->key($click)] = [
                'publisher_id' => $click->publisher_id,
                'app_id' => $click->app_id,
                'network_id' => $click->network_id,
                'country' => $click->country,
                'clicks' => (int) $click->clicks,
                'conversions' => 0,
                'reversals' => 0,
                'revenue' => '0',
                'payout' => '0',
            ];
        }

        // A reversed conversion keeps its reversal count but contributes no
        // revenue: the day it landed on has to shrink once the money goes back.
        $conversions = DB::table('conversions')
            ->selectRaw('publisher_id, app_id, network_id, country')
            ->selectRaw('SUM(status = ?) AS conversions', [ConversionStatus::Approved->value])
            ->selectRaw('SUM(status = ?) AS reversals', [ConversionStatus::Reversed->value])
            ->selectRaw('SUM(IF(status = ?, revenue, 0)) AS revenue', [ConversionStatus::Approved->value])
            ->selectRaw('SUM(IF(status = ?, payout, 0)) AS payout', [ConversionStatus::Approved->value])
            ->whereBetween('created_at', [$start, $end])
            ->where('is_test', false)
            ->groupBy('publisher_id', 'app_id', 'network_id', 'country')
            ->get();

        foreach ($conversions as $conversion) {
            $key = $this->key($conversion);

            $rows[$key] ??= [
                'publisher_id' => $conversion->publisher_id,
                'app_id' => $conversion->app_id,
                'network_id' => $conversion->network_id,
                'country' => $conversion->country,
                'clicks' => 0,
            ];

            $rows[$key]['conversions'] = (int) $conversion->conversions;
            $rows[$key]['reversals'] = (int) $conversion->reversals;
            $rows[$key]['revenue'] = (string) $conversion->revenue;
            $rows[$key]['payout'] = (string) $conversion->payout;
        }

        DB::transaction(function () use ($date, $rows): void {
            DailyStat::query()->withoutGlobalScopes()->whereDate('date', $date)->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                DailyStat::query()->insert(array_map(fn (array $row): array => [
                    ...$row,
                    'date' => $date->toDateString(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ], $chunk));
            }
        });

        return count($rows);
    }

    private function key(object $row): string
    {
        return implode('|', [$row->publisher_id, $row->app_id, $row->network_id ?? '-', $row->country ?? '-']);
    }
}
