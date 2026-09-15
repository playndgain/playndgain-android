<?php

use App\Enums\BlacklistType;
use App\Enums\NetworkStatus;
use App\Enums\OfferStatus;
use App\Filament\Publisher\Resources\Blacklists\Pages\ListBlacklists;
use App\Models\App;
use App\Models\Blacklist;
use App\Models\Network;
use App\Models\Offer;
use App\Models\Publisher;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * The blacklist create form puts four different fields on one `value` path,
 * switched by the selected type. That is a Filament shape worth exercising: a
 * visibility rule that leaks would either save the wrong field or refuse to
 * save at all, and neither shows up in static analysis.
 */
beforeEach(function () {
    // A Livewire component tested outside a request has no panel, and every
    // Filament URL helper it touches then resolves against no routes.
    Filament::setCurrentPanel('publisher');

    $this->publisher = Publisher::factory()->create(['rev_share' => 70.00]);
    $this->app_ = App::factory()->create(['publisher_id' => $this->publisher->id]);

    $this->actingAs($this->publisher->user);
});

/** The create form as the publisher's browser receives it, for a given state. */
function blacklistFormHtml(array $data): string
{
    $component = Livewire::test(ListBlacklists::class)
        ->mountTableAction('create')
        ->setTableActionData($data);

    $page = $component->instance();

    return $page->{$page->getMountedActionSchemaName()}->toHtml();
}

it('saves a country block through the create action', function () {
    Livewire::test(ListBlacklists::class)
        ->callTableAction('create', data: [
            'app_id' => $this->app_->id,
            'type' => BlacklistType::Country->value,
            'value' => 'DE',
        ])
        ->assertHasNoTableActionErrors();

    $entry = Blacklist::query()->firstOrFail();

    expect($entry->type)->toBe(BlacklistType::Country)
        ->and($entry->value)->toBe('DE')
        ->and($entry->publisher_id)->toBe($this->publisher->id);
});

it('saves a device block through the same path', function () {
    Livewire::test(ListBlacklists::class)
        ->callTableAction('create', data: [
            'app_id' => null,
            'type' => BlacklistType::Device->value,
            'value' => 'ios',
        ])
        ->assertHasNoTableActionErrors();

    $entry = Blacklist::query()->firstOrFail();

    expect($entry->type)->toBe(BlacklistType::Device)
        ->and($entry->value)->toBe('ios')
        ->and($entry->app_id)->toBeNull();
});

/**
 * The same margin rule the Offers page is held to — docs/08 §5 and §16.
 *
 * This picker lists the offer catalogue with a price beside every name, which
 * makes it the second place in the panel that could publish `offers.payout`.
 * A publisher who reads the gross here and the net on the Offers page has our
 * rev-share, and therefore our margin, from two screens.
 *
 * Asserted against the form's own markup rather than the component's, because
 * an action modal is rendered by the panel layout and never appears in what
 * Livewire::test returns — an assertDontSee there would pass against a form
 * that shouted the figure.
 */
it('never shows the network payout in the offer picker', function () {
    $network = Network::factory()->create(['status' => NetworkStatus::Active]);
    $offer = Offer::factory()->create([
        'network_id' => $network->id,
        'status' => OfferStatus::Active,
        'payout' => '3.500000',
    ]);

    $html = blacklistFormHtml([
        'app_id' => $this->app_->id,
        'type' => BlacklistType::Offer->value,
    ]);

    // 70% of $3.50, and never the $3.50 itself.
    expect($html)->toContain($offer->name)
        ->and($html)->toContain('$2.45')
        ->and($html)->not->toContain('3.50');
});

it('hides existing network blocks and does not name the supplier', function () {
    $network = Network::factory()->create([
        'status' => NetworkStatus::Active,
        'name' => 'Secret Supply Co',
    ]);

    Blacklist::create([
        'publisher_id' => $this->publisher->id,
        'app_id' => $this->app_->id,
        'type' => BlacklistType::Network,
        'value' => (string) $network->id,
    ]);

    $this->get('/dashboard/blacklists')
        ->assertSuccessful()
        ->assertDontSee('Secret Supply Co')
        ->assertDontSee('>Networks<', false);
});

it('does not offer a network to block or name a supplier', function () {
    $active = Network::factory()->create([
        'status' => NetworkStatus::Active,
        'name' => 'Secret Supply Co',
    ]);

    $html = blacklistFormHtml([
        'app_id' => $this->app_->id,
        'type' => BlacklistType::Country->value,
    ]);

    expect($html)
        ->not->toContain('Secret Supply Co')
        ->not->toContain(BlacklistType::Network->value);

    Livewire::test(ListBlacklists::class)
        ->callTableAction('create', data: [
            'app_id' => $this->app_->id,
            'type' => BlacklistType::Network->value,
            'value' => (string) $active->id,
        ])
        ->assertHasTableActionErrors(['type']);
});

it('requires a value', function () {
    Livewire::test(ListBlacklists::class)
        ->callTableAction('create', data: [
            'app_id' => $this->app_->id,
            'type' => BlacklistType::Country->value,
            'value' => null,
        ])
        ->assertHasTableActionErrors(['value']);

    expect(Blacklist::query()->count())->toBe(0);
});
