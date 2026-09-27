<?php

namespace App\Services\Reporting;

use App\Enums\IdentityKind;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one way the admin Audience page reads audience numbers.
 *
 * The source is the wall identity ledger (`identity_sessions`, kind `wall`),
 * not `daily_stats`: a user who opens the wall and never clicks is absent
 * from clicks and conversions but present here. Test-mode visits never reach
 * the ledger, and panel logins are a different kind.
 *
 * One audience identity is `(app_id, end_user_id)` — the same textual user id
 * in two apps is two people. It is counted with MySQL's multi-column
 * `COUNT(DISTINCT app_id, end_user_id)`, so no concatenation can make two
 * identities collide.
 *
 * Resolve through `for()`: the page's widgets share one instance per filter
 * set per request, so the five cards and the chart run one daily query
 * between them. The flip side: rows written after the first read, with the
 * same filters in the same request (or test), are not seen — seed first.
 */
final class AudienceQuery
{
    /** Complete days the average DAU is taken over. */
    public const AVERAGE_DAYS = 30;

    /** Calendar days in MAU, today included. */
    public const MAU_DAYS = 30;

    /** @var Collection<string, int>|null */
    private ?Collection $daily = null;

    private ?int $mau = null;

    /** @var array{known: Collection<string, int>, unknown: int}|null */
    private ?array $countries = null;

    private function __construct(private readonly AudienceWindow $window) {}

    public static function for(AudienceWindow $window): self
    {
        $key = self::class.':'.$window->key();

        if (! app()->bound($key)) {
            app()->instance($key, new self($window));
        }

        return app($key);
    }

    /**
     * Distinct identities per day for the 30 complete days before today and
     * today itself — 31 keys, oldest first, zero-filled so a quiet day is a 0
     * in the chart and in the average rather than a missing point.
     *
     * @return Collection<string, int> Y-m-d => identities
     */
    public function daily(): Collection
    {
        if ($this->daily !== null) {
            return $this->daily;
        }

        $from = $this->window->today->subDays(self::AVERAGE_DAYS);

        $rows = $this->between($from, $this->openEnd())
            ->selectRaw('DATE(created_at) AS day')
            ->selectRaw('COUNT(DISTINCT app_id, end_user_id) AS users')
            ->groupByRaw('DATE(created_at)')
            ->pluck('users', 'day');

        $days = collect();

        for ($day = $from; $day->lessThanOrEqualTo($this->window->today); $day = $day->addDay()) {
            $key = $day->toDateString();
            $days->put($key, (int) ($rows[$key] ?? 0));
        }

        return $this->daily = $days;
    }

    public function todayDau(): int
    {
        return $this->daily()->get($this->window->today->toDateString(), 0);
    }

    public function yesterdayDau(): int
    {
        return $this->daily()->get($this->window->today->subDay()->toDateString(), 0);
    }

    /** Mean of the 30 complete days' distinct totals; zero days count. */
    public function averageDau(): float
    {
        return $this->completeDays()->sum() / self::AVERAGE_DAYS;
    }

    /**
     * The 30 complete days only, for the average and its sparkline.
     *
     * @return Collection<string, int>
     */
    public function completeDays(): Collection
    {
        return $this->daily()->except($this->window->today->toDateString());
    }

    /** Distinct identities over the trailing 30 calendar days, today included. */
    public function mau(): int
    {
        return $this->mau ??= $this->distinct(
            $this->window->today->subDays(self::MAU_DAYS - 1),
            $this->openEnd(),
        );
    }

    /** Today DAU over MAU as a percentage; null when there is no MAU to divide by. */
    public function stickiness(): ?float
    {
        $mau = $this->mau();

        return $mau === 0 ? null : $this->todayDau() / $mau * 100;
    }

    /**
     * Known-country identities in the selected range, largest first.
     *
     * @return Collection<string, int> ISO-2 => identities
     */
    public function countries(): Collection
    {
        return $this->countryTotals()['known'];
    }

    /** Identities in the selected range with no recorded country. */
    public function unknownCountry(): int
    {
        return $this->countryTotals()['unknown'];
    }

    /** Every identity active in the selected range, known or not. */
    public function rangeActive(): int
    {
        return $this->countries()->sum() + $this->unknownCountry();
    }

    /**
     * Each identity lands in exactly one bucket: the country of its most
     * recent session in the range that recorded one. Only an identity with no
     * recorded country on any session in the range is unknown — nothing is
     * borrowed from another identity or distributed across countries.
     *
     * @return array{known: Collection<string, int>, unknown: int}
     */
    private function countryTotals(): array
    {
        if ($this->countries !== null) {
            return $this->countries;
        }

        $ranked = $this->between($this->window->rangeFrom, $this->window->rangeUntil)
            ->select('country')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY app_id, end_user_id ORDER BY country IS NULL, created_at DESC, id DESC) AS pick');

        $rows = DB::query()
            ->fromSub($ranked, 'ranked')
            ->where('pick', 1)
            ->select('country')
            ->selectRaw('COUNT(*) AS users')
            ->groupBy('country')
            ->orderByDesc('users')
            ->orderBy('country')
            ->get();

        $known = collect();
        $unknown = 0;

        foreach ($rows as $row) {
            if ($row->country === null) {
                $unknown = (int) $row->users;

                continue;
            }

            $known->put((string) $row->country, (int) $row->users);
        }

        return $this->countries = ['known' => $known, 'unknown' => $unknown];
    }

    private function distinct(CarbonInterface $from, CarbonInterface $until): int
    {
        return (int) $this->between($from, $until)
            ->selectRaw('COUNT(DISTINCT app_id, end_user_id) AS users')
            ->value('users');
    }

    /** Live wall rows in `[from, until)`, scoped to the publisher and app filters. */
    private function between(CarbonInterface $from, CarbonInterface $until): Builder
    {
        return DB::table('identity_sessions')
            ->where('kind', IdentityKind::Wall->value)
            ->whereNotNull('app_id')
            ->whereNotNull('end_user_id')
            ->where('created_at', '>=', $from->toDateTimeString())
            ->where('created_at', '<', $until->toDateTimeString())
            ->when($this->window->publisherId !== null, fn (Builder $query) => $query->where('publisher_id', $this->window->publisherId))
            ->when($this->window->appId !== null, fn (Builder $query) => $query->where('app_id', $this->window->appId));
    }

    /** Exclusive end that includes the current second. */
    private function openEnd(): CarbonInterface
    {
        return $this->window->now->addSecond();
    }
}
