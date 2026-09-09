<?php

use App\Enums\LedgerEntryType;
use App\Enums\PaymentMethodType;
use App\Enums\PublisherStatus;
use App\Enums\WithdrawalStatus;
use App\Mail\MonthlyPayoutErrorsMail;
use App\Mail\MonthlyPayoutSkippedMail;
use App\Models\LedgerEntry;
use App\Models\PaymentMethod;
use App\Models\Publisher;
use App\Models\Setting;
use App\Models\Withdrawal;
use Illuminate\Support\Facades\Mail;

/**
 * End-of-month auto-request: default payment method, full withdrawable, and
 * an error mail when a publisher fails the payout rules.
 */
function monthlyPayable(string $cleared = '100.000000', array $publisher = [], bool $withMethod = true): Publisher
{
    $owner = Publisher::factory()->create(['status' => PublisherStatus::Approved, ...$publisher]);

    LedgerEntry::create([
        'publisher_id' => $owner->id,
        'type' => LedgerEntryType::Earning,
        'amount' => $cleared,
        'balance_after' => $cleared,
        'available_at' => now()->subDay(),
        'created_at' => now()->subDay(),
    ]);

    $owner->update(['balance' => $cleared, 'lifetime_earnings' => $cleared]);

    if ($withMethod) {
        PaymentMethod::create([
            'publisher_id' => $owner->id,
            'type' => PaymentMethodType::Paypal,
            'details' => ['email' => 'payee@example.com'],
            'is_default' => true,
        ]);
    }

    return $owner->fresh();
}

it('requests the full withdrawable balance on the default method', function () {
    Mail::fake();

    $publisher = monthlyPayable('80.000000');

    $this->artisan('withdrawals:request-monthly')->assertSuccessful();

    $withdrawal = Withdrawal::query()->where('publisher_id', $publisher->id)->sole();

    expect($withdrawal->status)->toBe(WithdrawalStatus::Requested)
        ->and($withdrawal->amount)->toBe('80.000000')
        ->and($withdrawal->method)->toBe(PaymentMethodType::Paypal);

    Mail::assertNotQueued(MonthlyPayoutSkippedMail::class);
    Mail::assertNotQueued(MonthlyPayoutErrorsMail::class);
});

it('is a no-op on a second run in the same month', function () {
    Mail::fake();

    $publisher = monthlyPayable();

    $this->artisan('withdrawals:request-monthly')->assertSuccessful();
    $this->artisan('withdrawals:request-monthly')->assertSuccessful();

    expect(Withdrawal::query()->where('publisher_id', $publisher->id)->count())->toBe(1);
    Mail::assertNothingQueued();
});

it('ignores publishers who are not approved', function () {
    Mail::fake();

    monthlyPayable('100.000000', ['status' => PublisherStatus::Pending]);

    $this->artisan('withdrawals:request-monthly')->assertSuccessful();

    expect(Withdrawal::query()->count())->toBe(0);
    Mail::assertNothingQueued();
});

it('emails the publisher and ops when the payout rules fail', function () {
    Mail::fake();
    Setting::set('alert_emails', json_encode(['ops@playndgain.com']));

    $publisher = monthlyPayable('100.000000', withMethod: false);

    $this->artisan('withdrawals:request-monthly')->assertSuccessful();

    expect(Withdrawal::query()->count())->toBe(0);

    Mail::assertQueued(MonthlyPayoutSkippedMail::class, function (MonthlyPayoutSkippedMail $mail) use ($publisher): bool {
        return $mail->hasTo((string) $publisher->user->email)
            && str_contains($mail->reason, 'payment method');
    });

    Mail::assertQueued(MonthlyPayoutErrorsMail::class, function (MonthlyPayoutErrorsMail $mail) use ($publisher): bool {
        return $mail->hasTo('ops@playndgain.com')
            && collect($mail->failures)->contains(
                fn (array $row): bool => $row['pub_id'] === $publisher->pub_id
                    && str_contains($row['reason'], 'payment method'),
            );
    });
});

it('emails when there is no default method even if another method exists', function () {
    Mail::fake();
    Setting::set('alert_emails', json_encode(['ops@playndgain.com']));

    $publisher = monthlyPayable();
    PaymentMethod::query()->where('publisher_id', $publisher->id)->update(['is_default' => false]);

    $this->artisan('withdrawals:request-monthly')->assertSuccessful();

    expect(Withdrawal::query()->count())->toBe(0);

    Mail::assertQueued(MonthlyPayoutSkippedMail::class, fn (MonthlyPayoutSkippedMail $mail): bool => str_contains($mail->reason, 'default'));
});
