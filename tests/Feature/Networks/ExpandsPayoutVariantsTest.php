<?php

use App\Models\Network;
use App\Models\Offer;
use App\Networks\AbstractDriver;
use App\Networks\Drivers\Concerns\ExpandsPayoutVariants;
use App\Networks\DTO\ConnectionResult;
use App\Networks\DTO\OfferDTO;
use App\Networks\DTO\PostbackDTO;
use Illuminate\Http\Request;

/**
 * §4 of the platform-connector design, on its own.
 *
 * The rules are small and the consequences are not: a mis-expanded offer either
 * shows a user two tiles for one thing at two prices, or sells them a payout the
 * advertiser will not honour in their country.
 */
class VariantHarness extends AbstractDriver
{
    use ExpandsPayoutVariants;

    public static function key(): string
    {
        return 'variant-harness';
    }

    public static function credentialSchema(): array
    {
        return [];
    }

    public function testConnection(): ConnectionResult
    {
        return ConnectionResult::ok('ok');
    }

    public function fetchOffers(): iterable
    {
        return [];
    }

    public function parsePostback(Request $request): ?PostbackDTO
    {
        return null;
    }

    public function verifyPostback(Request $request): bool
    {
        return false;
    }

    public function buildTrackingUrl(Offer $offer, string $clickId): string
    {
        return '';
    }

    /**
     * @param  list<array<string, mixed>>  $variants
     * @return list<OfferDTO>
     */
    public function expand(string $externalId, array $variants): array
    {
        return $this->expandVariants($externalId, $variants, fn (array $variant, string $id): OfferDTO => new OfferDTO(
            externalId: $id,
            name: 'Offer '.$externalId,
            trackingUrl: 'https://p.test/c?sub1={click_id}',
            payout: $variant['payout'],
            countries: $variant['countries'],
            devices: $variant['devices'],
            goals: $variant['goals'] ?? [],
        ));
    }
}

function harness(): VariantHarness
{
    return new VariantHarness(new Network(['driver' => 'variant-harness']));
}

/**
 * @param  list<string>  $countries
 * @param  list<string>  $devices
 * @return array<string, mixed>
 */
function variant(array $countries, float $payout, array $devices = []): array
{
    return ['countries' => $countries, 'devices' => $devices, 'payout' => $payout];
}

it('keeps the bare upstream id when an offer has one price', function () {
    $rows = harness()->expand('902', [variant(['US', 'CA'], 1.25)]);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->externalId)->toBe('902')
        ->and($rows[0]->payout)->toBe(1.25)
        // Sorted, so the same two countries in either upstream order are one row.
        ->and($rows[0]->countries)->toBe(['CA', 'US']);
});

it('numbers a multi-price offer deterministically across two runs', function () {
    $first = harness()->expand('902', [
        variant(['US'], 2.0),
        variant(['DE'], 1.0),
        variant(['GB'], 1.5),
    ]);

    // The same data in a different upstream order. A network reordering its
    // payments array must not churn every row's external_id.
    $second = harness()->expand('902', [
        variant(['GB'], 1.5),
        variant(['US'], 2.0),
        variant(['DE'], 1.0),
    ]);

    $ids = fn (array $rows): array => array_map(fn (OfferDTO $row): string => $row->externalId, $rows);
    $prices = fn (array $rows): array => array_combine($ids($rows), array_map(fn (OfferDTO $row): float => $row->payout, $rows));

    expect($ids($first))->toBe(['902~1', '902~2', '902~3'])
        ->and($ids($second))->toBe($ids($first))
        ->and($prices($second))->toBe($prices($first))
        // Sorted by country, so ~1 is DE whichever order they arrived in.
        ->and($first[0]->countries)->toBe(['DE']);
});

