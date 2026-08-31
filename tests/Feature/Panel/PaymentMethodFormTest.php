<?php

use App\Enums\PaymentMethodType;
use App\Filament\Publisher\Resources\PaymentMethods\Pages\ListPaymentMethods;
use App\Models\PaymentMethod;
use App\Models\Publisher;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Payment details switch four field sets off one enum, the same shape as the
 * blacklist form — and the same trap: a Select bound to an enum holds the enum,
 * so a comparison against `->value` never matches and every dependent field
 * stays hidden.
 */
beforeEach(function () {
    Filament::setCurrentPanel('publisher');

    $this->publisher = Publisher::factory()->create();

    $this->actingAs($this->publisher->user);
});

it('saves a crypto destination with its chain', function () {
    Livewire::test(ListPaymentMethods::class)
        ->callTableAction('create', data: [
            'type' => PaymentMethodType::Crypto->value,
            'label' => 'cold wallet',
            'details' => [
                'asset' => 'USDT',
                'network' => 'TRC20',
                'address' => 'TXk9YbcAbCdEfGhIjKlMnOpQrStUvWxYz1',
            ],
        ])
        ->assertHasNoTableActionErrors();

    $method = PaymentMethod::query()->firstOrFail();

    expect($method->type)->toBe(PaymentMethodType::Crypto)
        ->and($method->details['network'])->toBe('TRC20')
        // The first destination saved is the default, or the withdrawal form
        // opens with nothing selected.
        ->and($method->is_default)->toBeTrue();
});

it('saves a litecoin destination on its own chain', function () {
    Livewire::test(ListPaymentMethods::class)
        ->callTableAction('create', data: [
            'type' => PaymentMethodType::Crypto->value,
            'label' => 'ltc wallet',
            'details' => [
                'asset' => 'LTC',
                'network' => 'LTC',
                'address' => 'ltc1qxy2kgdygjrsqtzq2n0yrf2493p83kkfjhx0wlh',
            ],
        ])
        ->assertHasNoTableActionErrors();

    $method = PaymentMethod::query()->firstOrFail();

    expect($method->details['asset'])->toBe('LTC')
        ->and($method->details['network'])->toBe('LTC');
});

it('saves a paypal destination', function () {
    Livewire::test(ListPaymentMethods::class)
        ->callTableAction('create', data: [
            'type' => PaymentMethodType::Paypal->value,
            'details' => ['email' => 'payee@example.com'],
        ])
        ->assertHasNoTableActionErrors();

    expect(PaymentMethod::query()->firstOrFail()->details['email'])->toBe('payee@example.com');
});

it('requires the fields the chosen type actually needs', function () {
    Livewire::test(ListPaymentMethods::class)
        ->callTableAction('create', data: [
            'type' => PaymentMethodType::Crypto->value,
            'details' => ['asset' => 'USDT'],
        ])
        ->assertHasTableActionErrors(['details.network', 'details.address']);

    expect(PaymentMethod::query()->count())->toBe(0);
});
