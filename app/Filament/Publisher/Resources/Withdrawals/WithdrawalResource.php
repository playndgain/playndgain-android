<?php

namespace App\Filament\Publisher\Resources\Withdrawals;

use App\Enums\WithdrawalStatus;
use App\Filament\Publisher\Resources\PublisherScopedResource;
use App\Filament\Publisher\Resources\Withdrawals\Pages\ListWithdrawals;
use App\Models\Withdrawal;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Withdrawal history — docs/08 §10.
 *
 * Requesting is a header action on the list page rather than a create form:
 * there is only ever one open request, and the reason a publisher cannot ask
 * for money right now is more useful than a form that rejects them.
 */
class WithdrawalResource extends PublisherScopedResource
{
    protected static ?string $model = Withdrawal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 8;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('amount')->money('USD')->sortable(),
                TextColumn::make('method')->badge(),
                TextColumn::make('destination')->label('Destination')
                    ->state(fn (Withdrawal $record): string => self::maskDestination($record)),
                TextColumn::make('status')->badge()->color(fn (WithdrawalStatus $state): string => match ($state) {
                    WithdrawalStatus::Paid => 'success',
                    WithdrawalStatus::Requested => 'warning',
                    WithdrawalStatus::Processing => 'info',
                    WithdrawalStatus::Rejected => 'danger',
                })->description(fn (Withdrawal $record): ?string => $record->status === WithdrawalStatus::Rejected
                    ? $record->admin_note
                    : null),
                TextColumn::make('requested_at')->label('Requested')->dateTime()->sortable(),
                TextColumn::make('paid_at')->label('Paid')->dateTime()->placeholder('—'),
                TextColumn::make('tx_reference')->label('Reference')->placeholder('—')->copyable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(WithdrawalStatus::class),
            ])
            ->recordActions([])
            ->toolbarActions([])
            ->defaultSort('requested_at', 'desc')
            ->emptyStateHeading('No withdrawals yet')
            ->emptyStateDescription('Your first payout request will appear here.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWithdrawals::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /** The snapshot from request time — not the payment method as it is now. */
    public static function maskDestination(Withdrawal $record): string
    {
        $details = json_decode((string) $record->payment_details, true);

        if (! is_array($details)) {
            return '—';
        }

        $value = (string) ($details['email'] ?? $details['address'] ?? $details['account'] ?? $details['iban'] ?? '');

        if ($value === '') {
            return '—';
        }

        return mb_substr($value, 0, 4).str_repeat('*', 4).mb_substr($value, -4);
    }
}
