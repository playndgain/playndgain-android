<?php

namespace App\Networks\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The latest answer for every (country, platform) cell of one network's probe
 * matrix, and the latest body of every offer those answers named.
 *
 * A cell is written the moment its probe returns, before anything is yielded
 * to the sync job, so a run that dies later still leaves what it learned. An
 * empty answer is stored too: "nothing here" is a result, and a cell with no
 * row is re-probed first by the rolling tier.
 */
final class ProbeSnapshots
{
    public function __construct(private readonly int $networkId) {}

    /**
     * @param  array<string, array<string, mixed>>  $offers  external id => payload
     */
    public function record(string $country, string $platform, array $offers, CarbonInterface $at): void
    {
        DB::transaction(function () use ($country, $platform, $offers, $at): void {
            DB::table('network_probe_cells')->upsert([[
                'network_id' => $this->networkId,
                'country' => $country,
                'platform' => $platform,
                'offer_ids' => json_encode(array_map('strval', array_keys($offers))),
                'probed_at' => $at,
            ]], ['network_id', 'country', 'platform'], ['offer_ids', 'probed_at']);

            foreach (array_chunk($offers, 200, true) as $chunk) {
                $rows = [];

                foreach ($chunk as $externalId => $payload) {
                    $rows[] = [
                        'network_id' => $this->networkId,
                        'external_id' => (string) $externalId,
                        'payload' => json_encode($payload),
                        'seen_at' => $at,
                    ];
                }

                DB::table('network_probe_offers')->upsert($rows, ['network_id', 'external_id'], ['payload', 'seen_at']);
            }
        });
    }

    /**
     * An offer body is refreshed whenever any cell sees it, so it is never
     * older than the newest cell that names it. One cutoff for both tables
     * therefore never strands a cell without its bodies.
     */
    public function prune(CarbonInterface $cutoff): void
    {
        DB::table('network_probe_cells')
            ->where('network_id', $this->networkId)
            ->where('probed_at', '<', $cutoff)
            ->delete();

        DB::table('network_probe_offers')
            ->where('network_id', $this->networkId)
            ->where('seen_at', '<', $cutoff)
            ->delete();
    }

    /**
     * When each country was last probed on its stalest platform.
     *
     * @return array<string, Carbon> ISO-2 => oldest probed_at
     */
    public function oldestProbePerCountry(): array
    {
        return DB::table('network_probe_cells')
            ->where('network_id', $this->networkId)
            ->groupBy('country')
            ->selectRaw('country, MIN(probed_at) AS oldest')
            ->pluck('oldest', 'country')
            ->map(fn (mixed $at): Carbon => Carbon::parse((string) $at))
            ->all();
    }

    /**
     * Every offer any stored cell names, with the countries that named it.
     *
     * @return array<string, array{payload: array<string, mixed>, countries: list<string>}>
     */
    public function catalogue(): array
    {
        /** @var array<string, array<string, true>> $countries */
        $countries = [];

        $cells = DB::table('network_probe_cells')
            ->where('network_id', $this->networkId)
            ->get(['country', 'offer_ids']);

        foreach ($cells as $cell) {
            $ids = json_decode((string) $cell->offer_ids, true);

            foreach (is_array($ids) ? $ids : [] as $id) {
                $countries[(string) $id][(string) $cell->country] = true;
            }
        }

        $catalogue = [];

        foreach (array_chunk(array_keys($countries), 500) as $ids) {
            $rows = DB::table('network_probe_offers')
                ->where('network_id', $this->networkId)
                ->whereIn('external_id', array_map('strval', $ids))
                ->get(['external_id', 'payload']);

            foreach ($rows as $row) {
                $payload = json_decode((string) $row->payload, true);
                $id = (string) $row->external_id;

                if (! is_array($payload)) {
                    continue;
                }

                $list = array_keys($countries[$id]);
                sort($list);

                $catalogue[$id] = ['payload' => $payload, 'countries' => $list];
            }
        }

        ksort($catalogue, SORT_STRING);

        return $catalogue;
    }
}