it('subtracts a targeted country from a broader list that names its countries', function () {
    $rows = harness()->expand('902', [
        variant(['US', 'CA', 'GB'], 1.0),
        variant(['US'], 3.0),
    ]);

    $byCountries = collect($rows)->keyBy(fn (OfferDTO $row): string => implode(',', $row->countries));

    // A US user sees one tile at $3, not two tiles at $3 and $1.
    expect($rows)->toHaveCount(2)
        ->and($byCountries->keys()->all())->toEqualCanonicalizing(['CA,GB', 'US'])
        ->and($byCountries['US']->payout)->toBe(3.0)
        ->and($byCountries['CA,GB']->payout)->toBe(1.0);
});

it('skips a worldwide base rather than inverting it', function () {
    $harness = harness();

    $rows = $harness->expand('902', [
        variant([], 1.0),
        variant(['US'], 3.0),
    ]);

    // "Everywhere except the US" is a list nobody has. The base goes and the
    // targeted row stands, with the reason on the sync row.
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->countries)->toBe(['US'])
        ->and($rows[0]->externalId)->toBe('902')
        ->and($harness->skippedOffers())->toHaveKey(VariantHarness::SKIP_UNBOUNDED_BASE)
        ->and($harness->skippedOffers()[VariantHarness::SKIP_UNBOUNDED_BASE]['samples'])->toBe(['902']);
});

it('keeps a worldwide payout when nothing is targeted alongside it', function () {
    $rows = harness()->expand('902', [variant([], 1.0)]);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->countries)->toBe([]);
});

it('skips the whole offer when two variants disagree about the same audience', function () {
    $harness = harness();

    $rows = $harness->expand('902', [
        variant(['US'], 1.0),
        variant(['US'], 3.0),
    ]);

    // Picking either one is a guess about money.
    expect($rows)->toBe([])
        ->and($harness->skippedOffers())->toHaveKey(VariantHarness::SKIP_CONFLICTING);
});

it('treats the same audience at the same price as a repeat, not a conflict', function () {
    $harness = harness();

    $rows = $harness->expand('902', [
        variant(['US'], 1.0),
        variant(['US'], 1.0),
    ]);

    expect($rows)->toHaveCount(1)
        ->and($harness->skippedOffers())->toBe([]);
});

it('prices android and ios separately without treating them as an overlap', function () {
    $rows = harness()->expand('902', [
        variant(['US'], 2.0, ['android']),
        variant(['US'], 4.0, ['ios']),
    ]);

    expect($rows)->toHaveCount(2)
        ->and(collect($rows)->pluck('payout')->all())->toEqualCanonicalizing([2.0, 4.0]);
});

it('drops a zero or negative payout and skips the offer when nothing is left', function () {
    $harness = harness();

    expect($harness->expand('902', [variant(['US'], 0.0)]))->toBe([])
        ->and($harness->skippedOffers())->toHaveKey(VariantHarness::SKIP_NO_VARIANTS);

    $rows = harness()->expand('903', [variant(['US'], 0.0), variant(['DE'], 1.0)]);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->externalId)->toBe('903');
});

it('drops a broader row whose every country is priced more specifically', function () {
    $rows = harness()->expand('902', [
        variant(['US', 'CA'], 1.0),
        variant(['US'], 3.0),
        variant(['CA'], 2.0),
    ]);

    expect(collect($rows)->map(fn (OfferDTO $row): string => implode(',', $row->countries))->all())
        ->toEqualCanonicalizing(['CA', 'US']);
});

it('leaves two partially overlapping lists alone rather than guessing', function () {
    // Neither is a narrower version of the other, so subtracting would drop
    // inventory neither of them priced. They stay as they are.
    $rows = harness()->expand('902', [
        variant(['US', 'CA'], 1.0),
        variant(['CA', 'GB'], 2.0),
    ]);

    expect($rows)->toHaveCount(2);
});

it('caps the samples it records per reason', function () {
    $harness = harness();

    foreach (range(1, 9) as $n) {
        $harness->expand((string) $n, [variant(['US'], 1.0), variant(['US'], 2.0)]);
    }

    $skips = $harness->skippedOffers()[VariantHarness::SKIP_CONFLICTING];

    expect($skips['count'])->toBe(9)
        ->and($skips['samples'])->toBe(['1', '2', '3', '4', '5']);
});
