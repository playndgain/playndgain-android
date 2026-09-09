<?php

use App\Enums\LedgerEntryType;
use App\Enums\PaymentMethodType;
use App\Enums\PublisherStatus;
use App\Mail\CreditNoteMail;
use App\Models\LedgerEntry;
use App\Models\PaymentMethod;
use App\Models\Publisher;
use App\Models\User;
use App\Services\Invoices\CreditNoteBuilder;
use App\Services\Money\WithdrawalService;
use App\Support\Identity;
use Illuminate\Support\Facades\Mail;

function invoicePayable(): Publisher
{
    $owner = Publisher::factory()->create(['status' => PublisherStatus::Approved]);

    LedgerEntry::create([
        'publisher_id' => $owner->id,
        'type' => LedgerEntryType::Earning,
        'amount' => '40.000000',
        'balance_after' => '40.000000',
        'available_at' => now()->subDay(),
        'created_at' => now()->subDay(),
    ]);

    $owner->update(['balance' => '40.000000', 'lifetime_earnings' => '40.000000']);

    PaymentMethod::create([
        'publisher_id' => $owner->id,
        'type' => PaymentMethodType::Paypal,
        'details' => ['email' => 'payee@example.com'],
        'is_default' => true,
    ]);

    return $owner->fresh();
}

it('queues a credit-note pdf when a withdrawal is marked paid', function () {
    Mail::fake();

    $publisher = invoicePayable();
    $method = PaymentMethod::query()->where('publisher_id', $publisher->id)->firstOrFail();
    $service = app(WithdrawalService::class);
    $admin = User::factory()->admin()->create();

    $withdrawal = $service->request($publisher, '40.00', $method);
    $service->approve($withdrawal, $admin);
    $service->markPaid($withdrawal->fresh(), 'TX-88', $admin);

    Mail::assertQueued(CreditNoteMail::class, function (CreditNoteMail $mail) use ($publisher, $withdrawal): bool {
        return $mail->hasTo((string) $publisher->user->email)
            && $mail->note->number === 'CN-'.str_pad((string) $withdrawal->id, 5, '0', STR_PAD_LEFT);
    });
});

it('does not send a credit note on approve or reject', function () {
    Mail::fake();

    $publisher = invoicePayable();
    $method = PaymentMethod::query()->where('publisher_id', $publisher->id)->firstOrFail();
    $service = app(WithdrawalService::class);
    $admin = User::factory()->admin()->create();

    $withdrawal = $service->request($publisher, '40.00', $method);
    $service->approve($withdrawal, $admin);

    Mail::assertNothingQueued();

    $service->reject($withdrawal->fresh(), 'not this time', $admin);

    Mail::assertNothingQueued();
});

it('renders a branded credit-note mail with a pdf attached', function () {
    $publisher = invoicePayable();
    $method = PaymentMethod::query()->where('publisher_id', $publisher->id)->firstOrFail();
    $service = app(WithdrawalService::class);
    $admin = User::factory()->admin()->create();

    $withdrawal = $service->request($publisher, '40.00', $method);
    $service->approve($withdrawal, $admin);
    $paid = $service->markPaid($withdrawal->fresh(), 'TX-88', $admin);

    $note = app(CreditNoteBuilder::class)->fromWithdrawal($paid->fresh());
    $mail = new CreditNoteMail($note);

    expect($mail->render())
        ->toContain($note->number)
        ->toContain('#FFB020') // amber-400: the mark and the button
        ->toContain('#6EE7A8') // gain-300: the payout figure in the ink band
        ->toContain('$'.$note->total)
        ->toContain(Identity::name())
        ->and($mail->attachments())->toHaveCount(1)
        ->and($mail->envelope()->subject)->toContain($note->number);
});
