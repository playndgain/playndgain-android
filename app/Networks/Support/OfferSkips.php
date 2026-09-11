<?php

namespace App\Networks\Support;

/**
 * Why a driver dropped an offer on the floor.
 *
 * Driver-side skips are silent today: a catalogue of 4,000 that yields 900 is
 * indistinguishable from a catalogue of 900, and the first question an admin
 * asks — "where did the rest go" — has no answer anywhere in the app. A driver
 * records the reason as it decides, and the sync job reads the tally back once
 * the generator is exhausted.
 *
 * Samples are capped because this is an admin summary, not a second copy of the
 * catalogue: five external ids per reason is enough to look one up upstream and
 * small enough to store on the sync row.
 */
final class OfferSkips
{
    /** Enough ids to check the reason against the partner's dashboard. */
    public const SAMPLE_LIMIT = 5;

    /** @var array<string, array{count: int, samples: list<string>}> */
    private array $reasons = [];

    public function skip(string $externalId, string $reason): void
    {
        $bucket = $this->reasons[$reason] ?? ['count' => 0, 'samples' => []];

        $bucket['count']++;

        if (count($bucket['samples']) < self::SAMPLE_LIMIT) {
            $bucket['samples'][] = $externalId;
        }

        $this->reasons[$reason] = $bucket;
    }

    /**
     * @return array<string, array{count: int, samples: list<string>}>
     */
    public function all(): array
    {
        return $this->reasons;
    }

    public function total(): int
    {
        return array_sum(array_column($this->reasons, 'count'));
    }
}
