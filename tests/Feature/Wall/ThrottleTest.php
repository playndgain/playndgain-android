<?php

use App\Enums\FraudActionTaken;
use App\Models\FraudEvent;
use App\Services\Wall\ThrottleReporter;
use Illuminate\Http\Request;

require_once __DIR__.'/WallFixtures.php';

it('returns 429 once the per-ip wall limit is exceeded', function () {
    // The limiter reads config per request, so pin the spec default here: the
    // local .env raises ADVAULT_LIMIT_WALL_IP for the docs/12 load test, and
    // this test must not need 600 requests to trip because of it.
    config()->set('advault.rate_limits.wall_per_ip', 60);

    // The limiter runs before the controller, so an unresolvable app id is the
    // cheapest way to spend the budget.
    foreach (range(1, 60) as $ignored) {
        test()->get('/wall?appid=99999&userid=USER_1')->assertNotFound();
    }

    test()->get('/wall?appid=99999&userid=USER_1')->assertStatus(429);
});

it('writes a flag-level fraud event when a limit is exceeded', function () {
    $response = app(ThrottleReporter::class)->report(
        Request::create('/wall', 'GET', ['appid' => '10042', 'userid' => 'USER_1'])
    );

    expect($response->getStatusCode())->toBe(429)
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');

    $event = FraudEvent::query()->sole();

    // Sustained rate-limit hits are a fraud signal, not only a load signal.
    expect($event->rule_key)->toBe('rate_limit')
        ->and($event->action_taken)->toBe(FraudActionTaken::Flagged)
        ->and($event->end_user_id)->toBe('USER_1')
        ->and($event->context['ip'])->toBe('127.0.0.1');
});

it('records one event per minute per key rather than one per shed request', function () {
    $request = Request::create('/wall', 'GET', ['appid' => '10042', 'userid' => 'USER_1']);

    foreach (range(1, 5) as $ignored) {
        app(ThrottleReporter::class)->report($request);
    }

    expect(FraudEvent::query()->count())->toBe(1);
});
