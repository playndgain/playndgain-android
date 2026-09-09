<?php

namespace App\Filament\Admin\Resources\Withdrawals;

use App\Enums\ConversionStatus;
use App\Enums\PaymentMethodType;
use App\Enums\WithdrawalStatus;
use App\Filament\Admin\Resources\Withdrawals\Pages\ListWithdrawals;
use App\Filament\Admin\Support\DateRangeFilter;
use App\Models\Publisher;
use App\Models\Withdrawal;
use App\Services\Money\Money;
use App\Services\Money\WithdrawalService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * The payout queue — docs/07 §6.
 *
 * Sorted oldest first, because the publisher who has waited longest is the one
 * about to email. The detail panel carries everything needed to decide, so the
 * decision never requires opening a second tab.
 */
class WithdrawalResource extends Resource
{
    protected static ?string $model = Withdrawal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|\UnitEnum|null $navigationGroup = 'Money';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Payout Destination')->columns(3)->schema([
                TextEntry::make('amount')->label('Amount to Pay')->fontFamily(FontFamily::Mono)
                    ->formatStateUsing(fn (string $state): string => Money::display($state)),
                TextEntry::make('method')->badge(),
                TextEntry::make('status')->badge()->color(fn (WithdrawalStatus $state): string => match ($state) {
                    WithdrawalStatus::Paid => 'success',
                    WithdrawalStatus::Requested => 'warning',
                    WithdrawalStatus::Processing => 'info',
                    WithdrawalStatus::Rejected => 'danger',
                }),

                // Crypto
                TextEntry::make('crypto_asset')
                    ->label('Asset / Coin')
                    ->state(fn (Withdrawal $record): ?string => self::decodeDetails($record)['asset'] ?? null)
                    ->visible(fn (Withdrawal $record): bool => $record->method === PaymentMethodType::Crypto),
                TextEntry::make('crypto_network')
                    ->label('Network / Chain')
                    ->state(fn (Withdrawal $record): ?string => self::decodeDetails($record)['network'] ?? null)
                    ->visible(fn (Withdrawal $record): bool => $record->method === PaymentMethodType::Crypto),
                TextEntry::make('crypto_address')
                    ->label('Wallet Address')
                    ->state(fn (Withdrawal $record): ?string => self::decodeDetails($record)['address'] ?? null)
                    ->copyable()
                    ->copyMessage('Wallet address copied')
                    ->columnSpanFull()
                    ->visible(fn (Withdrawal $record): bool => $record->method === PaymentMethodType::Crypto),

                // PayPal / Wise
                TextEntry::make('email_destination')
                    ->label(fn (Withdrawal $record): string => $record->method === PaymentMethodType::Wise ? 'Wise Email' : 'PayPal Email')
                    ->state(fn (Withdrawal $record): ?string => self::decodeDetails($record)['email'] ?? null)
                    ->copyable()
                    ->copyMessage('Email copied')
                    ->columnSpan(2)
                    ->visible(fn (Withdrawal $record): bool => in_array($record->method, [PaymentMethodType::Paypal, PaymentMethodType::Wise], true)),

                // Bank
                TextEntry::make('bank_account_name')
                    ->label('Account Holder')
                    ->state(fn (Withdrawal $record): ?string => self::decodeDetails($record)['account_name'] ?? null)
                    ->visible(fn (Withdrawal $record): bool => $record->method === PaymentMethodType::Bank),
                TextEntry::make('bank_iban')
                    ->label('IBAN / Account Number')
                    ->state(fn (Withdrawal $record): ?string => self::decodeDetails($record)['iban'] ?? null)
                    ->copyable()
                    ->visible(fn (Withdrawal $record): bool => $record->method === PaymentMethodType::Bank),
                TextEntry::make('bank_swift')
                    ->label('SWIFT / BIC')
                    ->state(fn (Withdrawal $record): ?string => self::decodeDetails($record)['swift'] ?? null)
                    ->copyable()
                    ->visible(fn (Withdrawal $record): bool => $record->method === PaymentMethodType::Bank),

