<?php

use App\Models\App;
use App\Models\Network;
use App\Models\Publisher;
use App\Models\User;
use Illuminate\Support\Facades\Config;

/**
 * ListRecords wires the view/edit URL onto the row. We turn that off globally
 * so the action buttons are the only way in — a clickable row fights copy
 * and selection on identity cells.
 */
function rowClickAdmin(): User
{
    Config::set('playndgain.require_admin_2fa', false);

    return User::factory()->admin()->create([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_recovery_codes' => ['aaaa-bbbb'],
    ]);
}

it('does not navigate when a resource row is clicked', function (string $path) {
    $publisher = Publisher::factory()->create();
    Network::factory()->create();
    App::factory()->create(['publisher_id' => $publisher->id]);

    $this->actingAs(rowClickAdmin());

    $this->get($path)
        ->assertSuccessful()
        ->assertDontSee('fi-clickable', escape: false);
})->with([
    '/admin/publishers',
    '/admin/networks',
    '/admin/apps',
]);

it('does not navigate when a publisher-panel row is clicked', function () {
    $publisher = Publisher::factory()->create();
    App::factory()->create(['publisher_id' => $publisher->id]);

    $this->actingAs($publisher->user);

    $this->get('/dashboard/apps')
        ->assertSuccessful()
        ->assertDontSee('fi-clickable', escape: false);
});
