<?php

use App\Enums\OutgoingPostbackKind;
use App\Enums\OutgoingPostbackStatus;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\Conversions\Pages\ViewConversion;
use App\Filament\Admin\Resources\OfferClicks\Pages\ListOfferClicks as AdminListOfferClicks;
use App\Filament\Publisher\Resources\OfferClicks\Pages\ListOfferClicks as PublisherListOfferClicks;
use App\Filament\Publisher\Resources\PostbackReports\Pages\ListPostbackReports;
use App\Filament\Publisher\Resources\Transactions\Pages\ListTransactions;
use App\Models\App;
use App\Models\Conversion;
use App\Models\OutgoingPostback;
use App\Models\Publisher;
use App\Models\User;
use Database\Factories\OfferClickFactory;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;

function actingAsActivityAdmin(): void
{
    Config::set('playndgain.require_admin_2fa', false);
    Filament::setCurrentPanel('admin');
    test()->actingAs(User::factory()->create(['role' => UserRole::Admin]));
}

function assertMountedViewIsFullGrid(object $component, object $record): void
{
    $action = TestAction::make('view')->table($record);

    $component
        ->mountAction($action)
        ->assertSuccessful()
        ->assertActionMounted($action);

    expect($component->instance()->getSchema($component->instance()->getMountedActionSchemaName())->getColumns('lg'))
        ->toBe(1);
}

it('stacks the conversion view on one column', function () {
    actingAsActivityAdmin();

    $conversion = Conversion::factory()->create();

    $component = Livewire::test(ViewConversion::class, ['record' => $conversion->getKey()])
        ->assertSuccessful();

    expect($component->instance()->getSchema('infolist')->getColumns('lg'))->toBe(1);
});

it('opens publisher activity views with each section on the full grid', function () {
    Filament::setCurrentPanel('publisher');

    $publisher = Publisher::factory()->create();
    $app = App::factory()->create(['publisher_id' => $publisher->id]);
    $click = OfferClickFactory::new()->for($app)->create();
    $conversion = Conversion::factory()->create([
        'app_id' => $app->id,
        'publisher_id' => $publisher->id,
        'offer_click_id' => $click->id,
    ]);
    $postback = OutgoingPostback::create([
        'conversion_id' => $conversion->id,
        'app_id' => $app->id,
        'kind' => OutgoingPostbackKind::Credit,
        'url' => 'https://publisher.example/cb?sig=REDACTED',
        'method' => 'GET',
        'attempt' => 1,
        'status' => OutgoingPostbackStatus::Success,
        'http_status' => 200,
        'created_at' => now(),
    ]);

    test()->actingAs($publisher->user);

    assertMountedViewIsFullGrid(Livewire::test(ListTransactions::class), $conversion);
    assertMountedViewIsFullGrid(Livewire::test(PublisherListOfferClicks::class), $click);
    assertMountedViewIsFullGrid(Livewire::test(ListPostbackReports::class), $postback);
});

it('opens an admin offerwall click on one column', function () {
    actingAsActivityAdmin();

    $click = OfferClickFactory::new()->create();

    assertMountedViewIsFullGrid(Livewire::test(AdminListOfferClicks::class), $click);
});
