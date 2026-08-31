<?php

use App\Filament\Admin\Resources\Apps\Pages\EditApp as AdminEditApp;
use App\Filament\Publisher\Resources\Apps\Pages\EditApp;
use App\Models\App;
use App\Models\Network;
use App\Models\Publisher;
use App\Models\User;
use App\Support\AppNetworks;
use Database\Factories\BlacklistFactory;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;

it('does not name suppliers on the publisher app form', function () {
    Filament::setCurrentPanel('publisher');

    $keep = Network::factory()->create(['name' => 'Keep Co']);
    $drop = Network::factory()->create(['name' => 'Drop Co']);
    $publisher = Publisher::factory()->create();
    $app = App::factory()->create(['publisher_id' => $publisher->id]);

    $this->actingAs($publisher->user);

    $html = $this->get('/dashboard/apps/'.$app->getRouteKey().'/edit')
        ->assertSuccessful()
        ->getContent();

    expect($html)
        ->not->toContain('Keep Co')
        ->not->toContain('Drop Co')
        ->not->toContain('enabled_networks')
        ->not->toContain('This app only. Off hides');
});

it('does not let a publisher rewrite network blocks by saving the app', function () {
    Filament::setCurrentPanel('publisher');

    $keep = Network::factory()->create(['name' => 'Keep Co']);
    $drop = Network::factory()->create(['name' => 'Drop Co']);
    $publisher = Publisher::factory()->create();
    $app = App::factory()->create(['publisher_id' => $publisher->id]);

    AppNetworks::sync($app, [(string) $keep->id]);

    $this->actingAs($publisher->user);

    Livewire::test(EditApp::class, ['record' => $app->getRouteKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(AppNetworks::blockedIds($app->fresh()))->toBe([$drop->id]);
});

it('lets an admin turn a network off on the same form', function () {
    Config::set('advault.require_admin_2fa', false);
    Filament::setCurrentPanel('admin');

    $keep = Network::factory()->create(['name' => 'Keep Co']);
    $drop = Network::factory()->create(['name' => 'Drop Co']);
    $app = App::factory()->create();

    $this->actingAs(User::factory()->admin()->create([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_recovery_codes' => ['aaaa-bbbb'],
    ]));

    Livewire::test(AdminEditApp::class, ['record' => $app->getRouteKey()])
        ->fillForm([
            'enabled_networks' => [(string) $keep->id],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(AppNetworks::blockedIds($app->fresh()))->toBe([$drop->id]);
});

it('does not lift a publisher-wide network block from an admin save that includes it', function () {
    $blocked = Network::factory()->create(['name' => 'Blocked Co']);
    $other = Network::factory()->create(['name' => 'Other Co']);
    $publisher = Publisher::factory()->create();
    $app = App::factory()->create(['publisher_id' => $publisher->id]);

    BlacklistFactory::new()->network((string) $blocked->id)->create([
        'publisher_id' => $publisher->id,
        'app_id' => null,
    ]);

    AppNetworks::sync($app, [(string) $blocked->id, (string) $other->id]);

    expect(AppNetworks::blockedIds($app->fresh()))->toContain($blocked->id)
        ->and(AppNetworks::publisherWideBlockedIds($publisher->id))->toBe([$blocked->id]);
});

it('turns a network back on by deleting the app-scoped row', function () {
    $network = Network::factory()->create();
    $app = App::factory()->create();

    AppNetworks::sync($app, []);
    expect(AppNetworks::blockedIds($app))->toContain($network->id);

    AppNetworks::sync($app, AppNetworks::enabledIds(null));
    expect(AppNetworks::blockedIds($app->fresh()))->toBe([]);
});
