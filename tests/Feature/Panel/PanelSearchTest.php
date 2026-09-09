<?php

use App\Filament\Admin\Resources\Conversions\Pages\ListConversions;
use App\Filament\Publisher\Resources\Apps\Pages\ListApps as PublisherListApps;
use App\Filament\Publisher\Resources\Transactions\Pages\ListTransactions;
use App\Models\App;
use App\Models\Conversion;
use App\Models\Publisher;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Livewire\GlobalSearch;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;

it('finds a conversion by app name on the admin table', function () {
    Config::set('playndgain.require_admin_2fa', false);
    Filament::setCurrentPanel('admin');

    $this->actingAs(User::factory()->admin()->create([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_recovery_codes' => ['aaaa-bbbb'],
    ]));

    $kept = Conversion::factory()->create([
        'app_id' => App::factory()->create(['name' => 'Kept Wall']),
        'offer_name' => 'Unrelated offer',
    ]);
    $hidden = Conversion::factory()->create([
        'app_id' => App::factory()->create(['name' => 'Other Placement']),
        'offer_name' => 'Unrelated offer',
    ]);

    Livewire::test(ListConversions::class)
        ->searchTable('Kept Wall')
        ->assertCanSeeTableRecords([$kept])
        ->assertCanNotSeeTableRecords([$hidden]);
});

it('finds a conversion by app name on the publisher table', function () {
    Filament::setCurrentPanel('publisher');

    $publisher = Publisher::factory()->create();
    $keptApp = App::factory()->create([
        'publisher_id' => $publisher->id,
        'name' => 'Kept Wall',
    ]);
    $hiddenApp = App::factory()->create([
        'publisher_id' => $publisher->id,
        'name' => 'Other Placement',
    ]);
    $kept = Conversion::factory()->create([
        'app_id' => $keptApp->id,
        'publisher_id' => $publisher->id,
        'offer_name' => 'Unrelated offer',
    ]);
    $hidden = Conversion::factory()->create([
        'app_id' => $hiddenApp->id,
        'publisher_id' => $publisher->id,
        'offer_name' => 'Unrelated offer',
    ]);

    $this->actingAs($publisher->user);

    Livewire::test(ListTransactions::class)
        ->searchTable('Kept Wall')
        ->assertCanSeeTableRecords([$kept])
        ->assertCanNotSeeTableRecords([$hidden]);
});

it('finds an app by public id in publisher global search', function () {
    Filament::setCurrentPanel('publisher');

    $publisher = Publisher::factory()->create();
    $app = App::factory()->create([
        'publisher_id' => $publisher->id,
        'name' => 'Demo Wall',
        'app_id' => '18881',
    ]);

    $this->actingAs($publisher->user);

    Livewire::test(GlobalSearch::class)
        ->set('search', '18881')
        ->assertSee('Demo Wall')
        ->assertDontSee('No results');
});

it('finds an app by public id on the publisher apps table', function () {
    Filament::setCurrentPanel('publisher');

    $publisher = Publisher::factory()->create();
    $kept = App::factory()->create([
        'publisher_id' => $publisher->id,
        'name' => 'Kept Wall',
        'app_id' => '18882',
    ]);
    $hidden = App::factory()->create([
        'publisher_id' => $publisher->id,
        'name' => 'Other Placement',
        'app_id' => '19991',
    ]);

    $this->actingAs($publisher->user);

    Livewire::test(PublisherListApps::class)
        ->searchTable('18882')
        ->assertCanSeeTableRecords([$kept])
        ->assertCanNotSeeTableRecords([$hidden]);
});
