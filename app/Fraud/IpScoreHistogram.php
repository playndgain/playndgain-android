<?php

namespace App\Fraud;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * How the third-party risk scores this platform actually sees are distributed —
 * the design spec's §4.5.
 *
 * `IpRiskScoreRule` refuses a wall render at or above a configured threshold,
 * and that threshold currently sits at 27, well under the ~75 IPQualityScore
 * itself calls suspicious. Nothing else on the platform can answer "what share
 * of my traffic is that", so the setting would otherwise be tuned from
 * publisher complaints rather than from data.
 *
 * **Sampled at render, not at click.** The two obvious sources are both biased,
 * and both in the direction that ruins the answer: `fraud_events` only records
 * a rule that *fired*, so it sees nothing below the threshold, and a column on
 * `offer_clicks` only sees traffic that got as far as a click, so it sees
 * nothing above it — a refused render never writes a click row.
 * `WallGate::context()` resolves a verdict for every impression, refused and
 * served alike, and is the only point that sees both sides.
 *
 * **Counters, not rows.** `ip_providers`' own docblock states the rule this
 * follows: a daily counter written on the render path lives in the cache, never
 * in a column, because a table write on that path is the cost the whole IP
 * design exists to avoid. One `increment` per render, against a key that is
 * already hot.
 *
 * The trade-off that buys: a cache flush loses the history. That is acceptable
 * for a tuning instrument — it refills within a day and `fraud_events` still
 * holds every actual block as a cross-check — but it is the reason this is not
 * the place to put anything anyone needs to audit later.
 */
final class IpScoreHistogram
{
    /** Scores are 0–100 inclusive, so a day holds at most 101 counters. */
    public const MAX_SCORE = 100;

    /** Windows the Protection page offers. */
    public const WINDOWS = [1, 7, 30];

    /**
     * A dated key only has to outlive its own day, but a TTL of exactly the
     * window would expire the oldest day mid-read. The longest window plus a
     * day of slack — same reasoning as `IpProvider::countRequest()`.
     */
    private const TTL_DAYS = 31;

    /**
     * One increment per scored render. A null score is not a zero — it means
     * nobody rated the address, which is most of them until a provider is
     * configured — and counting it as zero would put a spike at the bottom of
     * the chart that is really an absence of data.
     */
    public static function record(?int $score): void
    {
        if ($score === null || $score < 0 || $score > self::MAX_SCORE) {
            return;
        }

        $key = self::key(CarbonImmutable::now()->utc()->toDateString(), $score);

        Cache::add($key, 0, CarbonImmutable::now()->addDays(self::TTL_DAYS));
        Cache::increment($key);
    }

    /**
     * Every score counted in the last `$days` days, keyed by score.
     *
     * Per score rather than per band, so that "how many would this threshold
     * refuse" is an exact count instead of an estimate from whichever band the
     * threshold happens to fall inside. One `many()` is one MGET.
     *
     * @return array<int, int>
     */
    public static function scores(int $days): array
    {
        $keys = [];
        $today = CarbonImmutable::now()->utc()->startOfDay();

        for ($day = 0; $day < max(1, $days); $day++) {
            $date = $today->subDays($day)->toDateString();

            for ($score = 0; $score <= self::MAX_SCORE; $score++) {
                $keys[] = self::key($date, $score);
            }
        }

        $counts = array_fill(0, self::MAX_SCORE + 1, 0);

        foreach (Cache::many($keys) as $key => $value) {
            if ($value === null) {
                continue;
            }

            $score = (int) substr((string) $key, strrpos((string) $key, ':') + 1);
            $counts[$score] += (int) $value;
        }

        return $counts;
    }

    /**
     * The chart: ten bands of ten, with 100 folded into the last one so the
     * top band reads 90–100 rather than leaving a single-value band of its own.
     *
     * @param  array<int, int>  $scores
     * @return list<array{label: string, floor: int, ceiling: int, count: int}>
     */
    public static function bands(array $scores): array
    {
        $bands = [];

        for ($floor = 0; $floor < self::MAX_SCORE; $floor += 10) {
            $ceiling = $floor === 90 ? self::MAX_SCORE : $floor + 9;
            $count = 0;

            for ($score = $floor; $score <= $ceiling; $score++) {
                $count += $scores[$score] ?? 0;
            }

            $bands[] = [
                'label' => "{$floor}–{$ceiling}",
                'floor' => $floor,
                'ceiling' => $ceiling,
                'count' => $count,
            ];
        }

        return $bands;
    }

    /**
     * How many of those renders the current threshold refuses.
     *
     * `IpRiskScoreRule` triggers at `>= threshold`, so this counts from the
     * threshold itself — off by one here would misreport the boundary the
     * operator is trying to place.
     *
     * @param  array<int, int>  $scores
     */
    public static function atOrAbove(array $scores, int $threshold): int
    {
        $total = 0;

        for ($score = max(0, $threshold); $score <= self::MAX_SCORE; $score++) {
            $total += $scores[$score] ?? 0;
        }

        return $total;
    }

    /** @param array<int, int> $scores */
    public static function total(array $scores): int
    {
        return array_sum($scores);
    }

    private static function key(string $date, int $score): string
    {
        return "ip_score:{$date}:{$score}";
    }
}
