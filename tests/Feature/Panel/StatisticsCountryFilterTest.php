<?php

use App\Filament\Publisher\Pages\Statistics;
use App\Models\App;
use App\Models\DailyStat;
use App\Models\Publisher;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/**
 * The country filter is a multi-select over `daily_stats` — the two things that
 * can break it are an empty selection meaning "nothing" instead of
 * "everything", and a multi selection reading as one value.
 */
function seedCountryStats(Publisher $publisher): void
{
    $app = App::factory()->create(['publisher_id' => $publisher->id]);

    foreach (['US' => 10, 'EG' => 20, 'DE' => 40] as $country => $payout) {
        DailyStat::create([
            'date' => CarbonImmutable::today()->toDateString(),
            'publisher_id' => $publisher->id,
            'app_id' => $app->id,
            'country' => $country,
            'clicks' => 100,
            'conversions' => 1,
            'revenue' => $payout * 2,
            'payout' => $payout,
        ]);
    }
}

it('sums every country when nothing is picked', function () {
    $publisher = Publisher::factory()->create();
    seedCountryStats($publisher);

    $this->actingAs($publisher->user);

    $page = new Statistics;
    $page->range = 'today';

    expect((float) $page->totals['payout'])->toBe(70.0);
});

it('sums only the picked countries', function () {
    $publisher = Publisher::factory()->create();
    seedCountryStats($publisher);

    $this->actingAs($publisher->user);

    $page = new Statistics;
    $page->range = 'today';
    $page->countries = ['US', 'EG'];

    // 10 + 20, with DE's 40 left out — a whereIn, not a where on the first value.
    expect((float) $page->totals['payout'])->toBe(30.0);
});

// The names come from ext-intl, which composer does not require — the app
// falls back to bare codes without it, but this test does not. If CI ever goes
// red here, check the extension before the code.
it('offers the publisher their own countries, named', function () {
    $publisher = Publisher::factory()->create();
    $stranger = Publisher::factory()->create();

    seedCountryStats($publisher);

    $strangerApp = App::factory()->create(['publisher_id' => $stranger->id]);

    DailyStat::create([
        'date' => CarbonImmutable::today()->toDateString(),
        'publisher_id' => $stranger->id,
        'app_id' => $strangerApp->id,
        'country' => 'BR',
    ]);

    $this->actingAs($publisher->user);

    $options = (new Statistics)->countryOptions;

    // Each option is a flag beside the name; the key order is what matters.
    expect(array_keys($options))->toBe(['DE', 'EG', 'US'])
        ->and($options['DE'])->toContain('flags/de.svg', 'Germany')
        ->and($options['EG'])->toContain('flags/eg.svg', 'Egypt')
        ->and($options['US'])->toContain('flags/us.svg', 'United States');
});

it('drives the filter through the live component', function () {
    $publisher = Publisher::factory()->create();
    seedCountryStats($publisher);

    $this->actingAs($publisher->user);

    Livewire::test(Statistics::class)
        ->set('range', 'today')
        ->set('countries', ['DE'])
        ->assertSee('40.00')
        // The schema itself renders — a getSchema() that resolved to null would
        // print nothing and still pass every assertion above.
        ->assertSee('All countries')
        ->assertSee('United States');
});
