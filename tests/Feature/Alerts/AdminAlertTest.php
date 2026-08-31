<?php

use App\Models\Publisher;
use App\Models\Setting;
use App\Notifications\AdminAlertNotification;
use App\Support\AdminAlert;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/AlertFixtures.php';

it('mails the recipients configured in settings', function () {
    Notification::fake();
    alertRecipients(['ops@advault.io', 'oncall@advault.io']);

    AdminAlert::raise('ledger.drift', null, ['publisher_id' => 7]);

    Notification::assertSentOnDemand(
        AdminAlertNotification::class,
        function (AdminAlertNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool {
            expect($notifiable->routes['mail'])->toBe(['ops@advault.io', 'oncall@advault.io'])
                ->and($notification->key)->toBe('ledger.drift')
                ->and($notification->context)->toBe(['publisher_id' => 7]);

            return true;
        }
    );
});

it('falls back to the configured recipients when the setting is empty', function () {
    Notification::fake();
    config()->set('advault.alerts.recipients', ['fallback@advault.io']);

    AdminAlert::raise('ledger.drift');

    Notification::assertSentOnDemand(
        AdminAlertNotification::class,
        fn (AdminAlertNotification $n, array $channels, AnonymousNotifiable $to): bool => $to->routes['mail'] === ['fallback@advault.io']
    );
});

it('degrades to log-only with no recipients anywhere, and does not throw', function () {
    Notification::fake();
    config()->set('advault.alerts.recipients', []);

    // The log line is the whole contract when nobody is listening — an alert
    // that throws inside the money path it was reporting on is worse than one
    // that only ever lands in the log.
    Log::shouldReceive('critical')->once()->with('admin-alert: ledger.drift', Mockery::any());

    AdminAlert::raise('ledger.drift');

    Notification::assertNothingSent();
});

it('drops a recipient that is not an address rather than handing it to the mailer', function () {
    Notification::fake();
    alertRecipients(['ops@advault.io', 'not an email', '']);

    AdminAlert::raise('ledger.drift');

    Notification::assertSentOnDemand(
        AdminAlertNotification::class,
        fn (AdminAlertNotification $n, array $channels, AnonymousNotifiable $to): bool => $to->routes['mail'] === ['ops@advault.io']
    );
});

it('degrades to log-only when every configured recipient is junk', function () {
    Notification::fake();
    alertRecipients(['definitely-not-an-address']);
    config()->set('advault.alerts.recipients', []);

    AdminAlert::raise('ledger.drift');

    Notification::assertNothingSent();
});

it('sends once per key inside the cooldown window', function () {
    Notification::fake();
    alertRecipients(['ops@advault.io']);

    // The nightly drift run raising the same key for four hundred publishers is
    // exactly the case the cooldown exists for.
    foreach (range(1, 5) as $i) {
        AdminAlert::raise('ledger.drift', null, ['publisher_id' => $i]);
    }

    Notification::assertSentOnDemandTimes(AdminAlertNotification::class, 1);
});

it('logs every occurrence even while the cooldown is suppressing delivery', function () {
    Notification::fake();
    alertRecipients(['ops@advault.io']);

    Log::shouldReceive('critical')->times(5)->with('admin-alert: ledger.drift', Mockery::any());

    foreach (range(1, 5) as $i) {
        AdminAlert::raise('ledger.drift', null, ['publisher_id' => $i]);
    }
});

it('does not let the cooldown on one key suppress a different key', function () {
    Notification::fake();
    alertRecipients(['ops@advault.io']);

    AdminAlert::raise('ledger.drift');
    AdminAlert::raise('postback.failed');

    Notification::assertSentOnDemandTimes(AdminAlertNotification::class, 2);
});

it('sends again once the window the setting names has passed', function () {
    Notification::fake();
    alertRecipients(['ops@advault.io']);
    Setting::set('alert_cooldown_minutes', '15');

    AdminAlert::raise('ledger.drift');
    $this->travel(16)->minutes();
    AdminAlert::raise('ledger.drift');

    Notification::assertSentOnDemandTimes(AdminAlertNotification::class, 2);
});

it('stringifies the subject instead of serialising the model', function () {
    Notification::fake();
    alertRecipients(['ops@advault.io']);

    $publisher = Publisher::factory()->create();

    AdminAlert::raise('ledger.drift', $publisher);

    // A queued notification is a serialised payload in the jobs table. A
    // Network in that constructor would put encrypted credentials there.
    Notification::assertSentOnDemand(
        AdminAlertNotification::class,
        fn (AdminAlertNotification $n): bool => $n->subject === Publisher::class.'#'.$publisher->id
    );
});

it('rates the paging signals from the spec as pages and the rest as email', function () {
    Notification::fake();
    alertRecipients(['ops@advault.io']);

    AdminAlert::raise('ledger.drift');
    AdminAlert::raise('network.catalogue_collapse');

    Notification::assertSentOnDemand(
        AdminAlertNotification::class,
        fn (AdminAlertNotification $n): bool => $n->key === 'ledger.drift' && $n->pages === true
    );

    Notification::assertSentOnDemand(
        AdminAlertNotification::class,
        fn (AdminAlertNotification $n): bool => $n->key === 'network.catalogue_collapse' && $n->pages === false
    );
});

it('says in the subject line which severity it is, without pretending to page', function () {
    $drift = (new AdminAlertNotification('ledger.drift', true))->toMail(new AnonymousNotifiable);
    $collapse = (new AdminAlertNotification('network.catalogue_collapse', false))->toMail(new AnonymousNotifiable);

    expect($drift->subject)->toContain('[PAGE]')
        ->and($drift->subject)->toContain('ledger.drift')
        ->and($collapse->subject)->toContain('[ALERT]')
        // The body says plainly that no pager exists, so nobody reads the
        // alert table and assumes one will fire.
        ->and(implode(' ', $drift->introLines))->toContain('no paging channel')
        ->and(implode(' ', $collapse->introLines))->not->toContain('no paging channel');
});
