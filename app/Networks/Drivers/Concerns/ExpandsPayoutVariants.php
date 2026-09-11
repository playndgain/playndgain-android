<?php

namespace App\Networks\Drivers\Concerns;

use App\Networks\DTO\OfferDTO;

/**
 * One upstream offer, several payouts, one `offers` row each — docs/02 §7.
 *
 * The tracking platforms price by geography. A single Affise offer routinely
 * carries one `payments[]` entry per country, and a Swaarm ad carries a base
 * payout plus `targeted_payouts[]`. Our `offers` table has one `payout` column
 * and the wall already filters by country and device and ranks by payout, so
 * the smallest correct representation is **one row per clean payout variant**
 * — no schema change, and the wall shows each user the price that applies to
 * them.
 *
 * The alternative was to skip any offer with more than one payout. It was
 * rejected because that is most of an Affise catalogue: the connector would
 * sync almost nothing.
 *
 * What "clean" means is the whole design. A variant is accepted only when it is
 * expressible as countries and devices and nothing else. Cities, regions, zip
 * codes, OS version bounds, ISPs, browsers, sub-id lists and exclusion flags are
 * all dimensions the wall cannot filter on, so an offer priced along one of them
 * would be shown to users it cannot pay for. Those are **skipped with a reason**
 * an admin can read on the sync row, never guessed at.
 *
 * Two rules do the real work:
 *
 *   - **Overlap.** A specific variant (`US`) and a broader one both matching a
 *     US user would put two tiles for one offer on their wall at two prices.
 *     Where the broader row has a finite country list, the specific countries
 *     are subtracted from it. Where the broader row is *worldwide* there is
 *     nothing to subtract from — an empty country list means "everywhere", and
 *     inverting it into "everywhere except US" is a list we do not have — so
 *     the base is skipped and the specific variants stand.
 *   - **Conflict.** Two variants with the same `(countries, devices)` key and
 *     different payouts cannot both be true, and picking one would be a guess
 *     about money. The whole offer is skipped.
 *
 * Row identity has to survive a re-sync or every pull churns the catalogue:
 * a single-variant offer keeps the bare upstream id, and a multi-variant offer
 * numbers its rows `{id}~{n}` over a deterministic sort, so the same upstream
 * data always produces the same `external_id`.
 */
trait ExpandsPayoutVariants
{
    /** §4 skip reasons, named once so the driver and the sync row agree. */
    public const SKIP_UNBOUNDED_BASE = 'base payout unbounded with country variants';

    public const SKIP_CONFLICTING = 'conflicting variants';

    public const SKIP_NO_VARIANTS = 'no payable variant';

