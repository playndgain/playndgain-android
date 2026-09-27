<?php

use App\Enums\IdentityKind;
use App\Filament\Admin\Audience\MauMetric;
use App\Filament\Admin\Audience\RangeSummary;
use App\Filament\Admin\Audience\StickinessMetric;
use App\Filament\Admin\Audience\TopCountries;
use App\Filament\Admin\Pages\Audience;
use App\Models\App;
use App\Models\IdentitySession;
use App\Models\Network;
use App\Models\Publisher;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;

function audienceAdmin(): User
{
    Config::set('playndgain.require_admin_2fa', false);
    Filament::setCurrentPanel('admin');

    $admin = User::factory()->admin()->create([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_recovery_codes' => ['aaaa-bbbb'],
    ]);
    test()->actingAs($admin);

    return $admin;
}

function audienceVisit(App $app, string $user, string $country, string $ip = '203.0.113.77', ?string $at = null): void
{
    IdentitySession::query()->create([
        'kind' => IdentityKind::Wall,
        'publisher_id' => $app->publisher_id,
        'app_id' => $app->id,
        'end_user_id' => $user,
        'country' => $country,
        'ip' => $ip,
        'ua_hash' => str_repeat('c', 64),
        'ds_hash' => str_repeat('d', 64),
        'created_at' => $at ?? now()->toDateTimeString(),
    ]);
}

beforeEach(function () {
    Carbon::setTestNow('2026-09-09 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('renders the audience page for an admin and forbids a publisher', function () {
    audienceAdmin();

    Livewire::test(Audience::class)->assertSuccessful();
    $this->get('/admin/audience')
        ->assertSuccessful()
        ->assertSee('Audience')
        ->assertSee('Last updated 9 Sep 2026, 12:00 UTC');

    $this->actingAs(Publisher::factory()->create()->user);
    $this->get('/admin/audience')->assertForbidden();
});

it('renders a useful page on an empty ledger', function () {
    audienceAdmin();

    $this->get('/admin/audience')
        ->assertSuccessful()
        ->assertSee('Daily unique users')
        ->assertSee('No known-country visitors in this range')
        ->assertSee('No wall activity in the last 30 days')
        ->assertDontSee('0.0%');
});

it('scopes the widgets to the publisher and app filters', function () {
    audienceAdmin();
    $publisher = Publisher::factory()->create();
    $mine = App::factory()->create(['publisher_id' => $publisher->id]);
    $mySecond = App::factory()->create(['publisher_id' => $publisher->id]);
    $theirs = App::factory()->create();

    audienceVisit($mine, 'A', 'DE');
    audienceVisit($mySecond, 'B', 'DE');
    audienceVisit($theirs, 'C', 'BR');
    audienceVisit($theirs, 'D', 'BR');
    audienceVisit($theirs, 'E', 'BR');

    Livewire::test(MauMetric::class, ['pageFilters' => []])->assertSee('>5<', false);
    Livewire::test(MauMetric::class, ['pageFilters' => ['publisher_id' => $publisher->id]])->assertSee('>2<', false);
    Livewire::test(MauMetric::class, ['pageFilters' => ['app_id' => $mine->id]])->assertSee('>1<', false);

    Livewire::test(TopCountries::class, ['pageFilters' => ['publisher_id' => $publisher->id]])
        ->assertSee('Germany')
        ->assertDontSee('Brazil');
    Livewire::test(TopCountries::class, ['pageFilters' => []])
        ->assertSee('Brazil')
        ->assertSee('60%');
    Livewire::test(StickinessMetric::class, ['pageFilters' => ['app_id' => $mine->id]])
        ->assertSee('100.0%');
});

it('shows the unknown country count beside the known ones', function () {
    audienceAdmin();
    $app = App::factory()->create();

    audienceVisit($app, 'KNOWN', 'FR');
    IdentitySession::query()->create([
        'kind' => IdentityKind::Wall,
        'publisher_id' => $app->publisher_id,
        'app_id' => $app->id,
        'end_user_id' => 'LEGACY',
        'ip' => '203.0.113.78',
        'ua_hash' => str_repeat('c', 64),
        'created_at' => now()->subDay()->toDateTimeString(),
    ]);

    Livewire::test(RangeSummary::class, ['pageFilters' => []])
        ->assertSeeInOrder(['Unique users', '2', 'With a known country', '1', 'Unknown country', '1']);
    Livewire::test(TopCountries::class, ['pageFilters' => []])
        ->assertSee('100%');
});

it('renders no network identity, IP, end user id or device hash', function () {
    audienceAdmin();
    $network = Network::factory()->create(['name' => 'Zephyr Upstream Media', 'driver' => 'zephyr-upstream']);
    $app = App::factory()->create();

    audienceVisit($app, 'SECRET_END_USER_42', 'US', '198.51.100.201');

    $html = $this->get('/admin/audience')->assertSuccessful()->getContent();

    expect($html)
        ->not->toContain($network->name)
        ->not->toContain($network->driver)
        ->not->toContain('198.51.100.201')
        ->not->toContain('SECRET_END_USER_42')
        ->not->toContain(str_repeat('d', 64));
});
