<?php

use App\Enums\AppStatus;
use App\Enums\OutgoingPostbackKind;
use App\Enums\PublisherStatus;
use App\Filament\Admin\Resources\Apps\Pages\EditApp as AdminEditApp;
use App\Filament\Publisher\Auth\EditProfile;
use App\Filament\Publisher\Resources\Apps\Pages\CreateApp;
use App\Filament\Publisher\Resources\Apps\Pages\EditApp;
use App\Filament\Publisher\Widgets\EarningsStats;
use App\Filament\Publisher\Widgets\LatestTransactions;
use App\Filament\Publisher\Widgets\PostbackHealth;
use App\Filament\Publisher\Widgets\WithdrawalSummary;
use App\Models\App;
use App\Models\Conversion;
use App\Models\OutgoingPostback;
use App\Models\Publisher;
use App\Models\User;
use App\Services\Money\MacroResolver;
use App\Services\Money\Money;
use App\Services\Money\TestPostbackService;
use App\Support\Contrast;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * Panel behaviour from docs/08 §16 that is not about scoping — the pending and
 * suspended states, the currency example, the test postback.
 */
it('lets a pending publisher reach the dashboard and create an app', function () {
    $publisher = Publisher::factory()->pending()->create();

    $this->actingAs($publisher->user);

    $this->get('/dashboard')->assertSuccessful();

    $app = App::factory()->create([
        'publisher_id' => $publisher->id,
        'status' => AppStatus::Pending,
    ]);

    // Created, but not serving: the gate is on earning, not on exploring.
    expect($app->isActive())->toBeFalse()
        ->and(App::query()->count())->toBe(1);
});

it('keeps a banned publisher out of the panel', function () {
    $publisher = Publisher::factory()->create(['status' => PublisherStatus::Banned]);

    $this->actingAs($publisher->user);

    // The role still says publisher; the panel is what says no — docs/08 §1.
    expect($publisher->user->canAccessPanel(Filament::getPanel('publisher')))->toBeFalse();

    $this->get('/dashboard')->assertForbidden();
});

it('paints the create-app preview with the wall\'s own chrome', function () {
    $publisher = Publisher::factory()->create(['rev_share' => 70]);

    $this->actingAs($publisher->user);
    Filament::setCurrentPanel('publisher');

    Livewire::test(CreateApp::class)
        ->fillForm([
            'name' => 'Rewards Hub',
            'currency_name' => 'Coins',
            'currency_rate' => 1000,
        ])
        ->assertSee('wall-card', false)
        ->assertSee('wall-reward', false)
        ->assertSee(__('wall.all_offers'))
        ->assertSee(__('wall.featured_offers'))
        ->assertSee('Coin Master')
        ->assertSee('+2,940')
        ->assertSee('app-macros', false)
        ->assertDontSee('Earn Coins');
});

it('renders the currency example at several rates and shares', function (float $revShare, float $rate, string $expected) {
    $payout = Money::share('0.50', Money::format($revShare, 2));
    $reward = Money::currency($payout, (string) $rate);

    expect(number_format((float) $reward, 0))->toBe($expected);
})->with([
    [70.0, 1000.0, '350'],
    [70.0, 500.0, '175'],
    [80.0, 1000.0, '400'],
    [65.0, 2000.0, '650'],
]);

it('accepts a callback url that carries the documented macros', function () {
    // Laravel's `url` rule rejects the braces. The same tab lists them.
    // A trailing space is what a mobile paste actually sends.
    $pasted = 'https://rewardshub.us/api/postback/advalut?user_id={user_id}&trans_id={trans_id}&campaign_id={offer_id}&campaign_name={offer_name}&amount={currency_amount}&payout={payout_usd}&ip={ip}&status={status}&signature={signature} ';

    expect(MacroResolver::isHttpsTemplate($pasted))->toBeTrue()
        ->and(MacroResolver::isHttpsTemplate('https://rewardshub.us/api/postback/advalut?u'))->toBeTrue()
        ->and(MacroResolver::isHttpsTemplate('http://rewardshub.us/cb'))->toBeFalse()
        ->and(MacroResolver::isHttpsTemplate('javascript:alert(1)'))->toBeFalse();

    $publisher = Publisher::factory()->create();
    $app = App::factory()->create(['publisher_id' => $publisher->id]);

    $this->actingAs($publisher->user);

    Livewire::test(EditApp::class, ['record' => $app->getRouteKey()])
        ->fillForm(['postback_url' => $pasted])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($app->fresh()->postback_url)->toBe(trim($pasted));
});

