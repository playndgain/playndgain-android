<?php

use App\Enums\OutgoingPostbackKind;
use App\Enums\OutgoingPostbackStatus;
use App\Jobs\DispatchPublisherPostback;
use App\Models\Conversion;
use App\Models\OutgoingPostback;
use App\Services\Money\UrlRedactor;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/MoneyFixtures.php';

const CALLBACK = 'https://pub.test/cb?user={user_id}&tx={trans_id}&offer={offer_id}&name={offer_name}'
    .'&payout={payout_usd}&coins={currency_amount}&app={app_id}&country={country}&ip={ip}'
    .'&status={status}&ts={timestamp}&sig={signature}&secret=topsecret';

/** Fires the pipeline with the queue faked and hands back the conversion it produced. */
function convertWithCallback(string $callback = CALLBACK): Conversion
{
    Queue::fake();

    $click = moneyFixture(app: ['postback_url' => $callback, 'currency_rate' => 1000.0000]);

    firePostback(postbackDto($click, payout: 0.13))->assertOk();

    return Conversion::query()->sole();
}

/** @return array<string, string> */
function sentQuery(): array
{
    $sent = [];

    Http::assertSent(function (ClientRequest $request) use (&$sent) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $sent);

        return true;
    });

    return $sent;
}

function runPostback(
    Conversion $conversion,
    OutgoingPostbackKind $kind = OutgoingPostbackKind::Credit,
    int $attempts = 1,
    ?int $expectedRelease = null,
): DispatchPublisherPostback {
    $job = new DispatchPublisherPostback($conversion, $kind);

    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('attempts')->andReturn($attempts);

    if ($expectedRelease !== null) {
        $queueJob->shouldReceive('release')->once()->with($expectedRelease);
    }

    $job->setJob($queueJob);
    $job->handle();

    return $job;
}

it('resolves every macro in the publisher callback', function () {
    Http::fake(['*' => Http::response('1')]);

    $conversion = convertWithCallback();
    runPostback($conversion);

    $query = sentQuery();

    expect($query['user'])->toBe($conversion->end_user_id)
        ->and($query['tx'])->toBe($conversion->trans_id)
        ->and($query['offer'])->toBe($conversion->offer->external_id)
        ->and($query['name'])->toBe($conversion->offer_name)
        ->and($query['payout'])->toBe('0.091000')
        ->and($query['coins'])->toBe('91.00')
        ->and($query['app'])->toBe((string) $conversion->app->app_id)
        ->and($query['country'])->toBe($conversion->country)
        ->and($query['ip'])->toBe($conversion->ip)
        ->and($query['status'])->toBe('credited')
        ->and((int) $query['ts'])->toBeGreaterThan(1700000000);
});

it('signs the payout string exactly as it appears in the url', function () {
    Http::fake(['*' => Http::response('1')]);

    $conversion = convertWithCallback();
    runPostback($conversion);

    $query = sentQuery();
    $secret = $conversion->app->secret_key;

    expect($query['sig'])->toBe(hash('sha256', $conversion->trans_id.'0.091000'.$secret))
        // A publisher checking a payout the sender did not sign must fail.
        ->and($query['sig'])->not->toBe(hash('sha256', $conversion->trans_id.'0.092000'.$secret));
});

it('signs a reversal over the negative payout so it cannot be replayed as a credit', function () {
    Http::fake(['*' => Http::response('1')]);

    $conversion = convertWithCallback();

    runPostback($conversion);
    $credit = sentQuery();

    runPostback($conversion, OutgoingPostbackKind::Reversal);
    $reversal = sentQuery();

    $secret = $conversion->app->secret_key;

    expect($reversal['payout'])->toBe('-0.091000')
        ->and($reversal['coins'])->toBe('-91.00')
        ->and($reversal['status'])->toBe('reversed')
        ->and($reversal['tx'])->toBe($credit['tx'])
        ->and($reversal['sig'])->toBe(hash('sha256', $conversion->trans_id.'-0.091000'.$secret))
        ->and($reversal['sig'])->not->toBe($credit['sig']);
});

