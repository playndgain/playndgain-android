<?php

use App\Enums\NetworkIntegration;
use App\Enums\NetworkStatus;
use App\Filament\Admin\Resources\Networks\NetworkResource;
use App\Filament\Admin\Resources\Networks\Pages\CreateNetwork;
use App\Filament\Admin\Resources\Networks\Pages\EditNetwork;
use App\Models\AuditLog;
use App\Models\Network;
use App\Models\User;
use App\Networks\Drivers\FakeNetworkDriver;
use App\Networks\NetworkDriverManager;
use App\Providers\NetworkServiceProvider;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Tests\Support\PlatformTestDriver;

beforeEach(function () {
    Config::set('playndgain.require_admin_2fa', false);

    $this->app->register(NetworkServiceProvider::class);
    app(NetworkDriverManager::class)->register(FakeNetworkDriver::key(), FakeNetworkDriver::class);
    app(NetworkDriverManager::class)->register(PlatformTestDriver::key(), PlatformTestDriver::class);

    $this->actingAs(User::factory()->admin()->create([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_recovery_codes' => ['aaaa-bbbb'],
    ]));
});

it('renders the create page with connector cards, reusable first and the direct ones behind a toggle', function () {
    $page = Livewire::test(CreateNetwork::class);

    $page->assertFormFieldExists('driver', function ($field): bool {
        $keys = array_keys($field->getOptions());

        return $keys === array_values(array_filter($keys, fn (string $key): bool => in_array($key, ['swaarm', 'affise', 'platform-test', 'direct'], true)))
            && $keys[0] === 'swaarm'
            && in_array('platform-test', $keys, true)
            && in_array('direct', $keys, true)
            && ! in_array('fake', $keys, true)
            && ! in_array('ogads', $keys, true);
    });

    $page->set('data.show_advanced_connectors', true)
        ->assertFormFieldExists('driver', function ($field): bool {
            $keys = array_keys($field->getOptions());

            return $keys[0] === 'swaarm'
                && in_array('ogads', $keys, true)
                && array_search('direct', $keys, true) < array_search('ogads', $keys, true);
        });
});

it('fills name, website and logo from the connector when a card is picked', function () {
    Livewire::test(CreateNetwork::class)
        ->set('data.driver', 'swaarm')
        ->assertFormSet([
            'name' => 'Swaarm',
            'website' => 'https://www.swaarm.com',
            'logo_path' => 'img/networks/swaarm.svg',
        ])
        ->set('data.show_advanced_connectors', true)
        ->set('data.driver', 'ogads')
        ->assertFormSet([
            'name' => 'OGAds',
            'website' => 'https://ogads.com',
            'logo_path' => 'img/networks/ogads.png',
        ])
        ->set('data.driver', 'direct')
        ->assertFormSet([
            'name' => 'PLAYNDGAIN (house)',
            'website' => null,
            'logo_path' => 'img/networks/house.svg',
        ]);
});

