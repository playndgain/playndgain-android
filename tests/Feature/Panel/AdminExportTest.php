<?php

use App\Enums\ConversionStatus;
use App\Filament\Admin\Resources\Conversions\Pages\ListConversions;
use App\Filament\Admin\Resources\LedgerEntries\Pages\ListLedgerEntries;
use App\Filament\Admin\Resources\Withdrawals\Pages\ListWithdrawals;
use App\Models\Conversion;
use App\Models\LedgerEntry;
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
    Config::set('advault.require_admin_2fa', false);
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
        'payment_details' => json_encode(['email' => 'payee@example.com']),
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

/** Livewire renders a streamed download into the payload, base64-encoded. */
function exportedCsv(Testable $component): string
{
    return (string) base64_decode((string) data_get($component->effects, 'download.content'));
}
