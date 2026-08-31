<?php

namespace App\Networks\Drivers\Concerns;

use App\Enums\ConversionType;

/**
 * Normalizations every driver needs and `AbstractDriver` does not carry.
 * Network payloads are `mixed` all the way down — these two turn that into the
 * shapes `OfferDTO` and `PostbackDTO` promise the rest of the app.
 */
trait NormalizesFields
{
    protected function nullableString(mixed $value): ?string
    {
        return blank($value) || ! is_scalar($value) ? null : (string) $value;
    }

    /** Anything the enum does not know becomes the driver's default rather than a failed sync. */
    protected function conversionType(mixed $value, string $default = 'cpa'): string
    {
        $type = ConversionType::tryFrom(strtolower(trim((string) (is_scalar($value) ? $value : ''))));

        return $type === null ? $default : $type->value;
    }

    /**
     * Category fields arrive as strings, as lists of strings, and as lists of
     * objects — every network picks one. Flattened to plain names; the label is
     * kept verbatim because it is shown to end users on the wall.
     *
     * @return list<string>
     */
    protected function categoryNames(mixed $categories): array
    {
        if (blank($categories)) {
            return [];
        }

        return collect(is_array($categories) ? $categories : [$categories])
            ->map(fn (mixed $category): mixed => is_array($category)
                ? ($category['name'] ?? $category['title'] ?? $category['slug'] ?? null)
                : $category)
            ->map(fn (mixed $category): ?string => $this->nullableString($category))
            ->filter()
            ->map(fn (string $category): string => trim($category))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The steps of a multi-step offer, in the shape `offers.goals` stores.
     *
     * Seven networks, seven spellings: `name`/`payout`, `event_name`/
     * `event_payout`, `description`/`revenue`, and Monlix nesting the figure
     * one level down at `payouts.0.payout`. Dot paths rather than plain keys
     * are what let that last one use this method instead of its own loop.
     *
     * A step with no label is dropped. "Step 2 — 4.25" tells the user nothing
     * they cannot already see in the total, and a blank row in a list of tasks
     * reads as a bug.
     *
     * @param  array<int, mixed>  $rows
     * @return list<array{name: string, payout: float}>
     */
    protected function goalList(array $rows, string $namePath, string $payoutPath): array
    {
        return collect($rows)
            ->filter(fn (mixed $row): bool => is_array($row))
            ->map(fn (array $row): array => [
                'name' => (string) ($this->nullableString(data_get($row, $namePath)) ?? ''),
                'payout' => (float) data_get($row, $payoutPath, 0),
            ])
            ->filter(fn (array $goal): bool => $goal['name'] !== '')
            ->values()
            ->all();
    }

    /**
     * Sum of a multi-step offer's per-event payouts.
     *
     * @param  array<int, mixed>  $events
     */
    protected function sumPayouts(array $events, string $key = 'payout'): float
    {
        return collect($events)
            ->filter(fn (mixed $event): bool => is_array($event))
            ->sum(fn (array $event): float => (float) ($event[$key] ?? 0));
    }
}