    /**
     * Turn an offer's variants into the rows the wall will carry.
     *
     * `$build` receives one accepted variant and the `external_id` its row must
     * use, and returns the DTO. Everything about the upstream shape stays in the
     * driver; everything about which variants survive stays here.
     *
     * @param  list<array{countries: list<string>, devices: list<string>, payout: float, goals?: list<array{name: string, payout: float}>}>  $variants
     * @param  callable(array{countries: list<string>, devices: list<string>, payout: float, goals?: list<array{name: string, payout: float}>}, string): ?OfferDTO  $build
     * @return list<OfferDTO>
     */
    protected function expandVariants(string $externalId, array $variants, callable $build): array
    {
        $variants = $this->normaliseVariants($variants);

        if ($variants === []) {
            $this->skips()->skip($externalId, self::SKIP_NO_VARIANTS);

            return [];
        }

        if ($this->hasConflict($variants)) {
            $this->skips()->skip($externalId, self::SKIP_CONFLICTING);

            return [];
        }

        $variants = $this->resolveOverlaps($externalId, $variants);

        if ($variants === []) {
            return [];
        }

        $rows = [];
        $single = count($variants) === 1;

        foreach ($variants as $index => $variant) {
            // A single-variant offer keeps the bare upstream id, so a catalogue
            // that later grows a second price does not orphan the row that was
            // already there under a name nothing points at any more.
            $id = $single ? $externalId : $externalId.'~'.($index + 1);

            $row = $build($variant, $id);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Sorted, deduplicated, and in the deterministic order `~n` counts over.
     *
     * Both lists are sorted before the key is built: upstream field order is
     * not stable across pulls, and an unsorted key would make `["US","CA"]` and
     * `["CA","US"]` two different variants — two rows for one price this run
     * and one the next.
     *
     * @param  list<array<string, mixed>>  $variants
     * @return list<array<string, mixed>>
     */
    private function normaliseVariants(array $variants): array
    {
        $kept = [];
        $payoutByKey = [];

        foreach ($variants as $variant) {
            $countries = $this->sortedList($variant['countries'] ?? []);
            $devices = $this->sortedList($variant['devices'] ?? []);
            $payout = (float) ($variant['payout'] ?? 0);

            if ($payout <= 0) {
                continue;
            }

            $variant['countries'] = $countries;
            $variant['devices'] = $devices;
            $variant['payout'] = $payout;
            $variant['key'] = implode(',', $countries).'|'.implode(',', $devices);

            // Same audience at the same price twice is a repeat, not a
            // conflict. Networks emit a row per goal; the goals are merged
            // upstream of here and the leftover entry says nothing new. A
            // *different* price on the same key is left in place for
            // `hasConflict()` to find.
            $key = (string) $variant['key'];

            if (array_key_exists($key, $payoutByKey) && $payoutByKey[$key] === $payout) {
                continue;
            }

            $payoutByKey[$key] ??= $payout;
            $kept[] = $variant;
        }

        usort($kept, fn (array $a, array $b): int => $a['key'] <=> $b['key']);

        return $kept;
    }

    /**
     * @param  list<array<string, mixed>>  $variants
     */
    private function hasConflict(array $variants): bool
    {
        $payoutByKey = [];

        foreach ($variants as $variant) {
            $key = (string) $variant['key'];

            if (isset($payoutByKey[$key]) && $payoutByKey[$key] !== $variant['payout']) {
                return true;
            }

            $payoutByKey[$key] = $variant['payout'];
        }

        return false;
    }

    /**
     * Subtract the specific from the broad, or drop a base that cannot be
     * narrowed.
     *
     * "Broader" here means a variant whose country list is a strict superset of
     * another's on the same devices, or the worldwide list. Only countries are
     * subtracted: devices are a small closed set and an offer priced separately
     * for android and ios is two non-overlapping rows already.
     *
     * @param  list<array<string, mixed>>  $variants
     * @return list<array<string, mixed>>
     */
    private function resolveOverlaps(string $externalId, array $variants): array
    {
        if (count($variants) === 1) {
            return $variants;
        }

        $specific = array_values(array_filter($variants, fn (array $variant): bool => $variant['countries'] !== []));
        $targeted = array_values(array_unique(array_merge(
            ...array_map(fn (array $variant): array => $variant['countries'], $specific)
        )));

        $kept = [];

        foreach ($variants as $variant) {
            if ($variant['countries'] === []) {
                if ($targeted !== []) {
                    // Worldwide, with country variants alongside it. There is no
                    // list to subtract from, so the base would double every
                    // targeted country on the wall at a second price.
                    $this->skips()->skip($externalId, self::SKIP_UNBOUNDED_BASE);

                    continue;
                }

                $kept[] = $variant;

                continue;
            }

            $narrower = $this->countriesCoveredByNarrowerVariants($variant, $variants);
            $countries = array_values(array_diff($variant['countries'], $narrower));

            if ($countries === []) {
                // Every country it named is priced more specifically elsewhere.
                // Nothing left to sell, and nothing lost.
                continue;
            }

            $variant['countries'] = $countries;
            $kept[] = $variant;
        }

        return $kept;
    }

    /**
     * Countries this variant lists that a strictly narrower variant on the same
     * devices also lists.
     *
     * @param  array<string, mixed>  $variant
     * @param  list<array<string, mixed>>  $variants
     * @return list<string>
     */
    private function countriesCoveredByNarrowerVariants(array $variant, array $variants): array
    {
        $covered = [];

        foreach ($variants as $other) {
            if ($other['key'] === $variant['key'] || $other['devices'] !== $variant['devices']) {
                continue;
            }

            if (count($other['countries']) >= count($variant['countries']) || $other['countries'] === []) {
                continue;
            }

            $overlap = array_intersect($other['countries'], $variant['countries']);

            // A strict subset only. Two partially overlapping lists are not one
            // being a narrower version of the other, and subtracting would
            // silently drop inventory neither of them priced.
            if (count($overlap) === count($other['countries'])) {
                $covered = array_merge($covered, $other['countries']);
            }
        }

        return array_values(array_unique($covered));
    }

    /**
     * @return list<string>
     */
    private function sortedList(mixed $values): array
    {
        $list = array_values(array_unique(array_filter(
            array_map(
                fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '',
                is_array($values) ? $values : [],
            ),
            fn (string $value): bool => $value !== '',
        )));

        sort($list);

        return $list;
    }
}
