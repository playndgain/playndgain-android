<?php

use App\Enums\AppStatus;
use App\Enums\PublisherStatus;
use App\Filament\Admin\Pages\SignupQueue;
use App\Filament\Admin\Resources\Apps\Pages\ListApps;
use App\Filament\Admin\Resources\Publishers\Pages\EditPublisher;
use App\Mail\PublisherAccountStatusMail;
use App\Mail\PublisherAppStatusMail;
use App\Models\App;
use App\Models\Publisher;
use App\Models\User;
use App\Services\Publisher\PublisherNotifier;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    Config::set('playndgain.require_admin_2fa', false);
    Filament::setCurrentPanel('admin');

    $this->actingAs(User::factory()->admin()->create([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_recovery_codes' => ['aaaa-bbbb'],
    ]));
});

it('queues account approval mail from the signup queue', function () {
    Mail::fake();

    $publisher = Publisher::factory()->pending()->create();

    Livewire::test(SignupQueue::class)
        ->callTableAction('approve', $publisher)
        ->assertHasNoTableActionErrors();

    Mail::assertQueued(PublisherAccountStatusMail::class, function (PublisherAccountStatusMail $mail) use ($publisher): bool {
        return $mail->publisher->is($publisher)
            && $mail->status === PublisherStatus::Approved;
    });
});

it('queues account rejection mail with a reason from the signup queue', function () {
    Mail::fake();

    $publisher = Publisher::factory()->pending()->create();

    Livewire::test(SignupQueue::class)
        ->mountTableAction('reject', $publisher)
        ->set('mountedActions.0.data.reason', 'Could not verify traffic source')
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    Mail::assertQueued(PublisherAccountStatusMail::class, function (PublisherAccountStatusMail $mail) use ($publisher): bool {
        return $mail->publisher->is($publisher)
            && $mail->status === PublisherStatus::Banned
            && $mail->reason === 'Could not verify traffic source';
    });
});

it('queues app approval mail when an app is approved', function () {
    Mail::fake();

    $app = App::factory()->create(['status' => AppStatus::Pending, 'approved_at' => null]);

    Livewire::test(ListApps::class)
        ->callTableAction('approve', $app)
        ->assertHasNoTableActionErrors();

    Mail::assertQueued(PublisherAppStatusMail::class, function (PublisherAppStatusMail $mail) use ($app): bool {
        return $mail->app->is($app)
            && $mail->status === AppStatus::Active;
    });
});

it('queues app rejection mail with a reason', function () {
    Mail::fake();

    $app = App::factory()->create(['status' => AppStatus::Pending, 'approved_at' => null]);

    Livewire::test(ListApps::class)
        ->mountTableAction('reject', $app)
        ->set('mountedActions.0.data.reason', 'Postback URL unreachable')
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    Mail::assertQueued(PublisherAppStatusMail::class, function (PublisherAppStatusMail $mail) use ($app): bool {
        return $mail->app->is($app)
            && $mail->status === AppStatus::Rejected
            && $mail->reason === 'Postback URL unreachable';
    });
});

it('queues account status mail when status changes on the publisher edit form', function () {
    Mail::fake();

    $publisher = Publisher::factory()->pending()->create();

    Livewire::test(EditPublisher::class, ['record' => $publisher->getRouteKey()])
        ->set('data.status', PublisherStatus::Approved->value)
        ->call('save')
        ->assertHasNoFormErrors();

    Mail::assertQueued(PublisherAccountStatusMail::class, function (PublisherAccountStatusMail $mail) use ($publisher): bool {
        return $mail->publisher->is($publisher)
            && $mail->status === PublisherStatus::Approved;
    });
});

it('respects notification preferences for account status mail', function () {
    Mail::fake();

    $publisher = Publisher::factory()->pending()->create([
        'notification_preferences' => ['account_status' => false],
    ]);

    app(PublisherNotifier::class)->publisherStatusChanged(
        $publisher,
        PublisherStatus::Pending,
        PublisherStatus::Approved,
    );

    Mail::assertNothingQueued();
});

it('respects notification preferences for app status mail', function () {
    Mail::fake();

    $app = App::factory()->create([
        'status' => AppStatus::Pending,
        'approved_at' => null,
        'publisher_id' => Publisher::factory()->create([
            'notification_preferences' => ['app_status' => false],
        ]),
    ]);

    app(PublisherNotifier::class)->appStatusChanged(
        $app,
        AppStatus::Pending,
        AppStatus::Active,
    );

    Mail::assertNothingQueued();
});
