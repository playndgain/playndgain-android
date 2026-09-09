<?php

namespace App\Filament\Publisher\Support;

use App\Enums\PaymentMethodType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;

/**
 * The fields one payout destination needs, per type — docs/08 §10.
 *
 * One card per type on the Payments page, so nothing here switches on a type
 * select: the caller already knows which type it is rendering.
 */
final class PaymentMethodFields
{
    /** @var array<string, string> */
    public const CRYPTO_ASSETS = [
        'USDT' => 'USDT',
        'USDC' => 'USDC',
        'BTC' => 'BTC',
        'ETH' => 'ETH',
        'LTC' => 'LTC',
        'BCH' => 'BCH',
        'DOGE' => 'DOGE',
        'SOL' => 'SOL',
        'TRX' => 'TRX',
        'BNB' => 'BNB',
        'XRP' => 'XRP',
        'TON' => 'TON',
    ];

    /** @var array<string, string> */
    public const CRYPTO_NETWORKS = [
        'TRC20' => 'TRON (TRC20)',
        'ERC20' => 'Ethereum (ERC20)',
        'BEP20' => 'BSC (BEP20)',
        'BTC' => 'Bitcoin',
        'LTC' => 'Litecoin',
        'BCH' => 'Bitcoin Cash',
        'DOGE' => 'Dogecoin',
        'SOL' => 'Solana',
        'XRP' => 'XRP Ledger',
        'TON' => 'TON',
    ];

    /** @return list<Component> */
    public static function for(PaymentMethodType $type): array
    {
        return match ($type) {
            PaymentMethodType::Paypal => [
                TextInput::make('details.email')->label('PayPal email')->email()->required()->maxLength(191),
            ],
            PaymentMethodType::Wise => [
                TextInput::make('details.email')->label('Wise email')->email()->required()->maxLength(191),
            ],
            PaymentMethodType::Crypto => [
                Select::make('details.asset')
                    ->label('Cryptocurrency')
                    ->options(self::CRYPTO_ASSETS)
                    ->required()
                    ->native(false)
                    ->live(),
                Select::make('details.network')
                    ->label('Network')
                    ->options(self::CRYPTO_NETWORKS)
                    ->required()
                    ->native(false)
                    ->live()
                    ->helperText(fn (Get $get): string => self::networkHint((string) $get('details.network'))),
                TextInput::make('details.address')
                    ->label('Wallet address')
                    ->required()
                    ->maxLength(191)
                    ->columnSpanFull(),
            ],
            PaymentMethodType::Bank => [
                TextInput::make('details.account_name')->label('Account holder')->required()->maxLength(191),
                TextInput::make('details.iban')->label('IBAN / account number')->required()->maxLength(64),
                TextInput::make('details.swift')->label('SWIFT / BIC')->maxLength(32),
            ],
        };
    }

    public static function label(PaymentMethodType $type): string
    {
        return match ($type) {
            PaymentMethodType::Paypal => 'PayPal',
            PaymentMethodType::Crypto => 'Crypto',
            PaymentMethodType::Bank => 'Bank transfer',
            PaymentMethodType::Wise => 'Wise',
        };
    }

    public static function description(PaymentMethodType $type): string
    {
        return match ($type) {
            PaymentMethodType::Paypal => 'Receive payments to a PayPal account',
            PaymentMethodType::Crypto => 'Receive payments via cryptocurrency',
            PaymentMethodType::Bank => 'Receive payments by bank transfer',
            PaymentMethodType::Wise => 'Receive payments to a Wise account',
        };
    }

    /**
     * The one line a tile shows for a saved destination. Long identifiers are
     * middle-truncated: enough to recognise, never enough to retype wrongly.
     *
     * @param  array<string, mixed>  $details
     */
    public static function summary(PaymentMethodType $type, array $details): string
    {
        $value = fn (string $key): string => trim((string) ($details[$key] ?? ''));

        $line = match ($type) {
            PaymentMethodType::Paypal, PaymentMethodType::Wise => $value('email'),
            PaymentMethodType::Crypto => implode(' · ', array_filter([
                $value('asset'),
                self::CRYPTO_NETWORKS[$value('network')] ?? $value('network'),
                self::shorten($value('address')),
            ])),
            PaymentMethodType::Bank => implode(' · ', array_filter([
                $value('account_name'),
                self::shorten($value('iban')),
            ])),
        };

        return $line === '' ? 'Saved, no details' : $line;
    }

    /** "TXk9YbcA…WxYz1" — the head and tail a publisher checks against. */
    private static function shorten(string $value): string
    {
        return mb_strlen($value) > 18
            ? mb_substr($value, 0, 8).'…'.mb_substr($value, -5)
            : $value;
    }

    /**
     * Send to the wrong chain and the funds are gone. We pay exactly what is
     * set here — so the chain is spelled out under the select.
     */
    private static function networkHint(string $network): string
    {
        $name = self::CRYPTO_NETWORKS[$network] ?? null;

        return $name === null
            ? 'We pay exactly to the chain set here. Sending to the wrong chain loses the funds.'
            : "Network: {$name}. We pay exactly to this chain — a wrong chain loses the funds.";
    }
}