                TextEntry::make('tx_reference')
                    ->label('Transaction Reference')
                    ->placeholder('—')
                    ->copyable()
                    ->visible(fn (Withdrawal $record): bool => filled($record->tx_reference)),
                TextEntry::make('admin_note')
                    ->label('Admin Note')
                    ->placeholder('—')
                    ->columnSpanFull()
                    ->visible(fn (Withdrawal $record): bool => filled($record->admin_note)),
            ]),

            Section::make('Publisher & Fraud Metrics')->columns(4)->schema([
                TextEntry::make('publisher.user.name')->label('Publisher'),
                TextEntry::make('publisher.pub_id')->label('Publisher ID')->prefix('#'),
                TextEntry::make('publisher.lifetime_earnings')->label('Lifetime earnings')->fontFamily(FontFamily::Mono)
                    ->formatStateUsing(fn (string $state): string => Money::display($state)),
                TextEntry::make('account_age')->label('Account age')
                    ->state(fn (Withdrawal $record): string => (string) $record->publisher?->created_at?->diffForHumans()),
                TextEntry::make('reversal_rate')->label('Reversal rate')
                    ->state(fn (Withdrawal $record): string => self::reversalRate($record->publisher)),
                TextEntry::make('conversions_30d')->label('Conversions 30d')
                    ->state(fn (Withdrawal $record): int => $record->publisher?->conversions()
                        ->where('created_at', '>=', now()->subDays(30))
                        ->count() ?? 0),
                TextEntry::make('previous_paid')->label('Previously paid')
                    ->state(fn (Withdrawal $record): string => Money::display($record->publisher?->withdrawals()
                        ->where('status', WithdrawalStatus::Paid)
                        ->sum('amount') ?? 0)),
                TextEntry::make('requested_at')->label('Requested')->dateTime(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('publisher.user:id,name'))
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('publisher.user.name')->label('Publisher')->searchable()
                    ->description(fn (Withdrawal $record): string => '#'.$record->publisher?->pub_id),
                TextColumn::make('amount')->fontFamily(FontFamily::Mono)->sortable()
                    ->formatStateUsing(fn (string $state): string => Money::display($state)),
                TextColumn::make('method')->badge(),
                TextColumn::make('destination')->label('Destination')
                    ->state(fn (Withdrawal $record): string => self::maskDestination($record))
                    ->description(fn (Withdrawal $record): ?string => match ($record->method) {
                        PaymentMethodType::Crypto => ($d = self::decodeDetails($record)) ? trim(($d['asset'] ?? '').' · '.($d['network'] ?? ''), ' ·') : null,
                        default => null,
                    }),
                TextColumn::make('status')->badge()->color(fn (WithdrawalStatus $state): string => match ($state) {
                    WithdrawalStatus::Paid => 'success',
                    WithdrawalStatus::Requested => 'warning',
                    WithdrawalStatus::Processing => 'info',
                    WithdrawalStatus::Rejected => 'danger',
                }),
                TextColumn::make('reversal_rate')->label('Reversals')
                    ->state(fn (Withdrawal $record): string => self::reversalRate($record->publisher)),
                TextColumn::make('account_age')->label('Age')
                    ->state(fn (Withdrawal $record): string => (string) $record->publisher?->created_at?->diffForHumans(short: true)),
                TextColumn::make('requested_at')->label('Requested')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(WithdrawalStatus::class)
                    ->default(WithdrawalStatus::Requested->value),
                SelectFilter::make('publisher_id')->label('Publisher')->relationship('publisher', 'company_name')->searchable(),
                SelectFilter::make('method')->options(PaymentMethodType::class),
                DateRangeFilter::make('requested_at', 'requested'),
            ])
            ->recordActions([
                ViewAction::make()->slideOver(),
                Action::make('approve')
                    ->icon(Heroicon::OutlinedCheckBadge)
                    ->color('success')
                    ->visible(fn (Withdrawal $record): bool => $record->status === WithdrawalStatus::Requested)
                    ->requiresConfirmation()
                    ->modalDescription(fn (Withdrawal $record): string => sprintf(
                        'Moves to processing and writes the negative ledger entry. Pay $%s to %s externally, then mark it paid.',
                        Money::display($record->amount),
                        self::destinationSummary($record),
                    ))
                    ->action(fn (Withdrawal $record) => self::run(fn () => app(WithdrawalService::class)->approve($record, Auth::user()))),
                Action::make('mark_paid')
                    ->label('Mark paid')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (Withdrawal $record): bool => $record->status === WithdrawalStatus::Processing)
                    ->modalDescription(fn (Withdrawal $record): string => sprintf(
                        'Paying $%s to %s. Enter the transaction reference below.',
                        Money::display($record->amount),
                        self::destinationSummary($record),
                    ))
                    ->schema([
                        TextInput::make('tx_reference')->label('Transaction reference')->required()->maxLength(191)
                            ->helperText('The PayPal batch ID, the chain txid, the bank reference. This is what a dispute is answered with.'),
                    ])
                    ->action(fn (Withdrawal $record, array $data) => self::run(
                        fn () => app(WithdrawalService::class)->markPaid($record, $data['tx_reference'], Auth::user())
                    )),
                Action::make('reject')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->visible(fn (Withdrawal $record): bool => in_array($record->status, [WithdrawalStatus::Requested, WithdrawalStatus::Processing], true))
                    ->schema([Textarea::make('reason')->required()->rows(2)->maxLength(191)])
                    ->action(fn (Withdrawal $record, array $data) => self::run(
                        fn () => app(WithdrawalService::class)->reject($record, $data['reason'], Auth::user())
                    )),
            ])
            // Oldest first. Bulk approve is deliberately absent — bulk-approving
            // payouts is exactly the action that should be slightly annoying.
            ->defaultSort('requested_at', 'asc')
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWithdrawals::route('/'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $open = Withdrawal::query()
            ->withoutGlobalScopes()
            ->whereIn('status', [WithdrawalStatus::Requested, WithdrawalStatus::Processing])
            ->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function canCreate(): bool
    {
        // Publishers request; admins decide. An admin-created request would
        // have no publisher behind it and no destination to pay.
        return false;
    }

    /** The service throws on an illegal transition; the panel shows why rather than 500ing. */
    private static function run(callable $callback): void
    {
        try {
            $callback();
        } catch (RuntimeException $e) {
            Notification::make()->title('Not allowed')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title('Done')->success()->send();
    }

    private static function reversalRate(?Publisher $publisher): string
    {
        if ($publisher === null) {
            return '—';
        }

        $total = $publisher->conversions()->count();

        if ($total === 0) {
            return '—';
        }

        $reversed = $publisher->conversions()->where('status', ConversionStatus::Reversed)->count();

        return number_format($reversed / $total * 100, 1).'%';
    }

    /**
     * @return array<string, mixed>
     */
    public static function decodeDetails(Withdrawal $record): array
    {
        return $record->destination();
    }

    public static function destinationSummary(Withdrawal $record): string
    {
        $details = self::decodeDetails($record);

        if (empty($details)) {
            return '—';
        }

        return match ($record->method) {
            PaymentMethodType::Crypto => sprintf('%s (%s) — %s', $details['asset'] ?? '', $details['network'] ?? '', $details['address'] ?? ''),
            PaymentMethodType::Paypal, PaymentMethodType::Wise => (string) ($details['email'] ?? '—'),
            PaymentMethodType::Bank => sprintf('%s · IBAN: %s%s', $details['account_name'] ?? '', $details['iban'] ?? '', isset($details['swift']) ? ' · SWIFT: '.$details['swift'] : ''),
        };
    }

    /**
     * The snapshot taken at request time, never the current payment method —
     * this is where the money was meant to go when it was approved.
     */
    public static function maskDestination(Withdrawal $record): string
    {
        $details = self::decodeDetails($record);

        if (empty($details)) {
            return '—';
        }

        $value = (string) ($details['email'] ?? $details['address'] ?? $details['account'] ?? $details['iban'] ?? '');

        if ($value === '') {
            return '—';
        }

        return mb_substr($value, 0, 4).str_repeat('*', 4).mb_substr($value, -4);
    }
}
