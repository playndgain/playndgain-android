<?php

use App\Enums\PaymentMethodType;
use App\Enums\PayoutStatementStatus;
use App\Enums\PublisherStatus;
use App\Enums\WithdrawalStatus;
use App\Filament\Publisher\Pages\Payments;
use App\Filament\Publisher\Widgets\PaymentsSummary;
use App\Models\PaymentMethod;
use App\Models\PayoutStatement;
use App\Models\Publisher;
use App\Models\Withdrawal;
use Filament\Facades\Filament;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * The Payments page — docs/08 §10: five cards, one card per destination type
 * edited in place with one Active, and the monthly statements with a modal.
 */
beforeEach(function () {
    Filament::setCurrentPanel('publisher');

    $this->publisher = Publisher::factory()->create(['status' => PublisherStatus::Approved]);

    $this->actingAs($this->publisher->user);
});

function paidStatement(Publisher $publisher, string $period = '2026-07-01'): PayoutStatement
{
    $method = PaymentMethod::create([
        'publisher_id' => $publisher->id,
        'type' => PaymentMethodType::Crypto,
        'details' => ['asset' => 'LTC', 'network' => 'LTC', 'address' => 'LfrMRzgH51arp7Jd6HEedJnVqeZkbEQLDH'],
        'is_default' => true,
    ]);

    $withdrawal = Withdrawal::create([
        'publisher_id' => $publisher->id,
        'payment_method_id' => $method->id,
        'amount' => '387.460000',
        'method' => PaymentMethodType::Crypto,
        'payment_details' => $method->details,
        'status' => WithdrawalStatus::Paid,
        'tx_reference' => '09530a4a9937a3f35d088f07dd60d9c1',
        'requested_at' => '2026-08-01 00:10:00',
        'paid_at' => '2026-08-05 09:00:00',
    ]);

    return PayoutStatement::create([
        'publisher_id' => $publisher->id,
        'period' => $period,
        'gross' => '444.950000',
        'chargebacks' => '-57.490000',
        'adjustments' => '0.000000',
        'net' => '387.460000',
        'status' => PayoutStatementStatus::Paid,
        'due_date' => '2026-08-31',
        'withdrawal_id' => $withdrawal->id,
        'minimum_payout' => '20.00',
        'generated_at' => '2026-08-01 00:10:00',
        'paid_at' => '2026-08-05 09:00:00',
    ]);
}

/**
 * Action modals render as a Livewire partial, so the modal's markup is in the
 * response effects rather than in the component's main HTML.
 */
function modalHtml(Testable $component): string
{
    return collect($component->effects['partials'] ?? [])
        ->map(fn (mixed $partial): string => is_array($partial) ? (string) ($partial['html'] ?? implode('', $partial)) : (string) $partial)
        ->implode('');
}

function pendingStatement(Publisher $publisher, string $period = '2026-08-01'): PayoutStatement
{
    return PayoutStatement::create([
        'publisher_id' => $publisher->id,
        'period' => $period,
        'gross' => '120.520000',
        'chargebacks' => '0.000000',
        'adjustments' => '0.000000',
        'net' => '120.520000',
        'status' => PayoutStatementStatus::Pending,
        'due_date' => '2026-09-30',
        'minimum_payout' => '20.00',
        'generated_at' => '2026-09-01 00:10:00',
    ]);
}

it('renders for approved and pending publishers, and redirects the old pages', function () {
    $this->get('/dashboard/payments')->assertSuccessful();

    $this->get('/dashboard/withdrawals')->assertRedirect('/dashboard/payments');
    $this->get('/dashboard/payment-methods')->assertRedirect('/dashboard/payments');

    $this->publisher->update(['status' => PublisherStatus::Pending]);

    $this->get('/dashboard/payments')->assertSuccessful();
});

it('shows the five cards', function () {
    pendingStatement($this->publisher);

    Livewire::test(PaymentsSummary::class)
        ->assertSee('This month')
        ->assertSee('Pending payment')
        ->assertSee('Total paid')
        ->assertSee('Minimum payout')
        ->assertSee('$20.00')
        ->assertSee('Payment terms')
        ->assertSee('Monthly')
        ->assertSee('1 statement awaiting payment')
        ->assertSee('Next due Sep 30, 2026')
        ->assertSee('$0.00');
});

