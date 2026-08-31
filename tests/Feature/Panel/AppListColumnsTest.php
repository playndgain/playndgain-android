<?php

use App\Enums\AppPlatform;
use App\Enums\AppStatus;
use App\Models\App;
use App\Models\Publisher;
use App\Models\User;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Config;

it('renders an app as an identity cell, platform mark, and status pill', function () {
    Config::set('advault.require_admin_2fa', false);

    $publisher = Publisher::factory()->create(['company_name' => 'Iberia Media']);
    $publisher->user->update([
        'name' => 'Ana Costa',
        'email' => 'ana@iberia.test',
    ]);

    $app = App::factory()->create([
        'publisher_id' => $publisher->id,
        'name' => 'Rewards Box',
        'url' => 'https://rewards.box/wall',
        'platform' => AppPlatform::Android,
        'status' => AppStatus::Pending,
        'postback_url' => 'https://rewards.box/cb',
    ]);

    $this->actingAs(User::factory()->admin()->create([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_recovery_codes' => ['aaaa-bbbb'],
    ]));

    $this->get('/admin/apps')
        ->assertSuccessful()
        ->assertSee('Rewards Box')
        ->assertSee('#'.$app->app_id)
        ->assertSee('rewards.box')
        ->assertSee('Ana Costa')
        ->assertSee('ana@iberia.test')
        ->assertSee('Iberia Media')
        ->assertSee('img/devices/android.svg', escape: false)
        ->assertSee('ad-status--pending', escape: false)
        ->assertSee('Pending')
        ->assertSee('ad-postback__dot', escape: false)
        ->assertSee('Live')
        ->assertSee('Clicks 7d');
});

it('lists the same app chrome on the publisher apps table', function () {
    $publisher = Publisher::factory()->create();
    App::factory()->create([
        'publisher_id' => $publisher->id,
        'name' => 'Coin Locker',
        'platform' => AppPlatform::Ios,
        'status' => AppStatus::Paused,
        'postback_url' => null,
    ]);

    $this->actingAs($publisher->user);

    $this->get('/dashboard/apps')
        ->assertSuccessful()
        ->assertSee('Coin Locker')
        ->assertSee('img/devices/ios.svg', escape: false)
        ->assertSee('ad-status--paused', escape: false)
        ->assertSee('Paused')
        ->assertSee('None');
});

it('labels each app status for the admin table', function (AppStatus $status, string $label, string $color, Heroicon $icon) {
    expect($status->getLabel())->toBe($label)
        ->and($status->getColor())->toBe($color)
        ->and($status->getIcon())->toBe($icon);
})->with([
    [AppStatus::Pending, 'Pending', 'warning', Heroicon::OutlinedClock],
    [AppStatus::Active, 'Active', 'info', Heroicon::OutlinedCheckBadge],
    [AppStatus::Paused, 'Paused', 'gray', Heroicon::OutlinedPauseCircle],
    [AppStatus::Rejected, 'Rejected', 'gray', Heroicon::OutlinedNoSymbol],
]);
