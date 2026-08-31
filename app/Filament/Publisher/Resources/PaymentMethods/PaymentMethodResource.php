<?php

namespace App\Filament\Publisher\Resources\PaymentMethods;

use App\Enums\PaymentMethodType;
use App\Filament\Publisher\Resources\PaymentMethods\Pages\ListPaymentMethods;
use App\Filament\Publisher\Resources\PublisherScopedResource;
use App\Filament\Publisher\Support\CurrentPublisher;
use App\Models\AuditLog;
use App\Models\PaymentMethod;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * Saved payout destinations — docs/08 §11.
 *
 * Details are masked after save and revealed only on an explicit action.
 * Editing one carries the warning publishers most often get wrong: a withdrawal
 * already requested keeps the destination it was requested with.
 */
class PaymentMethodResource extends PublisherScopedResource
{
    protected static ?string $model = PaymentMethod::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?int $navigationSort = 9;

    protected static ?string $navigationLabel = 'Payment details';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(2)->schema([
                Select::make('type')->options(PaymentMethodType::class)->required()->live(),
                TextInput::make('label')->maxLength(64)->helperText('Your own name for it — "main PayPal".'),

                TextInput::make('details.email')
                    ->label(fn (Get $get): string => self::typeOf($get) === PaymentMethodType::Wise ? 'Wise email' : 'PayPal email')
                    ->email()
                    ->required()
                    ->visible(fn (Get $get): bool => in_array(self::typeOf($get), [PaymentMethodType::Paypal, PaymentMethodType::Wise], true)),

                Select::make('details.asset')
                    ->label('Asset')
                    ->options([
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
                    ])
                    ->required()
                    ->visible(fn (Get $get): bool => self::typeOf($get) === PaymentMethodType::Crypto),
                Select::make('details.network')
                    ->label('Chain')
                    ->options([
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
                    ])
                    ->required()
                    ->visible(fn (Get $get): bool => self::typeOf($get) === PaymentMethodType::Crypto)
                    ->helperText('Send to the wrong chain and the funds are gone. We pay exactly what is set here.'),
                TextInput::make('details.address')
                    ->label('Wallet address')
                    ->required()
                    ->maxLength(191)
                    ->visible(fn (Get $get): bool => self::typeOf($get) === PaymentMethodType::Crypto),

                TextInput::make('details.account_name')->label('Account holder')->required()
                    ->visible(fn (Get $get): bool => self::typeOf($get) === PaymentMethodType::Bank),
                TextInput::make('details.iban')->label('IBAN / account number')->required()->maxLength(64)
                    ->visible(fn (Get $get): bool => self::typeOf($get) === PaymentMethodType::Bank),
                TextInput::make('details.swift')->label('SWIFT / BIC')->maxLength(32)
                    ->visible(fn (Get $get): bool => self::typeOf($get) === PaymentMethodType::Bank),

                Toggle::make('is_default')
                    ->label('Use by default')
                    ->helperText('The end-of-month payout uses this destination.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')->badge(),
                TextColumn::make('label')->placeholder('—'),
                TextColumn::make('destination')->label('Destination')
                    ->state(fn (PaymentMethod $record): string => self::mask($record)),
                IconColumn::make('is_default')->label('Default')->boolean(),
                TextColumn::make('created_at')->label('Added')->date(),
            ])
            ->headerActions([
                CreateAction::make()->mutateDataUsing(function (array $data): array {
                    $data['publisher_id'] = CurrentPublisher::id();

                    // The first method saved becomes the default: a publisher
                    // with one destination and no default is a withdrawal form
                    // with nothing selected.
                    $isFirst = ! PaymentMethod::query()
                        ->where('publisher_id', $data['publisher_id'])
                        ->exists();

                    $data['is_default'] = $isFirst || ($data['is_default'] ?? false);

                    return $data;
                }),
            ])
            ->recordActions([
                EditAction::make()
                    ->modalDescription('Changing this will not affect withdrawals already requested — the destination is snapshotted when you request.')
                    ->after(fn (PaymentMethod $record) => AuditLog::record('payment_method.updated', $record)),
                Action::make('reveal')
                    ->icon(Heroicon::OutlinedEye)
                    ->requiresConfirmation()
                    ->action(function (PaymentMethod $record): void {
                        AuditLog::record('payment_method.revealed', $record);

                        Notification::make()
                            ->title('Payout destination')
                            ->body(collect($record->details)
                                ->map(fn (mixed $value, string $key): string => "{$key}: {$value}")
                                ->implode("\n"))
                            ->persistent()
                            ->send();
                    }),
                Action::make('make_default')
                    ->label('Make default')
                    ->icon(Heroicon::OutlinedStar)
                    ->visible(fn (PaymentMethod $record): bool => ! $record->is_default)
                    ->action(function (PaymentMethod $record): void {
                        PaymentMethod::query()
                            ->where('publisher_id', CurrentPublisher::id())
                            ->update(['is_default' => false]);

                        $record->update(['is_default' => true]);
                    }),
                DeleteAction::make(),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('No payment details yet')
            ->emptyStateDescription('Add one before requesting a withdrawal.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentMethods::route('/'),
        ];
    }

    /** A Select bound to an enum holds the enum, not its value. */
    private static function typeOf(Get $get): ?PaymentMethodType
    {
        $type = $get('type');

        return $type instanceof PaymentMethodType ? $type : PaymentMethodType::tryFrom((string) $type);
    }

    /** `Coco****@gmail.com` — enough to recognise, not enough to reuse. */
    private static function mask(PaymentMethod $record): string
    {
        $details = $record->details;
        $value = (string) ($details['email'] ?? $details['address'] ?? $details['iban'] ?? '');

        if ($value === '') {
            return '—';
        }

        if (str_contains($value, '@')) {
            [$local, $domain] = explode('@', $value, 2);

            return Str::limit($local, 4, '****').'@'.$domain;
        }

        return mb_substr($value, 0, 4).'****'.mb_substr($value, -4);
    }
}