it('accepts the same callback url on the admin app form', function () {
    Config::set('advault.require_admin_2fa', false);

    $this->actingAs(User::factory()->admin()->create([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_recovery_codes' => ['aaaa-bbbb'],
    ]));

    $app = App::factory()->create();
    $url = 'https://rewardshub.us/api/postback/advalut?user_id={user_id}&trans_id={trans_id}&campaign_id={offer_id}&campaign_name={offer_name}&amount={currency_amount}&payout={payout_usd}&ip={ip}&status={status}&signature={signature}';

    Livewire::test(AdminEditApp::class, ['record' => $app->getRouteKey()])
        ->fillForm(['postback_url' => $url])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($app->fresh()->postback_url)->toBe($url);
});

it('rejects a callback url that is not https', function () {
    $publisher = Publisher::factory()->create();
    $app = App::factory()->create(['publisher_id' => $publisher->id]);

    $this->actingAs($publisher->user);

    Livewire::test(EditApp::class, ['record' => $app->getRouteKey()])
        ->fillForm(['postback_url' => 'http://rewardshub.us/cb'])
        ->call('save')
        ->assertHasFormErrors(['postback_url']);
});

it('fires a real test postback and reports what came back', function () {
    Http::fake([
        '*' => Http::response('OK', 200),
    ]);

    $publisher = Publisher::factory()->create();
    $app = App::factory()->create([
        'publisher_id' => $publisher->id,
        'postback_url' => 'https://publisher.example/cb?tx={trans_id}&amount={payout_usd}&sig={signature}',
    ]);

    $result = app(TestPostbackService::class)->send($app, OutgoingPostbackKind::Credit, '42');

    expect($result['ok'])->toBeTrue()
        ->and($result['status'])->toBe(200)
        ->and($result['body'])->toBe('OK');

    Http::assertSent(function ($request) {
        // Macros resolved, signature present, and nothing left unexpanded.
        return str_contains($request->url(), 'tx=')
            && ! str_contains($request->url(), '{trans_id}')
            && ! str_contains($request->url(), '{signature}');
    });
});

it('sends the typed user id and a reversed payout', function () {
    Http::fake(['*' => Http::response('ok', 200)]);

    $publisher = Publisher::factory()->create();
    $app = App::factory()->create([
        'publisher_id' => $publisher->id,
        'postback_url' => 'https://publisher.example/cb?user={user_id}&offer={offer_id}&payout={payout_usd}&status={status}&sig={signature}',
    ]);

    $result = app(TestPostbackService::class)->send($app, OutgoingPostbackKind::Reversal, '42');

    expect($result['ok'])->toBeTrue();

    Http::assertSent(function ($request) {
        $url = $request->url();

        return str_contains($url, 'user=42')
            && str_contains($url, 'offer=0')
            && str_contains($url, 'payout=-0.100000')
            && str_contains($url, 'status=reversed')
            && ! str_contains($url, '{user_id}');
    });
});

it('collects user id and status on the test postback modal', function () {
    Http::fake(['*' => Http::response('ok', 200)]);
    Filament::setCurrentPanel('publisher');

    $publisher = Publisher::factory()->create();
    $app = App::factory()->create([
        'publisher_id' => $publisher->id,
        'postback_url' => 'https://publisher.example/cb?user={user_id}&status={status}',
    ]);

    $this->actingAs($publisher->user);

    Livewire::test(EditApp::class, ['record' => $app->getRouteKey()])
        ->callAction('publisher_test_postback', [
            'user_id' => '99',
            'status' => 'credited',
        ]);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'user=99')
        && str_contains($request->url(), 'status=credited'));
});

it('writes nothing to the money tables when a test postback fires', function () {
    Http::fake(['*' => Http::response('OK', 200)]);

    $publisher = Publisher::factory()->create();
    $app = App::factory()->create([
        'publisher_id' => $publisher->id,
        'postback_url' => 'https://publisher.example/cb?tx={trans_id}',
    ]);

    app(TestPostbackService::class)->send($app);

    expect(Conversion::query()->count())->toBe(0)
        ->and(OutgoingPostback::query()->count())->toBe(0)
        ->and($publisher->fresh()->balance)->toBe('0.000000');
});

it('reports a connection failure instead of throwing', function () {
    Http::fake(fn () => throw new ConnectionException('dns failure'));

    $publisher = Publisher::factory()->create();
    $app = App::factory()->create([
        'publisher_id' => $publisher->id,
        'postback_url' => 'https://nowhere.example/cb',
    ]);

    $result = app(TestPostbackService::class)->send($app);

    expect($result['ok'])->toBeFalse()
        ->and($result['status'])->toBeNull()
        ->and($result['body'])->toContain('dns failure');
});

it('warns when a wall palette fails the contrast minimum', function () {
    expect(Contrast::verdict('#F5F7FA', '#0B0D10'))->toContain('passes WCAG AA')
        ->and(Contrast::verdict('#999999', '#AAAAAA'))->toContain('below the 4.5:1 minimum')
        ->and(Contrast::ratio('#FFFFFF', '#000000'))->toBe(21.0);
});

