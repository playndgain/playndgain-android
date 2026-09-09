<?php

namespace App\Services\Reporting;

use App\Enums\ConversionStatus;
use App\Enums\FraudActionTaken;
use App\Enums\SourceDimension;
use App\Models\App;
use App\Models\Conversion;
use App\Models\FraudEvent;
use App\Models\Publisher;
use App\Services\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use stdClass;

/**
 * Chargebacks, status counts, and fraud rankings for admin Analytics.
 *
 * Money KPIs stay on StatsQuery. This class may sum reversed payout only,
 * and only under the Chargebacks label.
 */
final class RiskQuery
{
    private function __construct(private readonly AnalyticsWindow $window) {}

    public static function make(AnalyticsWindow $window): self
    {
        return new self($window);
    }

    /**
     * @return array{pending: int, approved: int, reversed: int, rejected: int}
     */
    public function statusCounts(): array
    {
        $counts = $this->conversions(reversedOnly: false)
            ->toBase()
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'pending' => (int) ($counts[ConversionStatus::Pending->value] ?? 0),
            'approved' => (int) ($counts[ConversionStatus::Approved->value] ?? 0),
            'reversed' => (int) ($counts[ConversionStatus::Reversed->value] ?? 0),
            'rejected' => (int) ($counts[ConversionStatus::Rejected->value] ?? 0),
        ];
    }

    /**
     * @return array{count: int, payout: string}
     */
    public function chargebacks(): array
    {
        /** @var object{count: int|string|null, payout: string|null}|null $row */
        $row = $this->conversions(reversedOnly: true)
            ->selectRaw('COUNT(*) AS count')
            ->selectRaw('COALESCE(SUM(payout), 0) AS payout')
            ->toBase()
            ->first();

        return [
            'count' => (int) ($row->count ?? 0),
            'payout' => Money::format($row->payout ?? '0'),
        ];
    }

    /**
     * @return list<object{key: string, label: string, count: int, payout: string}>
     */
    public function chargebacksBy(SourceDimension $dimension): array
    {
        $bucket = $this->conversionBucket($dimension);

        $rows = $this->conversions(reversedOnly: true)
            ->selectRaw("{$bucket} AS bucket")
            ->selectRaw('COUNT(*) AS count')
            ->selectRaw('COALESCE(SUM(payout), 0) AS payout')
            ->when($dimension === SourceDimension::Offer, fn (Builder $query) => $query->selectRaw('MAX(offer_name) AS offer_name'))
            ->groupByRaw($bucket)
            ->orderByDesc('payout')
            ->orderByDesc('count')
            ->limit(10)
            ->toBase()
            ->get();

        $labels = $this->conversionLabels($dimension, $rows);

        return $rows->map(function (object $row) use ($dimension, $labels): object {
            $key = $this->bucketKey($row->bucket);

            return (object) [
                'key' => $key,
                'label' => $labels[$key] ?? $this->fallbackLabel($dimension, $key, $row),
                'count' => (int) $row->count,
                'payout' => Money::format($row->payout),
            ];
        })->values()->all();
    }

    /**
     * @return array{blocked: int, flagged: int, scored: int}
     */
    public function fraudActions(): array
    {
        if ($this->window->networkId !== null) {
            return ['blocked' => 0, 'flagged' => 0, 'scored' => 0];
        }

        $counts = $this->fraudEvents()
            ->toBase()
            ->selectRaw('action_taken, COUNT(*) AS total')
            ->groupBy('action_taken')
            ->pluck('total', 'action_taken');

        return [
            'blocked' => (int) ($counts[FraudActionTaken::Blocked->value] ?? 0),
            'flagged' => (int) ($counts[FraudActionTaken::Flagged->value] ?? 0),
            'scored' => (int) ($counts[FraudActionTaken::Scored->value] ?? 0),
        ];
    }

    /**
     * @return list<object{key: string, count: int}>
     */
    public function fraudByRule(int $limit = 10): array
    {
        if ($this->window->networkId !== null) {
            return [];
        }

        return $this->fraudEvents()
            ->selectRaw('rule_key AS bucket, COUNT(*) AS total')
            ->groupBy('rule_key')
            ->orderByDesc('total')
            ->limit($limit)
            ->toBase()
            ->get()
            ->map(fn (object $row): object => (object) [
                'key' => (string) $row->bucket,
                'count' => (int) $row->total,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<object{key: string, label: string, count: int}>
     */
    public function fraudBy(SourceDimension $dimension): array
    {
        if ($this->window->networkId !== null) {
            return [];
        }

        $bucket = $this->fraudBucket($dimension);

        $rows = $this->fraudEvents()
            ->selectRaw("{$bucket} AS bucket")
            ->selectRaw('COUNT(*) AS count')
            ->groupByRaw($bucket)
            ->orderByDesc('count')
            ->limit(10)
            ->toBase()
            ->get();

        $labels = $this->fraudLabels($dimension, $rows);

        return $rows->map(function (object $row) use ($dimension, $labels): object {
            $key = $this->bucketKey($row->bucket);

            return (object) [
                'key' => $key,
                'label' => $labels[$key] ?? $this->fallbackLabel($dimension, $key, $row),
                'count' => (int) $row->count,
            ];
        })->values()->all();
    }

    /**
     * @return list<FraudEvent>
     */
    public function latestFraud(int $limit = 8): array
    {
        if ($this->window->networkId !== null) {
            return [];
        }

        return $this->fraudEvents()
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * @return Builder<Conversion>
     */
    private function conversions(bool $reversedOnly): Builder
    {
        $query = Conversion::query()->withoutGlobalScopes()->where('is_test', false);

        if ($reversedOnly) {
            $query->where('status', ConversionStatus::Reversed)
                ->whereBetween('reversed_at', [$this->window->from->startOfDay(), $this->window->to->endOfDay()]);
        } else {
            $query->whereBetween('created_at', [$this->window->from->startOfDay(), $this->window->to->endOfDay()]);
        }

        $this->scopeSubject($query);

        return $query;
    }

    /**
     * @return Builder<FraudEvent>
     */
    private function fraudEvents(): Builder
    {
        $query = FraudEvent::query()
            ->whereBetween('created_at', [$this->window->from->startOfDay(), $this->window->to->endOfDay()]);

        if ($this->window->publisherId !== null) {
            $query->where('publisher_id', $this->window->publisherId);
        }

        if ($this->window->appId !== null) {
            $query->where('app_id', $this->window->appId);
        }

        if ($this->window->countries !== []) {
            $query->whereIn('context->country', $this->window->countries);
        }

        return $query;
    }

    /**
     * @param  Builder<Conversion>  $query
     */
    private function scopeSubject(Builder $query): void
    {
        if ($this->window->publisherId !== null) {
            $query->where('publisher_id', $this->window->publisherId);
        }

        if ($this->window->networkId !== null) {
            $query->where('network_id', $this->window->networkId);
        }

        if ($this->window->appId !== null) {
            $query->where('app_id', $this->window->appId);
        }

        if ($this->window->countries !== []) {
            $query->whereIn('country', $this->window->countries);
        }
    }

    private function conversionBucket(SourceDimension $dimension): string
    {
        return match ($dimension) {
            SourceDimension::Publisher => 'publisher_id',
            SourceDimension::App => 'app_id',
            SourceDimension::Offer => "COALESCE(CAST(offer_id AS CHAR), 'unknown')",
            SourceDimension::Country => "COALESCE(NULLIF(country, ''), 'unknown')",
            SourceDimension::Ip => "COALESCE(NULLIF(ip, ''), 'unknown')",
            SourceDimension::EndUser => "CONCAT(publisher_id, ':', end_user_id)",
        };
    }

    private function fraudBucket(SourceDimension $dimension): string
    {
        $json = fn (string $key): string => "COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(context, '$.{$key}')), ''), 'unknown')";

        return match ($dimension) {
            SourceDimension::Publisher => "COALESCE(CAST(publisher_id AS CHAR), 'unknown')",
            SourceDimension::App => "COALESCE(CAST(app_id AS CHAR), 'unknown')",
            SourceDimension::Offer => $json('offer_id'),
            SourceDimension::Country => $json('country'),
            SourceDimension::Ip => $json('ip'),
            SourceDimension::EndUser => "CONCAT(COALESCE(CAST(publisher_id AS CHAR), '0'), ':', COALESCE(end_user_id, 'unknown'))",
        };
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     * @return array<string, string>
     */
    private function conversionLabels(SourceDimension $dimension, $rows): array
    {
        $keys = $rows->map(fn (object $row): string => $this->bucketKey($row->bucket))
            ->reject(fn (string $key): bool => $key === 'unknown')
            ->values();

        return match ($dimension) {
            SourceDimension::Publisher => $this->publisherLabels($keys->map(fn (string $key): int => (int) $key)->all()),
            SourceDimension::App => $this->appLabels($keys->map(fn (string $key): int => (int) $key)->all()),
            SourceDimension::Offer => $rows->mapWithKeys(fn (object $row): array => [
                $this->bucketKey($row->bucket) => (string) ($row->offer_name ?? 'Unknown'),
            ])->all(),
            SourceDimension::EndUser => $this->endUserLabels($keys->all()),
            default => [],
        };
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     * @return array<string, string>
     */
    private function fraudLabels(SourceDimension $dimension, $rows): array
    {
        $keys = $rows->map(fn (object $row): string => $this->bucketKey($row->bucket))
            ->reject(fn (string $key): bool => $key === 'unknown')
            ->values();

        return match ($dimension) {
            SourceDimension::Publisher => $this->publisherLabels($keys->map(fn (string $key): int => (int) $key)->all()),
            SourceDimension::App => $this->appLabels($keys->map(fn (string $key): int => (int) $key)->all()),
            SourceDimension::EndUser => $this->endUserLabels($keys->all()),
            default => [],
        };
    }

    /**
     * @param  list<int>  $ids
     * @return array<int|string, string>
     */
    private function publisherLabels(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $labels = [];

        foreach (Publisher::query()->withoutGlobalScopes()->with('user:id,name')->whereIn('id', $ids)->get() as $publisher) {
            $labels[(string) $publisher->id] = "#{$publisher->pub_id} {$publisher->user?->name}";
        }

        return $labels;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int|string, string>
     */
    private function appLabels(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $labels = [];

        foreach (App::query()->withoutGlobalScopes()->whereIn('id', $ids)->pluck('name', 'id') as $id => $name) {
            $labels[(string) $id] = $name;
        }

        return $labels;
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    private function endUserLabels(array $keys): array
    {
        $publisherIds = [];

        foreach ($keys as $key) {
            $publisherIds[] = (int) strstr($key, ':', true);
        }

        $publishers = Publisher::query()
            ->withoutGlobalScopes()
            ->whereIn('id', array_values(array_unique(array_filter($publisherIds))))
            ->pluck('pub_id', 'id');

        $labels = [];

        foreach ($keys as $key) {
            [$publisherId, $endUserId] = array_pad(explode(':', $key, 2), 2, 'unknown');
            $pubId = $publishers[(int) $publisherId] ?? $publisherId;
            $labels[$key] = "#{$pubId} / {$endUserId}";
        }

        return $labels;
    }

    private function fallbackLabel(SourceDimension $dimension, string $key, object $row): string
    {
        if ($key === 'unknown') {
            return 'Unknown';
        }

        if ($dimension === SourceDimension::Offer && isset($row->offer_name)) {
            return (string) $row->offer_name;
        }

        return $key;
    }

    private function bucketKey(mixed $bucket): string
    {
        if ($bucket === null || $bucket === '') {
            return 'unknown';
        }

        return (string) $bucket;
    }
}