it('lists statements newest first', function () {
    $paid = paidStatement($this->publisher);
    $pending = pendingStatement($this->publisher);

    Livewire::test(Payments::class)
        ->assertCanSeeTableRecords([$pending, $paid], inOrder: true)
        ->assertSee('Aug, 2026')
        ->assertSee('Jul, 2026')
        ->assertSee('$444.95')
        ->assertSee('$387.46');
});

it('shows the destination and transaction id for a paid statement only', function () {
    $paid = paidStatement($this->publisher);
    $pending = pendingStatement($this->publisher);

    $paidModal = modalHtml(Livewire::test(Payments::class)
        ->mountTableAction('view', $paid)
        ->assertActionMounted());

    expect($paidModal)
        ->toContain('Payment breakdown')
        ->toContain('$444.95')
        ->toContain('-$57.49')
        ->toContain('$387.46')
        ->toContain('Wallet address')
        ->toContain('LfrMRzgH51arp7Jd6HEedJnVqeZkbEQLDH')
        ->toContain('Litecoin')
        ->toContain('Transaction ID')
        ->toContain('09530a4a9937a3f35d088f07dd60d9c1')
        ->toContain('ad-status--paid');

    $pendingModal = modalHtml(Livewire::test(Payments::class)
        ->mountTableAction('view', $pending)
        ->assertActionMounted());

    expect($pendingModal)
        ->toContain('Payment breakdown')
        ->toContain('$120.52')
        ->toContain('ad-status--pending')
        ->not->toContain('Wallet address')
        ->not->toContain('Transaction ID');
});

it('saves a crypto destination with its chain and makes the first one active', function () {
    Livewire::test(Payments::class)
        ->callAction('editMethod', [
            'type' => 'crypto',
            'details' => [
                'asset' => 'USDT',
                'network' => 'TRC20',
                'address' => 'TXk9YbcAbCdEfGhIjKlMnOpQrStUvWxYz1',
            ],
        ])
        ->assertHasNoActionErrors();

    $method = PaymentMethod::query()->firstOrFail();

    expect($method->type)->toBe(PaymentMethodType::Crypto)
        ->and($method->details['network'])->toBe('TRC20')
        ->and($method->details['address'])->toBe('TXk9YbcAbCdEfGhIjKlMnOpQrStUvWxYz1')
        ->and($method->is_default)->toBeTrue();
});

it('saves a litecoin destination on its own chain', function () {
    Livewire::test(Payments::class)
        ->callAction('editMethod', [
            'type' => 'crypto',
            'details' => ['asset' => 'LTC', 'network' => 'LTC', 'address' => 'ltc1qxy2kgdygjrsqtzq2n0yrf2493p83kkfjhx0wlh'],
        ])
        ->assertHasNoActionErrors();

    $method = PaymentMethod::query()->firstOrFail();

    expect($method->details['asset'])->toBe('LTC')
        ->and($method->details['network'])->toBe('LTC');
});

it('edits the wallet in place instead of adding a second one', function () {
    Livewire::test(Payments::class)
        ->callAction('editMethod', ['type' => 'crypto', 'details' => ['asset' => 'USDT', 'network' => 'TRC20', 'address' => 'T-one']])
        ->assertHasNoActionErrors()
        ->callAction('editMethod', ['type' => 'crypto', 'details' => ['asset' => 'USDT', 'network' => 'TRC20', 'address' => 'T-two']])
        ->assertHasNoActionErrors();

    expect(PaymentMethod::query()->count())->toBe(1)
        ->and(PaymentMethod::query()->firstOrFail()->details['address'])->toBe('T-two');
});

it('ignores a type that is no longer offered', function () {
    Livewire::test(Payments::class)->call('save', 'wise', ['email' => 'payee@example.com']);

    expect(PaymentMethod::query()->count())->toBe(0);
});

it('requires the fields the type actually needs', function () {
    Livewire::test(Payments::class)
        ->callAction('editMethod', ['type' => 'crypto', 'details' => ['asset' => 'USDT']])
        ->assertHasActionErrors(['details.network', 'details.address']);

    expect(PaymentMethod::query()->count())->toBe(0);
});

