<?php

use App\Enums\ConversionStatus;
use App\Filament\Admin\Resources\Conversions\Pages\ListConversions;
use App\Filament\Admin\Resources\LedgerEntries\Pages\ListLedgerEntries;
use App\Filament\Admin\Resources\NewsletterSubscribers\Pages\ListNewsletterSubscribers;
use App\Filament\Admin\Resources\Withdrawals\Pages\ListWithdrawals;
use App\Models\Conversion;
use App\Models\LedgerEntry;
use App\Models\NewsletterSubscriber;
use App\Models\Publisher;
use App\Models\User;
use App\Models\Withdrawal;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Config;
use Livewire\Features\SupportTesting\Testable;

/**
 * CSV export on conversions, ledger and withdrawals — docs/07 §10.
 *
 * Worth a test rather than a click-through: the rows are built by a closure per
 * column, so a mistyped relation is a 500 the first time an admin exports a
 * quarter — and the stream is only rendered when someone asks for the file.
 */
beforeEach(function () {
    Config::set('playndgain.require_admin_2fa', false);
    Filament::setCurrentPanel('admin');

    $this->actingAs(User::factory()->admin()->create([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_recovery_codes' => ['aaaa-bbbb'],
    ]));
});

it('exports only what the filters left on screen', function () {
    $kept = Conversion::factory()->create(['status' => ConversionStatus::Approved]);
    $filteredOut = Conversion::factory()->create(['status' => ConversionStatus::Rejected]);

    $csv = exportedCsv(Livewire::test(ListConversions::class)
        ->filterTable('status', ConversionStatus::Approved->value)
        ->callAction('export'));

    // An export that ignores the filters is one nobody can reconcile against
    // the screen they exported it from.
    expect($csv)->toContain('trans_id')
        ->toContain($kept->trans_id)
        ->not->toContain($filteredOut->trans_id);
});

it('exports the ledger and the withdrawal queue with their own columns', function () {
    $conversion = Conversion::factory()->create(['status' => ConversionStatus::Approved]);
    LedgerEntry::createEarning($conversion);

    $publisher = Publisher::query()->findOrFail($conversion->publisher_id);

    Withdrawal::create([
        'publisher_id' => $publisher->id,
        'amount' => '25.000000',
        'method' => 'paypal',
        'payment_details' => ['email' => 'payee@example.com'],
        'status' => 'requested',
        'requested_at' => now(),
    ]);

    $ledger = exportedCsv(Livewire::test(ListLedgerEntries::class)->callAction('export'));

    // The running balance is what makes a ledger export checkable at all.
    expect($ledger)->toContain('balance_after')
        ->toContain('earning');

    $withdrawals = exportedCsv(Livewire::test(ListWithdrawals::class)->callAction('export'));

    expect($withdrawals)->toContain('destination')
        ->toContain('paypal')
        // Masked exactly as the table masks it: a spreadsheet of payout
        // addresses is the one artefact of this panel worth stealing.
        ->not->toContain('payee@example.com');
});

it('never puts a working unsubscribe link beside an address that already left', function () {
    NewsletterSubscriber::create(['email' => 'dana@example.com', 'source' => '/']);
    NewsletterSubscriber::create([
        'email' => 'lee@example.com',
        'source' => '/advertise',
        'unsubscribed_at' => now()->subDays(3),
    ]);

    // The "Subscribed only" filter is a toggle, so the operator can clear it —
    // and then the file holds people who left. A link on one of those rows is
    // a mailing waiting to go to somebody who asked not to get it.
    $csv = exportedCsv(Livewire::test(ListNewsletterSubscribers::class)
        ->filterTable('subscribed', false)
        ->callAction('export'));

    expect($csv)->toContain('dana@example.com')
        ->toContain('lee@example.com')
        ->toContain('unsubscribed');

    $rows = array_values(array_filter(explode("\n", $csv), fn (string $line): bool => str_contains($line, '@example.com')));
    $lee = current(array_filter($rows, fn (string $line): bool => str_contains($line, 'lee@')));
    $dana = current(array_filter($rows, fn (string $line): bool => str_contains($line, 'dana@')));

    expect($lee)->not->toContain('/newsletter/unsubscribe/')
        // The one still on the list keeps the link the mailing has to carry.
        ->and($dana)->toContain('/newsletter/unsubscribe/')
        ->toContain('signature=');
});

/** Livewire renders a streamed download into the payload, base64-encoded. */
function exportedCsv(Testable $component): string
{
    return (string) base64_decode((string) data_get($component->effects, 'download.content'));
}
