<?php

use App\Enums\AppStatus;
use App\Enums\NetworkStatus;
use App\Enums\OfferStatus;
use App\Enums\PublisherStatus;
use App\Filament\Admin\Resources\Offers\Pages\ListOffers;
use App\Filament\Publisher\Pages\Offers;
use App\Filament\Support\ViewOfferEventsAction;
use App\Models\App;
use App\Models\Network;
use App\Models\Offer;
use App\Models\Publisher;
use App\Models\User;
use App\Support\OfferEvents;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;

/**
 * The events slide-over on both Offers catalogues.
 *
 * The wall already itemises `offers.goals`. These pages did not. The publisher
 * assertion is the commercial one: they see their share of each event and
 * never the network payout.
 */
function eventsOffer(array $attributes = []): Offer
{
    $network = Network::factory()->create(['status' => NetworkStatus::Active]);

    return Offer::factory()->create([
        'network_id' => $network->id,
        'status' => OfferStatus::Active,
        'payout' => '4.000000',
        'description' => 'A two-week village builder.',
        'instructions' => 'Install the game<br>Reach village 5',
        'is_multi_reward' => true,
        'goals' => [
            ['name' => 'Reach village 5', 'payout' => 2.25],
            ['name' => 'Reach village 15', 'payout' => 1.75],
        ],
        ...$attributes,
    ]);
}

it('reads stored events and invents one when the offer pays once', function () {
    $multi = eventsOffer();
    $single = eventsOffer([
        'is_multi_reward' => false,
        'goals' => null,
        'payout' => '0.500000',
        'name' => 'One-shot survey',
    ]);

    expect(OfferEvents::raw($multi))->toHaveCount(2)
        ->and(OfferEvents::of($multi)[0]['name'])->toBe('Reach village 5')
        ->and(OfferEvents::raw($single))->toBe([])
        ->and(OfferEvents::of($single))->toBe([
            ['name' => 'Complete the offer', 'payout' => 0.5],
        ]);
});

it('lists each event and the network payout for an admin', function () {
    Config::set('playndgain.require_admin_2fa', false);
    Filament::setCurrentPanel('admin');

    $offer = eventsOffer();

    $this->actingAs(User::factory()->admin()->create([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_recovery_codes' => ['aaaa-bbbb'],
    ]));

    Livewire::test(ListOffers::class)
        ->assertTableActionVisible('events', $offer)
        ->mountTableAction('events', $offer)
        ->assertActionMounted();

    $html = view('filament.offers.events', ViewOfferEventsAction::viewData($offer))->render();

    expect($html)
        ->toContain('Reach village 5')
        ->toContain('Reach village 15')
        ->toContain('+$2.2500')
        ->toContain('+$1.7500')
        ->toContain('+1,575 Coins')
        ->toContain('+1,225 Coins')
        ->toContain('A two-week village builder.')
        ->toContain('Install the game')
        ->toContain('Each event pays on its own')
        ->toContain('1,000 Coins / $1')
        ->not->toContain('Amounts shown are what you earn');
});

it('lists each event at the publisher share and hides the network payout', function () {
    Filament::setCurrentPanel('publisher');

    $publisher = Publisher::factory()->create([
        'status' => PublisherStatus::Approved,
        'rev_share' => 70.00,
    ]);
    $app = App::factory()->create([
        'publisher_id' => $publisher->id,
        'status' => AppStatus::Active,
    ]);
    $offer = eventsOffer();

    $this->actingAs($publisher->user);

    Livewire::test(Offers::class, ['appId' => $app->id])
        ->assertTableActionVisible('events', $offer)
        ->mountTableAction('events', $offer)
        ->assertActionMounted();

    $html = view('filament.offers.events', ViewOfferEventsAction::viewData($offer, publisherFacing: true, app: $app))->render();

    expect($html)
        ->toContain('Reach village 5')
        ->toContain('Reach village 15')
        ->toContain('+$1.5750')
        ->toContain('+$1.2250')
        ->toContain('+1,575 Coins')
        ->toContain('+1,225 Coins')
        ->toContain('Amounts shown are what you earn')
        ->toContain('User reward uses this app currency')
        ->not->toContain('+$2.2500')
        ->not->toContain('+$1.7500');
});

it('still prices events from the selected app when the session has no publisher', function () {
    Filament::setCurrentPanel('publisher');

    $publisher = Publisher::factory()->create([
        'status' => PublisherStatus::Approved,
        'rev_share' => 70.00,
    ]);
    $app = App::factory()->create([
        'publisher_id' => $publisher->id,
        'status' => AppStatus::Active,
    ]);
    $offer = eventsOffer();

    $this->actingAs(User::factory()->admin()->create());

    $html = view('filament.offers.events', ViewOfferEventsAction::viewData($offer, publisherFacing: true, app: $app))->render();

    expect($html)
        ->toContain('+$1.5750')
        ->toContain('+$1.2250')
        ->toContain('+1,575 Coins')
        ->not->toContain('+—')
        ->not->toContain('your payout');
});

it('does not invent a user reward when the publisher has no app in scope', function () {
    Filament::setCurrentPanel('publisher');

    $publisher = Publisher::factory()->create([
        'status' => PublisherStatus::Approved,
        'rev_share' => 70.00,
    ]);
    $offer = eventsOffer();

    $this->actingAs($publisher->user);

    $html = view('filament.offers.events', ViewOfferEventsAction::viewData($offer, publisherFacing: true))->render();

    expect($html)
        ->toContain('+$1.5750')
        ->not->toContain('Coins')
        ->not->toContain('User reward uses this app currency');
});

it('still opens for a single-step offer', function () {
    Config::set('playndgain.require_admin_2fa', false);
    Filament::setCurrentPanel('admin');

    $offer = eventsOffer([
        'name' => 'One-shot survey',
        'is_multi_reward' => false,
        'goals' => null,
        'payout' => '0.800000',
        'instructions' => 'Finish the survey',
    ]);

    $this->actingAs(User::factory()->admin()->create([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_recovery_codes' => ['aaaa-bbbb'],
    ]));

    Livewire::test(ListOffers::class)
        ->assertTableActionVisible('events', $offer)
        ->mountTableAction('events', $offer)
        ->assertActionMounted();

    $html = view('filament.offers.events', ViewOfferEventsAction::viewData($offer))->render();

    expect($html)
        ->toContain('Complete the offer')
        ->toContain('+$0.8000')
        ->toContain('+560 Coins')
        ->toContain('This offer pays once')
        ->toContain('Finish the survey');
});
