<?php

namespace App\Services\Reporting;

use Carbon\CarbonImmutable;

/**
 * One parsed filter set for the admin Analytics page.
 *
 * Widgets never compute a date range themselves. Current and previous windows
 * are derived here so a metric and a chart cannot disagree about "7d".
 */
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

    /**
     * @param  array<string, mixed>  $filters
     */
    public static function fromFilters(array $filters): self
    {
        $today = CarbonImmutable::today();
        $range = is_string($filters['range'] ?? null) ? $filters['range'] : '7d';

        [$from, $to] = match ($range) {
            'today' => [$today, $today],
            '30d' => [$today->subDays(29), $today],
            'month' => [$today->startOfMonth(), $today],
            'last_month' => [$today->subMonth()->startOfMonth(), $today->subMonth()->endOfMonth()],
            default => [$today->subDays(6), $today],
        };

        [$previousFrom, $previousTo] = match ($range) {
            'today' => [$today->subDay(), $today->subDay()],
            '30d' => [$today->subDays(59), $today->subDays(30)],
            'month' => [$today->subMonth()->startOfMonth(), $today->subMonth()->endOfMonth()],
            'last_month' => [
                $today->subMonth()->startOfMonth()->subMonth(),
                $today->subMonth()->startOfMonth()->subDay(),
            ],
            default => [$today->subDays(13), $today->subDays(7)],
        };

        $countries = [];

        foreach (is_array($filters['countries'] ?? null) ? $filters['countries'] : [] as $code) {
            if (! is_string($code)) {
                continue;
            }

            $normalised = strtoupper($code);

            if (strlen($normalised) === 2) {
                $countries[] = $normalised;
            }
        }

        return new self(
            from: $from,
            to: $to,
            previousFrom: $previousFrom,
            previousTo: $previousTo,
            publisherId: self::intId($filters['publisher_id'] ?? null),
            networkId: self::intId($filters['network_id'] ?? null),
            appId: self::intId($filters['app_id'] ?? null),
            countries: array_values(array_unique($countries)),
        );
    }

    public function stats(): StatsQuery
    {
        return $this->apply($this->from, $this->to);
    }

    public function previousStats(): StatsQuery
    {
        return $this->apply($this->previousFrom, $this->previousTo);
    }

    /** Same filters, looking at the previous window as if it were current. */
    public function previous(): self
    {
        return new self(
            from: $this->previousFrom,
            to: $this->previousTo,
            previousFrom: $this->previousFrom,
            previousTo: $this->previousTo,
            publisherId: $this->publisherId,
            networkId: $this->networkId,
            appId: $this->appId,
            countries: $this->countries,
        );
    }

    private function apply(CarbonImmutable $from, CarbonImmutable $to): StatsQuery
    {
        $query = StatsQuery::make()->between($from, $to)->forApp($this->appId)->forNetwork($this->networkId)->forCountries($this->countries);

        if ($this->publisherId !== null) {
            $query->forPublisher($this->publisherId);
        }

        return $query;
    }

    private static function intId(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