it('saves a new account disabled, with its own postback key', function () {
    Livewire::test(CreateNetwork::class)
        ->fillForm([
            'driver' => 'platform-test',
            'name' => 'A tenant',
            'integration' => NetworkIntegration::Api->value,
            'credentials' => ['api_key' => 'live-key'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $network = Network::query()->where('driver', 'platform-test')->sole();

    expect($network->status)->toBe(NetworkStatus::Disabled)
        ->and($network->postback_key)->toHaveLength(32)
        // A platform account scopes itself by its own key, which is what lets
        // the next tenant share the driver.
        ->and($network->account_scope)->toBe($network->postback_key)
        ->and($network->credentials)->toBe(['api_key' => 'live-key'])
        ->and($network->hosts_wall)->toBeFalse();
});

it('disables a single-account connector that already has a row', function () {
    Network::factory()->create(['driver' => 'ogads', 'name' => 'The first one']);

    Livewire::test(CreateNetwork::class)
        ->set('data.show_advanced_connectors', true)
        ->assertSee('Already added.')
        ->assertFormFieldExists('driver', fn ($field): bool => $field->isOptionDisabled('ogads', 'OGAds'));
});

it('refuses a second account on a connector that holds one', function () {
    Network::factory()->create(['driver' => 'ogads', 'name' => 'The first one']);

    Livewire::test(CreateNetwork::class)
        ->set('data.show_advanced_connectors', true)
        ->fillForm([
            'driver' => 'ogads',
            'name' => 'The second one',
            'integration' => NetworkIntegration::Api->value,
        ])
        ->call('create')
        ->assertHasFormErrors(['driver']);

    expect(Network::query()->where('driver', 'ogads')->count())->toBe(1);
});

it('lets a platform connector take a second account', function () {
    Network::factory()->platform('platform-test')->create(['name' => 'Tenant one']);

    Livewire::test(CreateNetwork::class)
        ->fillForm([
            'driver' => 'platform-test',
            'name' => 'Tenant two',
            'integration' => NetworkIntegration::Api->value,
            'credentials' => ['api_key' => 'another-key'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $keys = Network::query()->where('driver', 'platform-test')->pluck('postback_key');

    expect($keys)->toHaveCount(2)
        ->and($keys->unique())->toHaveCount(2);
});

it('lets a second swaarm tenant be created and generates its postback secret', function () {
    Livewire::test(CreateNetwork::class)
        ->fillForm([
            'driver' => 'swaarm',
            'name' => 'Acme Swaarm',
            'integration' => NetworkIntegration::Api->value,
            'credentials' => [
                'feed_url' => 'https://feed.acme.swaarm-clients.com/feed/v1.2/ads',
                'api_key' => 'acme-key',
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    Livewire::test(CreateNetwork::class)
        ->fillForm([
            'driver' => 'swaarm',
            'name' => 'Globex Swaarm',
            'integration' => NetworkIntegration::Api->value,
            'credentials' => [
                'feed_url' => 'https://feed.globex.swaarm-clients.com/feed/v1.2/ads',
                'api_key' => 'globex-key',
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rows = Network::query()->where('driver', 'swaarm')->orderBy('id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('postback_key')->unique())->toHaveCount(2)
        ->and($rows[0]->credentials['postback_secret'])->toHaveLength(32)
        ->and($rows[1]->credentials['postback_secret'])->toHaveLength(32)
        ->and($rows[0]->credentials['postback_secret'])->not->toBe($rows[1]->credentials['postback_secret'])
        ->and($rows[0]->credentials['api_key'])->toBe('acme-key')
        ->and($rows[1]->credentials['api_key'])->toBe('globex-key');
});

it('lets an affise tenant be created and generates its postback secret', function () {
    Livewire::test(CreateNetwork::class)
        ->fillForm([
            'driver' => 'affise',
            'name' => 'Acme Affise',
            'integration' => NetworkIntegration::Api->value,
            'credentials' => [
                'api_base_url' => 'https://api-acme.affise.com',
                'api_key' => 'affise-key',
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $network = Network::query()->where('driver', 'affise')->sole();

    expect($network->credentials['postback_secret'])->toHaveLength(32)
        ->and($network->credentials['api_key'])->toBe('affise-key');
});

it('mints a swaarm postback secret on the model when none is given', function () {
    $network = Network::factory()->platform('swaarm')->create([
        'credentials' => [
            'feed_url' => 'https://feed.acme.swaarm-clients.com/feed/v1.2/ads',
            'api_key' => 'acme-key',
        ],
    ]);

    expect($network->credentials['postback_secret'])->toHaveLength(32)
        ->and($network->credentials['api_key'])->toBe('acme-key');
});

it('does not invent a postback secret for a single-account driver', function () {
    $network = Network::factory()->create([
        'driver' => 'admantium',
        'credentials' => ['appid' => '1'],
    ]);

    expect($network->credentials)->toBe(['appid' => '1']);
});

it('keeps a postback secret the admin typed', function () {
    Livewire::test(CreateNetwork::class)
        ->fillForm([
            'driver' => 'swaarm',
            'name' => 'Acme Swaarm',
            'integration' => NetworkIntegration::Api->value,
            'credentials' => [
                'feed_url' => 'https://feed.acme.swaarm-clients.com/feed/v1.2/ads',
                'api_key' => 'acme-key',
                'postback_secret' => 'typed-by-hand',
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Network::query()->where('driver', 'swaarm')->sole()->credentials['postback_secret'])
        ->toBe('typed-by-hand');
});

it('keeps an existing direct network saveable even with the advanced toggle off', function () {
    // The driver select is disabled on edit but still dehydrated, so Filament
    // validates the stored value against the option list on every save. A
    // direct driver missing from those options would make 17 rows unsaveable.
    $network = Network::factory()->create(['driver' => 'ogads']);

    Livewire::test(EditNetwork::class, ['record' => $network->getRouteKey()])
        ->fillForm(['name' => 'Renamed'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($network->fresh()->name)->toBe('Renamed');
});

it('shows the postback url for the row with its secret masked, and copies the whole one', function () {
    $network = Network::factory()->platform('platform-test')->create();

    $full = NetworkResource::postbackUrl($network);

    expect($full)->toBe(url('/postback/'.$network->postback_key).'?secret=super-secret&token=super-token&click_id={click_id}');

    Livewire::test(EditNetwork::class, ['record' => $network->getRouteKey()])
        ->assertFormSet([
            // Every credential-shaped name is masked, not just `secret`; the
            // macro the partner substitutes is left whole.
            'postback_url_display' => url('/postback/'.$network->postback_key).'?secret=REDACTED&token=REDACTED&click_id={click_id}',
        ]);
});

it('writes an audit entry when an admin copies the real postback url', function () {
    $network = Network::factory()->create(['driver' => 'fake']);

    Livewire::test(EditNetwork::class, ['record' => $network->getRouteKey()])
        ->call('logPostbackUrlCopy', $network->getKey());

    expect(AuditLog::query()->where('action', 'network.postback_url_copied')->sole()->subject_id)
        ->toBe($network->id);
});

it('names the machine key under the driver select, and keeps up when it changes', function () {
    // The key is the postback route's fallback, the seeder's identifier and the
    // string in every log line. Hiding it behind a brand name would make the
    // admin the one place it cannot be read.
    Livewire::test(CreateNetwork::class)
        ->set('data.show_advanced_connectors', true)
        ->set('data.driver', 'platform-test')
        ->assertSee('Key: platform-test')
        ->assertSee('more than one network may use it')
        ->set('data.driver', 'ogads')
        ->assertSee('Key: ogads')
        ->assertSee('One account.')
        ->assertDontSee('Key: platform-test');
});