it('stores the attempt with the signature and the secret masked', function () {
    Http::fake(['*' => Http::response('ok')]);

    $conversion = convertWithCallback();
    runPostback($conversion);

    $log = OutgoingPostback::query()->sole();
    $signature = sentQuery()['sig'];

    expect($log->url)->not->toContain($signature)
        ->and($log->url)->not->toContain('topsecret')
        ->and($log->url)->toContain('sig='.UrlRedactor::MASK)
        ->and($log->url)->toContain('secret='.UrlRedactor::MASK)
        ->and($log->url)->toContain($conversion->trans_id)
        ->and($log->status)->toBe(OutgoingPostbackStatus::Success)
        ->and($log->http_status)->toBe(200)
        ->and($log->response_body)->toBe('ok')
        ->and($log->attempt)->toBe(1);
});

it('treats anything but a 2xx as a retry', function () {
    $this->freezeTime();
    Http::fake(['*' => Http::response('server on fire', 500)]);

    $conversion = convertWithCallback();
    runPostback($conversion, attempts: 1, expectedRelease: 60);

    $log = OutgoingPostback::query()->sole();

    expect($log->status)->toBe(OutgoingPostbackStatus::Retrying)
        ->and($log->http_status)->toBe(500)
        ->and($log->response_body)->toBe('server on fire')
        ->and($log->next_retry_at->timestamp)->toBe(now()->addSeconds(60)->timestamp);
});

it('writes its own row for every attempt and walks the backoff schedule', function () {
    Http::fake(['*' => Http::response('nope', 503)]);

    $conversion = convertWithCallback();

    foreach ([1 => 60, 2 => 300, 3 => 1800, 4 => 7200, 5 => 21600] as $attempt => $delay) {
        runPostback($conversion, attempts: $attempt, expectedRelease: $delay);
    }

    $logs = OutgoingPostback::query()->orderBy('id')->get();

    expect($logs)->toHaveCount(5)
        ->and($logs->pluck('attempt')->all())->toBe([1, 2, 3, 4, 5])
        ->and($logs->pluck('status')->unique()->all())->toBe([OutgoingPostbackStatus::Retrying]);
});

it('marks the last attempt failed when the retries run out', function () {
    Http::fake(['*' => Http::response('still down', 500)]);

    $conversion = convertWithCallback();

    // The sixth run is the last: `tries = 6`, and the worker calls failed()
    // once it is exhausted.
    $job = runPostback($conversion, attempts: 6, expectedRelease: 21600);
    $job->failed(new RuntimeException('publisher unreachable'));

    $log = OutgoingPostback::query()->latest('id')->first();

    expect($log->attempt)->toBe(6)
        ->and($log->status)->toBe(OutgoingPostbackStatus::Failed)
        ->and($log->error)->toContain('publisher unreachable');
});

it('retries a publisher whose host does not resolve', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host'));

    $conversion = convertWithCallback();
    runPostback($conversion, attempts: 1, expectedRelease: 60);

    $log = OutgoingPostback::query()->sole();

    expect($log->status)->toBe(OutgoingPostbackStatus::Retrying)
        ->and($log->http_status)->toBeNull()
        ->and($log->error)->toContain('Could not resolve host');
});

it('queues nothing and errors on nothing when the app has no callback url', function () {
    Http::fake();

    $conversion = convertWithCallback(callback: '');

    Queue::assertNothingPushed();

    runPostback($conversion);

    expect(OutgoingPostback::query()->count())->toBe(0);

    Http::assertNothingSent();
});

it('honours the retry contract the spec states', function () {
    $job = new DispatchPublisherPostback(new Conversion);

    expect($job->tries)->toBe(6)
        ->and($job->timeout)->toBe(20)
        ->and($job->backoff)->toBe([60, 300, 1800, 7200, 21600]);
});
