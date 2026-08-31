<?php

use App\Enums\OutgoingPostbackKind;
use App\Enums\OutgoingPostbackStatus;
use App\Fraud\MaxMindIpIntelligence;
use App\Jobs\DispatchPublisherPostback;
use App\Models\Conversion;
use App\Models\OutgoingPostback;
use App\Models\Setting;
use App\Notifications\AdminAlertNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/AlertFixtures.php';

/** Writes `$count` attempt rows for one conversion, `$failed` of them not delivered. */
function postbackAttempts(int $count, int $failed): void
{
    $conversion = Conversion::factory()->create();

    foreach (range(1, $count) as $i) {
        OutgoingPostback::create([
            'conversion_id' => $conversion->id,
            'app_id' => $conversion->app_id,
            'kind' => OutgoingPostbackKind::Credit,
            'url' => 'https://pub.test/cb',
            'method' => 'GET',
            'attempt' => 1,
            'status' => $i <= $failed ? OutgoingPostbackStatus::Failed : OutgoingPostbackStatus::Success,
            'created_at' => now()->subMinutes(5),
        ]);
    }
}

function assertRaised(string $key): void
{
    Notification::assertSentOnDemand(
        AdminAlertNotification::class,
        fn (AdminAlertNotification $n): bool => $n->key === $key
    );
}

function assertNotRaised(string $key): void
{
    Notification::assertNotSentTo(
        new AnonymousNotifiable,
        AdminAlertNotification::class,
        fn (AdminAlertNotification $n): bool => $n->key === $key
    );
}

beforeEach(function () {
    Notification::fake();
    alertRecipients(['ops@advault.io']);

    // Noon: inside the active window, so the silence check is live unless a
    // test moves the clock out of it on purpose.
    $this->travelTo('2026-08-17 12:00:00');
});

it('raises the global postback failure rate above the threshold', function () {
    postbackAttempts(count: 40, failed: 8);   // 20%

    $this->artisan('alerts:check')->assertSuccessful();

    assertRaised('postback.failure_rate');
});

it('stays quiet on a failure rate at or under the threshold', function () {
    postbackAttempts(count: 100, failed: 5);   // exactly 5%, the spec's line

    $this->artisan('alerts:check')->assertSuccessful();

    assertNotRaised('postback.failure_rate');
});

it('ignores a window too small for a rate to mean anything', function () {
    // One failure out of two is 50% and says nothing about the platform.
    postbackAttempts(count: 2, failed: 1);

    $this->artisan('alerts:check')->assertSuccessful();

    assertNotRaised('postback.failure_rate');
});

it('ignores failures that fell outside the window', function () {
    postbackAttempts(count: 40, failed: 40);

    OutgoingPostback::query()->update(['created_at' => now()->subHours(3)]);

    $this->artisan('alerts:check')->assertSuccessful();

    assertNotRaised('postback.failure_rate');
});

it('reads the threshold from settings rather than the config default', function () {
    postbackAttempts(count: 40, failed: 8);   // 20%, under a tuned 25%

    Setting::set('alert_postback_failure_rate', '25');

    $this->artisan('alerts:check')->assertSuccessful();

    assertNotRaised('postback.failure_rate');
});

it('raises queue depth when the postbacks queue backs up past the threshold', function () {
    Queue::fake();
    Setting::set('alert_queue_depth', '2');

    foreach (range(1, 3) as $ignored) {
        Queue::pushOn('postbacks', new DispatchPublisherPostback(new Conversion));
    }

    $this->artisan('alerts:check')->assertSuccessful();

    assertRaised('queue.depth');
});

it('stays quiet on a queue at the threshold, and ignores depth on other queues', function () {
    Queue::fake();
    Setting::set('alert_queue_depth', '2');

    Queue::pushOn('postbacks', new DispatchPublisherPostback(new Conversion));
    Queue::pushOn('postbacks', new DispatchPublisherPostback(new Conversion));

    // A ten-minute catalogue sync piling up on `sync` is not a postback outage.
    foreach (range(1, 50) as $ignored) {
        Queue::pushOn('sync', new DispatchPublisherPostback(new Conversion));
    }

    $this->artisan('alerts:check')->assertSuccessful();

    assertNotRaised('queue.depth');
});

it('raises silence on a platform that had traffic yesterday and none for two hours', function () {
    Conversion::factory()->create(['created_at' => now()->subHours(6)]);

    $this->artisan('alerts:check')->assertSuccessful();

    assertRaised('conversions.silent');
});

it('stays quiet while conversions are still arriving', function () {
    Conversion::factory()->create(['created_at' => now()->subMinutes(10)]);

    $this->artisan('alerts:check')->assertSuccessful();

    assertNotRaised('conversions.silent');
});

it('stays quiet on a platform nobody has plugged in yet', function () {
    // No conversions at all — a fresh install, not an outage. Alerting here
    // means every new deployment starts by emailing its operator.
    $this->artisan('alerts:check')->assertSuccessful();

    assertNotRaised('conversions.silent');
});

it('stays quiet on a platform whose last conversion is older than a day', function () {
    Conversion::factory()->create(['created_at' => now()->subDays(3)]);

    $this->artisan('alerts:check')->assertSuccessful();

    assertNotRaised('conversions.silent');
});

it('does not page anyone at four in the morning over a quiet two hours', function () {
    $this->travelTo('2026-08-17 04:00:00');

    Conversion::factory()->create(['created_at' => now()->subHours(6)]);

    $this->artisan('alerts:check')->assertSuccessful();

    // A real outage is still there at 08:00, and the run at 08:00 will say so.
    assertNotRaised('conversions.silent');
});

it('checks every threshold in one run rather than stopping at the first', function () {
    Queue::fake();
    Setting::set('alert_queue_depth', '1');

    postbackAttempts(count: 40, failed: 20);
    Conversion::query()->update(['created_at' => now()->subHours(6)]);
    OutgoingPostback::query()->update(['created_at' => now()->subMinutes(5)]);

    Queue::pushOn('postbacks', new DispatchPublisherPostback(new Conversion));
    Queue::pushOn('postbacks', new DispatchPublisherPostback(new Conversion));

    $this->artisan('alerts:check')->assertSuccessful();

    assertRaised('postback.failure_rate');
    assertRaised('queue.depth');
    assertRaised('conversions.silent');
});

it('raises when the IP intelligence databases are not on disk', function () {
    // Targeting is fail-closed by specification (docs/03 §2), so a box without
    // these files serves zero offers to everyone and reports it nowhere. The
    // whole point of this check is that the silence has a voice.
    config(['advault.geoip_path' => base_path('tests/tmp/geoip-empty')]);

    $this->artisan('alerts:check')->assertSuccessful();

    assertRaised('geoip.missing');
});

it('stays quiet once every database is present', function () {
    $dir = base_path('tests/tmp/geoip-present');

    if (! is_dir($dir)) {
        mkdir($dir, 0o777, true);
    }

    foreach (MaxMindIpIntelligence::DATABASES as $file) {
        file_put_contents($dir.DIRECTORY_SEPARATOR.$file, 'x');
    }

    config(['advault.geoip_path' => $dir]);

    $this->artisan('alerts:check')->assertSuccessful();

    assertNotRaised('geoip.missing');

    foreach (MaxMindIpIntelligence::DATABASES as $file) {
        @unlink($dir.DIRECTORY_SEPARATOR.$file);
    }

    @rmdir($dir);
});
