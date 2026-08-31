<?php

namespace App\Filament\Publisher\Resources\Transactions;

use App\Enums\ConversionStatus;
use App\Filament\Publisher\Resources\PublisherScopedResource;
use App\Filament\Publisher\Resources\Transactions\Pages\ListTransactions;
use App\Models\Conversion;
use App\Models\OutgoingPostback;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Conversion history — docs/08 §7.
 *
 * Reversals are negative rows in danger red, not a grey minus sign a publisher
 * scrolls past. Hiding a chargeback until someone notices their balance moved
 * is the fastest way to lose the account.
 */
class TransactionResource extends PublisherScopedResource
{
    protected static ?string $model = Conversion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'trans_id';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Transaction')->columns(4)->schema([
                TextEntry::make('trans_id')->label('TX ID')->copyable(),
                TextEntry::make('end_user_id')->label('End user'),
                TextEntry::make('app.name')->label('App'),
                TextEntry::make('offer_name')->label('Offer'),
                TextEntry::make('payout')->label('You earned')->money('USD'),
                TextEntry::make('currency_amount')->label('User reward'),
                TextEntry::make('status')->badge(),
                TextEntry::make('country')->placeholder('—'),
                TextEntry::make('offerClick.created_at')->label('Clicked')->dateTime()->placeholder('—'),
                TextEntry::make('created_at')->label('Converted')->dateTime(),
                TextEntry::make('clears_at')->label('Clears')->date()->placeholder('—'),
                TextEntry::make('reversed_at')->label('Reversed')->dateTime()->placeholder('—'),
            ]),

            Section::make('Postback delivery')->columns(3)->schema([
                TextEntry::make('delivery_status')->label('Status')
                    ->state(fn (Conversion $record): string => self::deliveryStatus($record))
                    ->badge(),
                TextEntry::make('delivery_attempts')->label('Attempts')
                    ->state(fn (Conversion $record): int => $record->outgoingPostbacks()->count()),
                TextEntry::make('delivery_last')->label('Last attempt')
                    ->state(fn (Conversion $record): string => (string) ($record->outgoingPostbacks()
                        ->latest('id')
                        ->value('created_at') ?? '—')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('app:id,name'))
            ->columns([
                TextColumn::make('trans_id')->label('TX ID')->copyable()->limit(18)->searchable(),
                TextColumn::make('app.name')->label('App')->limit(18),
                TextColumn::make('offer_name')->label('Offer')->limit(30)->searchable(),
                TextColumn::make('type')
                    ->label('Type')
                    ->state(fn (Conversion $record): string => $record->status === ConversionStatus::Reversed ? '−' : '+')
                    ->badge()
                    ->color(fn (Conversion $record): string => $record->status === ConversionStatus::Reversed ? 'danger' : 'success'),
                TextColumn::make('payout')
                    ->label('Amount (USD)')
                    ->sortable()
                    ->formatStateUsing(fn (string $state, Conversion $record): string => ($record->status === ConversionStatus::Reversed ? '−$' : '$')
                        .number_format((float) $state, 4))
                    ->color(fn (Conversion $record): string => $record->status === ConversionStatus::Reversed ? 'danger' : 'gray')
                    ->weight(fn (Conversion $record): string => $record->status === ConversionStatus::Reversed ? 'bold' : 'normal'),
                TextColumn::make('currency_amount')->label('Currency')->numeric(decimalPlaces: 2),
                TextColumn::make('status')->badge()->color(fn (ConversionStatus $state): string => match ($state) {
                    ConversionStatus::Approved => 'success',
                    ConversionStatus::Pending => 'warning',
                    ConversionStatus::Reversed => 'danger',
                    ConversionStatus::Rejected => 'gray',
                }),
                TextColumn::make('country')->badge()->placeholder('—'),
                TextColumn::make('created_at')->label('Date')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('app_id')->label('App')->relationship('app', 'name'),
                SelectFilter::make('status')->options(ConversionStatus::class),
                Filter::make('end_user')
                    ->schema([TextInput::make('end_user_id')->label('End user ID')])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['end_user_id'] ?? null)
                        ? $query->where('end_user_id', $data['end_user_id'])
                        : $query),
                Filter::make('country')
                    ->schema([TextInput::make('country')->maxLength(2)])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['country'] ?? null)
                        ? $query->where('country', strtoupper($data['country']))
                        : $query),
                Filter::make('created')
                    ->schema([DatePicker::make('from'), DatePicker::make('until')])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, string $date) => $q->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([ViewAction::make()])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTransactions::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    private static function deliveryStatus(Conversion $record): string
    {
        /** @var ?OutgoingPostback $last */
        $last = $record->outgoingPostbacks()->latest('id')->first();

        if ($last === null) {
            return blank($record->app?->postback_url) ? 'no callback configured' : 'not sent yet';
        }

        return $last->status->value;
    }
}