it('takes the active flag from a legacy destination when a wallet is saved', function () {
    $wise = PaymentMethod::create([
        'publisher_id' => $this->publisher->id,
        'type' => PaymentMethodType::Wise,
        'details' => ['email' => 'payee@example.com'],
        'is_default' => true,
    ]);

    Livewire::test(Payments::class)
        ->assertSee('no longer offered')
        ->callAction('editMethod', ['type' => 'crypto', 'details' => ['asset' => 'USDT', 'network' => 'TRC20', 'address' => 'TXk9YbcAbCdEfGhIjKlMnOpQrStUvWxYz1']])
        ->assertHasNoActionErrors()
        ->assertDontSee('no longer offered');

    $crypto = PaymentMethod::query()->where('type', PaymentMethodType::Crypto)->firstOrFail();

    expect($crypto->is_default)->toBeTrue()
        ->and($wise->fresh()->is_default)->toBeFalse();
});

it('saves a PayPal destination and makes it the first active one', function () {
    Livewire::test(Payments::class)
        ->assertSee('No payment method')
        ->callAction('editMethod', ['type' => 'paypal', 'details' => ['email' => 'payee@example.com']])
        ->assertHasNoActionErrors()
        ->assertSee('Ready to be paid');

    $paypal = PaymentMethod::query()->where('type', PaymentMethodType::Paypal)->firstOrFail();

    expect($paypal->is_default)->toBeTrue()
        ->and($paypal->details['email'])->toBe('payee@example.com');
});

it('saves a bank destination and switches the active one to it', function () {
    PaymentMethod::create([
        'publisher_id' => $this->publisher->id,
        'type' => PaymentMethodType::Crypto,
        'details' => ['asset' => 'USDT', 'network' => 'TRC20', 'address' => 'TXk9YbcAbCdEfGhIjKlMnOpQrStUvWxYz1'],
        'is_default' => true,
    ]);

    Livewire::test(Payments::class)
        ->callAction('editMethod', ['type' => 'bank', 'details' => [
            'account_name' => 'Jane Roe',
            'iban' => 'DE89370400440532013000',
            'swift' => 'COBADEFF',
        ]], ['activate' => true])
        ->assertHasNoActionErrors();

    $bank = PaymentMethod::query()->where('type', PaymentMethodType::Bank)->firstOrFail();

    expect($bank->is_default)->toBeTrue()
        ->and(PaymentMethod::query()->where('type', PaymentMethodType::Crypto)->value('is_default'))->toBeFalsy();
});

it('saves the billing details a credit note prints', function () {
    Livewire::test(Payments::class)
        ->callAction('editBilling', [
            'company_name' => 'Roe Media',
            'country' => 'DE',
            'billing_address' => '12 Hauptstrasse',
            'billing_city' => 'Berlin',
            'billing_postal_code' => '10115',
            'vat_number' => 'DE123456789',
        ])
        ->assertHasNoActionErrors();

    expect($this->publisher->fresh())
        ->company_name->toBe('Roe Media')
        ->billing_city->toBe('Berlin')
        ->vat_number->toBe('DE123456789');
});

it('reactivates a saved wallet', function () {
    $crypto = PaymentMethod::create([
        'publisher_id' => $this->publisher->id,
        'type' => PaymentMethodType::Crypto,
        'details' => ['asset' => 'USDT', 'network' => 'TRC20', 'address' => 'TXk9YbcAbCdEfGhIjKlMnOpQrStUvWxYz1'],
        'is_default' => false,
    ]);

    Livewire::test(Payments::class)
        ->mountAction('editMethod', ['type' => 'crypto'])
        ->assertActionDataSet(['type' => 'crypto', 'details.address' => 'TXk9YbcAbCdEfGhIjKlMnOpQrStUvWxYz1'])
        ->unmountAction()
        ->call('activate', 'crypto');

    expect($crypto->fresh()->is_default)->toBeTrue();
});

it('keeps a suspended publisher read-only', function () {
    $this->publisher->update(['status' => PublisherStatus::Suspended]);

    Livewire::test(Payments::class)
        ->call('save', 'crypto', ['asset' => 'USDT', 'network' => 'TRC20', 'address' => 'T1'])
        ->assertForbidden();

    expect(PaymentMethod::query()->count())->toBe(0);
});

