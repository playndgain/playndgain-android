<?php

use App\Enums\AppPlatform;
use App\Filament\Publisher\Resources\Apps\AppResource;
use App\Filament\Publisher\Resources\Apps\Pages\CreateApp;
use App\Filament\Support\ImpersonateAction;
use App\Http\Controllers\ImpersonationController;
use App\Models\App;
use App\Models\Conversion;
use App\Models\OfferClick;
use App\Models\Publisher;
use App\Models\User;
use BezhanSalleh\PanelSwitch\PanelSwitch;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * An admin can reach both panels, and the publisher panel stays single-tenant
 * whoever is looking at it.
 *
 * The scope keys on the panel rather than the role for a specific reason: 19 of
 * the publisher panel's files scope through CurrentPublisher (which answers 0
 * for a viewer with no publisher, meaning no rows) and the rest lean on this
 * global scope. Keying on role alone made those two disagree — an admin saw
 * every publisher's clicks and transactions in some tables and nothing in
 * others, inside a UI whose every total is captioned "yours".
 */
it('lets an admin into both panels and a publisher into only their own', function () {
    $admin = User::factory()->admin()->create();
    $publisher = Publisher::factory()->create();

    expect($admin->canAccessPanel(Filament::getPanel('admin')))->toBeTrue()
        ->and($admin->canAccessPanel(Filament::getPanel('publisher')))->toBeTrue()
        ->and($publisher->user->canAccessPanel(Filament::getPanel('publisher')))->toBeTrue()
        ->and($publisher->user->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();
});

it('does not let an admin create an app in the publisher panel', function () {
    $this->actingAs(User::factory()->admin()->create());

    Filament::setCurrentPanel('publisher');

    expect(AppResource::canCreate())->toBeFalse();

    Livewire::test(CreateApp::class)
        ->assertForbidden();

    expect(App::query()->count())->toBe(0);
});

it('creates an app against the signed-in publisher, not publisher_id 0', function () {
    $publisher = Publisher::factory()->create();

    $this->actingAs($publisher->user);
    Filament::setCurrentPanel('publisher');

    Livewire::test(CreateApp::class)
        ->fillForm([
            'name' => 'Rewards Hub',
            'platform' => AppPlatform::Web,
            'currency_name' => 'Coins',
            'currency_rate' => 1000,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $app = App::query()->sole();

    expect($app->publisher_id)->toBe($publisher->id)
        ->and($app->name)->toBe('Rewards Hub');
});

it('shows an admin no publisher rows inside the publisher panel', function () {
    $a = Publisher::factory()->create();
    $b = Publisher::factory()->create();
    App::factory()->count(2)->create(['publisher_id' => $a->id]);
    App::factory()->count(3)->create(['publisher_id' => $b->id]);

    $this->actingAs(User::factory()->admin()->create());

    Filament::setCurrentPanel('publisher');
    expect(App::query()->count())->toBe(0);

    // The admin panel is where platform-wide data belongs, and it still is.
    Filament::setCurrentPanel('admin');
    expect(App::query()->count())->toBe(5);
});

it('keeps scoping a publisher to their own rows in their panel', function () {
    $a = Publisher::factory()->create();
    $b = Publisher::factory()->create();
    App::factory()->count(2)->create(['publisher_id' => $a->id]);
    App::factory()->count(3)->create(['publisher_id' => $b->id]);

    $this->actingAs($a->user);
    Filament::setCurrentPanel('publisher');

    expect(App::query()->count())->toBe(2);
});

it('does not scope the money path, which has no panel at all', function () {
    $publisher = Publisher::factory()->create();
    $app = App::factory()->create(['publisher_id' => $publisher->id]);
    Conversion::factory()->create(['app_id' => $app->id, 'publisher_id' => $publisher->id]);

    // Queue workers and postback handlers run with no session and no panel.
    expect(Conversion::query()->count())->toBe(1)
        ->and(OfferClick::query()->count())->toBe(0);
});

it('offers the panel switcher to admins and to nobody else', function () {
    // The switch lists the panels the user can access and renders only when
    // there are two or more — an admin has both, a publisher has one.
    $this->actingAs(User::factory()->admin()->create());
    expect(PanelSwitch::make()->isVisible())->toBeTrue()
        ->and(array_keys(PanelSwitch::make()->getPanels()))->toBe(['admin', 'publisher']);

    $this->actingAs(Publisher::factory()->create()->user);
    expect(PanelSwitch::make()->isVisible())->toBeFalse();
});

it('hides impersonation from inside an impersonated session', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $action = ImpersonateAction::make();

    expect($action->isVisible())->toBeTrue();

    // Nesting would overwrite the one session key holding the admin's own id
    // and leave them with no route back to their account.
    session([ImpersonationController::SESSION_KEY => $admin->id]);

    expect($action->isVisible())->toBeFalse();
});
