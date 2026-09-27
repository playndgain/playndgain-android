<?php

namespace App\Services\Reporting;

use Carbon\CarbonImmutable;

/**
 * One parsed filter set for the admin Audience page.
 *
 * The range only moves the GEO breakdown and the range summary. The headline
 * DAU/MAU cards and the daily chart keep their fixed definitions and read
 * `$today` / `$now` directly, so a short range can never quietly redefine MAU.
 * Every boundary is an application-timezone instant computed here and bound
 * into SQL — nothing downstream calls NOW() or CURDATE().
 */
final readonly class AudienceWindow
{
    public const RANGES = [
        'today' => 'Today',
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
        'month' => 'This month',
        'last_month' => 'Last month',
    ];

    public function __construct(
        public CarbonImmutable $now,
        public CarbonImmutable $today,
        public string $range,
        /** Inclusive start of the selected range. */
        public CarbonImmutable $rangeFrom,
        /** Exclusive end of the selected range. */
        public CarbonImmutable $rangeUntil,
        public ?int $publisherId,
        public ?int $appId,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public static function fromFilters(array $filters): self
    {
        $now = CarbonImmutable::now();
        $today = $now->startOfDay();
        $range = is_string($filters['range'] ?? null) && array_key_exists($filters['range'], self::RANGES)
            ? $filters['range']
            : '30d';

        // A range that includes today ends one second past now, so a session
        // written this second is inside it and nothing after it is.
        $open = $now->addSecond();

        [$from, $until] = match ($range) {
            'today' => [$today, $open],
            '7d' => [$today->subDays(6), $open],
            'month' => [$today->startOfMonth(), $open],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->startOfMonth()],
            default => [$today->subDays(29), $open],
        };

        return new self(
            now: $now,
            today: $today,
            range: $range,
            rangeFrom: $from,
            rangeUntil: $until,
            publisherId: self::intId($filters['publisher_id'] ?? null),
            appId: self::intId($filters['app_id'] ?? null),
        );
    }

    public function rangeLabel(): string
    {
        return self::RANGES[$this->range];
    }

    /** Identifies one filter set within a request, for memoising its query. */
    public function key(): string
    {
        return implode('|', [
            $this->now->toIso8601String(),
            $this->range,
            (string) $this->publisherId,
            (string) $this->appId,
        ]);
    }

    private static function intId(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