/**
 * Widget discovery scans the whole directory, so a widget written for one page
 * lands on the Dashboard too unless it opts out. docs/08 §3 lists the rows the
 * Dashboard has, and neither of these is on it.
 */
it('keeps the page-specific widgets off the dashboard', function () {
    $dashboard = Filament::getPanel('publisher')->getWidgets();

    expect($dashboard)
        ->not->toContain(WithdrawalSummary::class)
        ->not->toContain(PostbackHealth::class)
        ->toContain(EarningsStats::class);
});

it('narrows the dashboard widgets to the selected app, and nothing else', function () {
    $publisher = Publisher::factory()->create();
    $kept = App::factory()->create(['publisher_id' => $publisher->id]);
    $other = App::factory()->create(['publisher_id' => $publisher->id]);

    $this->actingAs($publisher->user);

    Conversion::factory()->create(['publisher_id' => $publisher->id, 'app_id' => $kept->id, 'offer_name' => 'Kept offer']);
    Conversion::factory()->create(['publisher_id' => $publisher->id, 'app_id' => $other->id, 'offer_name' => 'Other offer']);

    Livewire::test(LatestTransactions::class, ['pageFilters' => ['app_id' => $kept->id]])
        ->assertSee('Kept offer')
        ->assertDontSee('Other offer');

    // No selection is every app, not no app.
    Livewire::test(LatestTransactions::class, ['pageFilters' => ['app_id' => null]])
        ->assertSee('Kept offer')
        ->assertSee('Other offer');
});

/**
 * docs/08 §14: the timezone "drives every date shown in the panel". It reaches
 * them through Filament's timezone manager, which the panel provider points at
 * this column — so the assertion that matters is on the manager, not on any one
 * rendered column.
 */
it('renders panel dates in the publisher\'s own timezone', function () {
    $publisher = Publisher::factory()->create(['timezone' => 'Africa/Cairo']);

    $this->actingAs($publisher->user);

    // Through a real request rather than by booting the panel by hand: the
    // wiring being tested is that panel boot reaches the manager at all.
    $this->get('/dashboard')->assertSuccessful();

    expect(FilamentTimezone::get())->toBe('Africa/Cairo');

    // Blank means the platform default, so a later change to it reaches
    // everyone who never picked one.
    $publisher->update(['timezone' => null]);

    expect($publisher->fresh()->resolvedTimezone())->toBe(config('app.timezone'));
});

it('reads an unset notification preference as on, and an unticked one as off', function () {
    $publisher = Publisher::factory()->create();

    // Never opened the page: everything is on. Silence by omission would mean
    // a publisher never hears their postbacks are failing.
    expect($publisher->wantsNotification('postback_failures'))->toBeTrue();

    $publisher->update(['notification_preferences' => [
        'postback_failures' => false,
        'withdrawal_status' => true,
    ]]);

    expect($publisher->wantsNotification('postback_failures'))->toBeFalse()
        ->and($publisher->wantsNotification('withdrawal_status'))->toBeTrue()
        // Absent from the stored map but a real type: still on.
        ->and($publisher->wantsNotification('monthly_summary'))->toBeTrue()
        // Not a type at all. A stale key cannot switch on something we never
        // agreed to send.
        ->and($publisher->wantsNotification('everything'))->toBeFalse();
});

it('saves the timezone and the notification preferences off the profile page', function () {
    $publisher = Publisher::factory()->create();

    $this->actingAs($publisher->user);

    Livewire::test(EditProfile::class)
        ->fillForm([
            'name' => $publisher->user->name,
            'email' => $publisher->user->email,
            'timezone' => 'Europe/Berlin',
            'notification_preferences' => ['withdrawal_status', 'account_status'],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $publisher->refresh();

    expect($publisher->timezone)->toBe('Europe/Berlin')
        ->and($publisher->wantsNotification('withdrawal_status'))->toBeTrue()
        // Unticked is stored as false rather than left out, which is the whole
        // reason the column holds a map and not a list.
        ->and($publisher->wantsNotification('postback_failures'))->toBeFalse();
});

it('lists browser sessions and signs the other ones out', function () {
    config()->set('session.driver', 'database');

    $publisher = Publisher::factory()->create();

    $this->actingAs($publisher->user);

    // One row for this session, one for a phone somewhere else.
    foreach ([session()->getId(), 'a-session-on-another-device'] as $id) {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $publisher->user->id,
            'ip_address' => '203.0.113.9',
            'user_agent' => 'Mozilla/5.0 (Linux; Android 13; Pixel 7) Chrome/120.0 Mobile Safari/537.36',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);
    }

    Livewire::test(EditProfile::class)
        ->assertSee('Chrome')
        ->assertSee('this device')
        ->callAction('logOutOtherSessions', ['password' => 'password']);

    expect(DB::table('sessions')->where('user_id', $publisher->user->id)->pluck('id')->all())
        ->toBe([session()->getId()]);
});
