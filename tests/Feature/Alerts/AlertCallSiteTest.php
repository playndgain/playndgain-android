<?php

use App\Jobs\DispatchPublisherPostback;
use App\Models\Conversion;
use App\Models\Publisher;
use App\Notifications\AdminAlertNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/AlertFixtures.php';
require_once __DIR__.'/../Money/MoneyFixtures.php';

beforeEach(function () {
    Notification::fake();
    alertRecipients(['ops@playndgain.com']);
});

it('raises ledger drift from the nightly reconcile', function () {
    Queue::fake();

    $click = moneyFixture();
    firePostback(postbackDto($click))->assertOk();

    // A balance written without the entry that justifies it.
    Publisher::query()->whereKey($click->publisher_id)->update(['balance' => '9.910000']);

    $this->artisan('ledger:reconcile')->assertFailed();

    Notification::assertSentOnDemand(
        AdminAlertNotification::class,
        function (AdminAlertNotification $n) use ($click): bool {
            expect($n->key)->toBe('ledger.drift')
                // Drift is the one signal docs/11 §7 rates as a page.
                ->and($n->pages)->toBeTrue()
                ->and($n->context['cached'])->toBe('9.910000')
                ->and($n->subject)->toBe(Publisher::class.'#'.$click->publisher_id);

            return true;
        }
    );
});

it('raises nothing from a reconcile that finds every balance correct', function () {
    Queue::fake();

    $click = moneyFixture();
    firePostback(postbackDto($click))->assertOk();

    $this->artisan('ledger:reconcile')->assertSuccessful();

    Notification::assertNothingSent();
});

it('raises the terminal postback failure the retry contract gives up on', function () {
    $conversion = Conversion::factory()->create();

    (new DispatchPublisherPostback($conversion))->failed(new RuntimeException('publisher unreachable'));

    Notification::assertSentOnDemand(
        AdminAlertNotification::class,
        function (AdminAlertNotification $n) use ($conversion): bool {
            expect($n->key)->toBe('postback.failed')
                // One publisher's endpoint dying is their problem, not a
                // platform emergency — docs/11 §7 keeps the page for the rate.
                ->and($n->pages)->toBeFalse()
                ->and($n->context['trans_id'])->toBe($conversion->trans_id);

            return true;
        }
    );
});
