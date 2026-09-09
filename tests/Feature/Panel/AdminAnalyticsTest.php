<?php

use App\Enums\ConversionStatus;
use App\Enums\FraudActionTaken;
use App\Enums\FraudStage;
use App\Filament\Admin\Analytics\ChargebackSources;
use App\Filament\Admin\Analytics\ConversionsByStatus;
use App\Filament\Admin\Analytics\FraudSources;
use App\Filament\Admin\Analytics\RevenueMetric;
use App\Filament\Admin\Pages\Analytics;
use App\Models\App;
use App\Models\Conversion;
use App\Models\DailyStat;
use App\Models\FraudEvent;
use App\Models\Network;
use App\Models\Offer;
use App\Models\Publisher;
use App\Models\User;
use App\Services\Money\Money;
use Database\Factories\OfferClickFactory;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;

function analyticsAdmin(): User
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

function analyticsPublisher(): array
{
    $publisher = Publisher::factory()->create();
    $app = App::factory()->create(['publisher_id' => $publisher->id]);
    $network = Network::factory()->create();
    $offer = Offer::factory()->create(['network_id' => $network->id]);

    return [$publisher, $app, $network, $offer];
}

beforeEach(function () {
    Carbon::setTestNow('2026-09-09 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('renders analytics for an admin and forbids a publisher', function () {
    analyticsAdmin();

    Livewire::test(Analytics::class)->assertSuccessful();
    $this->get('/admin/analytics')->assertSuccessful();

    $publisher = Publisher::factory()->create();
    $this->actingAs($publisher->user);
    $this->get('/admin/analytics')->assertForbidden();
});

it('does not put analytics widgets on the home dashboard', function () {
    analyticsAdmin();

    Livewire::test(Dashboard::class)
        ->assertSuccessful()
        ->assertDontSee('Chargebacks by source')
        ->assertDontSeeLivewire(ChargebackSources::class);
});

it('shows StatsQuery revenue on the metric widget', function () {
    analyticsAdmin();
    [$publisher, $app, $network] = analyticsPublisher();

    DailyStat::create([
        'date' => '2026-09-09',
        'publisher_id' => $publisher->id,
        'app_id' => $app->id,
        'network_id' => $network->id,
        'country' => 'EG',
        'clicks' => 100,
        'conversions' => 10,
        'reversals' => 1,
        'revenue' => '20.000000',
        'payout' => '14.000000',
    ]);

    Livewire::test(RevenueMetric::class)
        ->assertSuccessful()
        ->assertSee(Money::display('20.000000'));
});

it('ranks chargebacks and switches the source group', function () {
    analyticsAdmin();
    [$heavy, $heavyApp, $network, $offer] = analyticsPublisher();
    [$light, $lightApp] = analyticsPublisher();

    Conversion::factory()->create([
        'publisher_id' => $heavy->id,
        'app_id' => $heavyApp->id,
        'network_id' => $network->id,
        'offer_id' => $offer->id,
        'offer_name' => $offer->name,
        'status' => ConversionStatus::Reversed,
        'payout' => '5.000000',
        'reversed_at' => now(),
        'created_at' => now()->subDays(8),
    ]);
    Conversion::factory()->create([
        'publisher_id' => $light->id,
        'app_id' => $lightApp->id,
        'network_id' => $network->id,
        'offer_id' => $offer->id,
        'offer_name' => $offer->name,
        'status' => ConversionStatus::Reversed,
        'payout' => '1.000000',
        'reversed_at' => now(),
        'created_at' => now()->subDays(8),
    ]);

    Livewire::test(ChargebackSources::class)
        ->assertSuccessful()
        ->assertSee('#'.$heavy->pub_id)
        ->set('source', 'offer')
        ->assertSee($offer->name);
});

it('shows Unknown for a fraud event without an ip', function () {
    analyticsAdmin();
    [$publisher, $app] = analyticsPublisher();
    $click = OfferClickFactory::new()->create([
        'app_id' => $app->id,
        'publisher_id' => $publisher->id,
    ]);

    FraudEvent::query()->create([
        'rule_key' => 'ip_risk_score',
        'stage' => FraudStage::PreClick,
        'subject_type' => $click->getMorphClass(),
        'subject_id' => $click->getKey(),
        'app_id' => $app->id,
        'publisher_id' => $publisher->id,
        'end_user_id' => $click->end_user_id,
        'action_taken' => FraudActionTaken::Flagged,
        'context' => ['reason' => 'no ip stored'],
        'created_at' => now(),
    ]);

    Livewire::test(FraudSources::class)
        ->set('source', 'ip')
        ->assertSuccessful()
        ->assertSee('Unknown');
});

it('shows conversion status counts, not revenue', function () {
    analyticsAdmin();
    [$publisher, $app, $network, $offer] = analyticsPublisher();

    Conversion::factory()->create([
        'publisher_id' => $publisher->id,
        'app_id' => $app->id,
        'network_id' => $network->id,
        'offer_id' => $offer->id,
        'status' => ConversionStatus::Approved,
        'revenue' => '40.000000',
        'created_at' => now(),
    ]);
    Conversion::factory()->create([
        'publisher_id' => $publisher->id,
        'app_id' => $app->id,
        'network_id' => $network->id,
        'offer_id' => $offer->id,
        'status' => ConversionStatus::Pending,
        'revenue' => '10.000000',
        'created_at' => now(),
    ]);

    Livewire::test(ConversionsByStatus::class)
        ->assertSuccessful()
        ->assertSee('Approved')
        ->assertSee('Pending')
        ->assertDontSee(Money::display('40.000000'));
});