it('never shows another publisher\'s statements', function () {
    $stranger = Publisher::factory()->create(['status' => PublisherStatus::Approved]);
    pendingStatement($stranger);
    $mine = pendingStatement($this->publisher, '2026-06-01');

    Livewire::test(Payments::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCountTableRecords(1);
});

it('keeps one destination per type', function () {
    Livewire::test(Payments::class)
        ->callAction('editMethod', ['type' => 'crypto', 'details' => ['asset' => 'USDT', 'network' => 'TRC20', 'address' => 'T1']])
        ->assertHasNoActionErrors();

    expect(fn () => PaymentMethod::create([
        'publisher_id' => $this->publisher->id,
        'type' => PaymentMethodType::Crypto,
        'details' => ['asset' => 'BTC', 'network' => 'BTC', 'address' => 'b1'],
        'is_default' => false,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('renders an empty card when a saved destination cannot be decrypted', function () {
    $method = PaymentMethod::create([
        'publisher_id' => $this->publisher->id,
        'type' => PaymentMethodType::Crypto,
        'details' => ['asset' => 'USDT', 'network' => 'TRC20', 'address' => 'T1'],
        'is_default' => true,
    ]);

    // A row written under another APP_KEY: valid-looking ciphertext, wrong MAC.
    DB::table('payment_methods')->where('id', $method->id)->update([
        'details' => (new Encrypter(random_bytes(32), 'AES-256-CBC'))->encryptString(json_encode(['address' => 'lost'])),
    ]);

    Livewire::test(Payments::class)
        ->assertSuccessful()
        ->mountAction('editMethod', ['type' => 'crypto'])
        ->assertActionDataSet(['type' => 'crypto', 'details.address' => null]);

    $this->get('/dashboard/payments')->assertSuccessful();
});

it('flags the sidebar until a default payout method exists', function () {
    expect(Payments::getNavigationBadge())->toBe('!')
        ->and(Payments::getNavigationBadgeColor())->toBe('warning');

    Livewire::test(Payments::class)
        ->callAction('editMethod', ['type' => 'paypal', 'details' => ['email' => 'payee@example.com']])
        ->assertHasNoActionErrors();

    expect(Payments::getNavigationBadge())->toBeNull();
});

it('opens the details modal from a tile', function () {
    Livewire::test(Payments::class)
        ->call('openMethod', 'bank')
        ->assertActionMounted('editMethod');

    Livewire::test(Payments::class)
        ->call('openBilling')
        ->assertActionMounted('editBilling');
});

it('refuses to make a method the default while billing is incomplete', function () {
    $this->publisher->update(['company_name' => null, 'billing_city' => null]);
    $this->publisher->refresh();

    Livewire::test(Payments::class)
        ->callAction('editMethod', ['type' => 'paypal', 'details' => ['email' => 'payee@example.com']], ['activate' => true])
        ->assertHasNoActionErrors()
        ->assertActionMounted('editBilling')
        ->assertSee('Billing details required');

    $paypal = PaymentMethod::query()->where('type', PaymentMethodType::Paypal)->firstOrFail();

    expect($paypal->details['email'])->toBe('payee@example.com')
        ->and($paypal->is_default)->toBeFalse()
        ->and(Payments::whatIsMissing())->toBe('Billing details incomplete');
});

it('will not save half of a billing address', function () {
    $this->publisher->update([
        'billing_address' => null,
        'billing_city' => null,
        'billing_postal_code' => null,
    ]);

    Livewire::test(Payments::class)
        ->callAction('editBilling', ['company_name' => 'Roe Media', 'country' => 'DE'])
        ->assertHasActionErrors(['billing_address', 'billing_city', 'billing_postal_code']);
});

it('pays once billing and a method are both in place', function () {
    $this->publisher->update(['company_name' => null]);

    Livewire::test(Payments::class)
        ->callAction('editBilling', [
            'company_name' => 'Roe Media',
            'country' => 'DE',
            'billing_address' => '12 Hauptstrasse',
            'billing_city' => 'Berlin',
            'billing_postal_code' => '10115',
        ])
        ->assertHasNoActionErrors()
        ->callAction('editMethod', ['type' => 'paypal', 'details' => ['email' => 'payee@example.com']], ['activate' => true])
        ->assertHasNoActionErrors()
        ->assertSee('Ready to be paid');

    expect(PaymentMethod::query()->where('type', PaymentMethodType::Paypal)->value('is_default'))->toBeTruthy()
        ->and(Payments::whatIsMissing())->toBeNull();
});

it('locks the payment method row until billing is complete', function () {
    $this->publisher->update(['billing_city' => null]);

    Livewire::test(Payments::class)
        ->assertSee('Complete your billing details first')
        ->call('openMethod')
        ->assertActionNotMounted('editMethod')
        ->assertActionMounted('editBilling');
});

it('unlocks the payment method row once billing is complete', function () {
    Livewire::test(Payments::class)
        ->assertDontSee('Complete your billing details first')
        ->call('openMethod')
        ->assertActionMounted('editMethod');
});
