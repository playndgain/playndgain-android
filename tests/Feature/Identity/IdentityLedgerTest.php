<?php

use App\Enums\IdentityKind;
use App\Enums\IdentitySignal;
use App\Http\Controllers\ImpersonationController;
use App\Identity\DeviceSignals;
use App\Identity\IdentityLedger;
use App\Models\AccountMatch;
use App\Models\FraudEvent;
use App\Models\IdentitySession;
use App\Models\Publisher;
use App\Models\User;
use App\Notifications\AdminAlertNotification;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/../Wall/WallFixtures.php';
require_once __DIR__.'/../Alerts/AlertFixtures.php';

function identityRequest(string $ip = '203.0.113.10', ?string $ua = WALL_UA, array $query = []): Request
{
    return Request::create('/wall', 'GET', $query, server: [
        'REMOTE_ADDR' => $ip,
        'HTTP_USER_AGENT' => $ua ?? '',
    ]);
}

function encodeDs(array $signals = []): string
{
    $payload = [
        'c' => $signals['c'] ?? 8,
        'm' => $signals['m'] ?? 4,
        'p' => $signals['p'] ?? 2.0,
        'r' => $signals['r'] ?? 'Mali-G710',
        't' => $signals['t'] ?? 5,
        'w' => $signals['w'] ?? 0,
    ];

    return rtrim(strtr(base64_encode(json_encode($payload, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
}

it('hashes a valid ds payload and rejects junk', function () {
    $ds = encodeDs();

    expect(DeviceSignals::hash($ds))->toBe(DeviceSignals::hash($ds))
        ->and(DeviceSignals::hash($ds))->toHaveLength(64)
        ->and(DeviceSignals::hash(null))->toBeNull()
        ->and(DeviceSignals::hash('not-valid'))->toBeNull()
        ->and(DeviceSignals::hash(str_repeat('a', DeviceSignals::MAX_LENGTH + 1)))->toBeNull();
});

it('records a panel login and fills last_login columns', function () {
    $publisher = Publisher::factory()->create();
    $request = identityRequest('198.51.100.20');
    $request->setLaravelSession(app('session')->driver());
    $request->session()->put('id', 'sess-abc');

    app(IdentityLedger::class)->recordPanel($publisher->user, $request);

    $session = IdentitySession::query()->sole();

    expect($session->kind)->toBe(IdentityKind::Panel)
        ->and($session->user_id)->toBe($publisher->user_id)
        ->and($session->ip)->toBe('198.51.100.20')
        ->and($session->ua_hash)->toBe(hash('sha256', WALL_UA))
        ->and($publisher->user->fresh()->last_login_ip)->toBe('198.51.100.20')
        ->and($publisher->user->fresh()->last_login_at)->not->toBeNull();
});

it('alerts once when two publisher accounts share an IP', function () {
    Notification::fake();
    alertRecipients(['ops@playndgain.com']);

    $first = Publisher::factory()->create();
    $second = Publisher::factory()->create();
    $request = identityRequest('198.51.100.40');

    app(IdentityLedger::class)->recordPanel($first->user, $request);
    app(IdentityLedger::class)->recordPanel($second->user, $request);
    app(IdentityLedger::class)->recordPanel($second->user, $request);

    $match = AccountMatch::query()->sole();

    expect($match->scope)->toBe(IdentityKind::Panel)
        ->and($match->signal)->toBe(IdentitySignal::Ip)
        ->and($match->seen_count)->toBe(2)
        ->and([$match->left_user_id, $match->right_user_id])->toEqualCanonicalizing([
            $first->user_id,
            $second->user_id,
        ]);

    Notification::assertSentOnDemandTimes(AdminAlertNotification::class, 1);
});

it('does not match an admin login against a publisher on the same IP', function () {
    Notification::fake();
    alertRecipients(['ops@playndgain.com']);

    $publisher = Publisher::factory()->create();
    $admin = User::factory()->admin()->create();
    $request = identityRequest('198.51.100.50');

    app(IdentityLedger::class)->recordPanel($publisher->user, $request);
    app(IdentityLedger::class)->recordPanel($admin, $request);

    expect(AccountMatch::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('skips panel recording while impersonating', function () {
    $publisher = Publisher::factory()->create();
    $request = identityRequest();
    $request->setLaravelSession(app('session')->driver());
    $request->session()->put(ImpersonationController::SESSION_KEY, 1);

    app(IdentityLedger::class)->recordPanel($publisher->user, $request);

    expect(IdentitySession::query()->count())->toBe(0)
        ->and($publisher->user->fresh()->last_login_ip)->toBeNull();
});

it('matches two wall userids on the same publisher IP without a fraud event or email', function () {
    Notification::fake();
    alertRecipients(['ops@playndgain.com']);
    wallGeo('US');

    [$app] = wallFixture();
    $ledger = app(IdentityLedger::class);

    $ledger->recordWall(partnerContext($app, 'USER_A'), identityRequest('203.0.113.80'));
    $ledger->recordWall(partnerContext($app, 'USER_B'), identityRequest('203.0.113.80'));

    $match = AccountMatch::query()->sole();

    expect($match->scope)->toBe(IdentityKind::Wall)
        ->and($match->signal)->toBe(IdentitySignal::Ip)
        ->and($match->publisher_id)->toBe($app->publisher_id)
        ->and([$match->left_end_user_id, $match->right_end_user_id])->toEqualCanonicalizing(['USER_A', 'USER_B'])
        ->and(FraudEvent::query()->count())->toBe(0);

    Notification::assertNothingSent();
});

it('does not treat the same userid on two publishers as a wall match', function () {
    wallGeo('US');

    [$appA] = wallFixture();
    [$appB] = wallFixture();

    app(IdentityLedger::class)->recordWall(partnerContext($appA, 'USER_1'), identityRequest('203.0.113.90'));
    app(IdentityLedger::class)->recordWall(partnerContext($appB, 'USER_1'), identityRequest('203.0.113.90'));

    expect(AccountMatch::query()->count())->toBe(0);
});

it('matches wall accounts that share a ds hash', function () {
    wallGeo('US');
    [$app] = wallFixture();
    $ds = encodeDs(['r' => 'SwiftShader']);

    app(IdentityLedger::class)->recordWall(
        partnerContext($app, 'ALPHA'),
        identityRequest('203.0.113.11', WALL_UA, ['ds' => $ds]),
    );
    app(IdentityLedger::class)->recordWall(
        partnerContext($app, 'BETA'),
        identityRequest('203.0.113.12', WALL_UA, ['ds' => $ds]),
    );

    expect(AccountMatch::query()->where('signal', IdentitySignal::Ds)->count())->toBe(1)
        ->and(IdentitySession::query()->whereNotNull('ds_hash')->count())->toBe(2);
});

it('skips wall writes in test mode', function () {
    wallGeo('US');
    [$app] = wallFixture(['test_mode' => true]);

    app(IdentityLedger::class)->recordWall(partnerContext($app), identityRequest());

    expect(IdentitySession::query()->count())->toBe(0);
});

it('dedupes a wall session write to one row per hour', function () {
    wallGeo('US');
    Cache::flush();
    [$app] = wallFixture();
    $ledger = app(IdentityLedger::class);
    $context = partnerContext($app, 'USER_1');
    $request = identityRequest('203.0.113.70');

    $ledger->recordWall($context, $request);
    $ledger->recordWall($context, $request);

    expect(IdentitySession::query()->count())->toBe(1);
});

it('does not write a wall session when consent is required and missing', function () {
    [$app, $network] = wallFixture();
    wallConsentCountries(['DE']);
    wallGeo('DE');
    wallOffer($network, ['countries' => [], 'devices' => []]);

    wallGet($app)->assertOk();

    expect(IdentitySession::query()->count())->toBe(0)
        ->and(AccountMatch::query()->count())->toBe(0);
});

it('writes a wall session after a granted consent', function () {
    [$app, $network] = wallFixture();
    wallConsentCountries(['DE']);
    wallGeo('DE');
    wallOffer($network, ['countries' => [], 'devices' => []]);

    test()->withHeaders(['User-Agent' => WALL_UA])
        ->post('/wall/consent', ['appid' => $app->app_id, 'userid' => 'USER_1', 'action' => 'accept']);

    wallGet($app)->assertOk();

    expect(IdentitySession::query()->where('kind', IdentityKind::Wall)->count())->toBe(1);
});

it('records a panel session from the Filament login event', function () {
    $publisher = Publisher::factory()->create();

    $request = identityRequest('203.0.113.21');
    $request->setLaravelSession(app('session')->driver());
    app()->instance('request', $request);

    event(new Login('web', $publisher->user, false));

    expect(IdentitySession::query()->where('kind', IdentityKind::Panel)->count())->toBe(1)
        ->and($publisher->user->fresh()->last_login_ip)->toBe('203.0.113.21');
});

it('raises a panel match alert with a per-pair key', function () {
    Cache::flush();

    $first = Publisher::factory()->create();
    $second = Publisher::factory()->create();
    $left = min($first->user_id, $second->user_id);
    $right = max($first->user_id, $second->user_id);

    Notification::fake();
    alertRecipients(['ops@playndgain.com']);

    $request = identityRequest('198.51.100.88');
    app(IdentityLedger::class)->recordPanel($first->user, $request);
    app(IdentityLedger::class)->recordPanel($second->user, $request);

    Notification::assertSentOnDemand(
        AdminAlertNotification::class,
        fn (AdminAlertNotification $notification): bool => $notification->key === "identity.panel_match.{$left}.{$right}",
    );
});
