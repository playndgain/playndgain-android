<?php

namespace App\Filament\Publisher\Resources\Transactions;

use App\Enums\ConversionStatus;
use App\Filament\Publisher\Resources\PublisherScopedResource;
use App\Filament\Publisher\Resources\Transactions\Pages\ListTransactions;
use App\Filament\Support\AppColumns;
use App\Models\Conversion;
use App\Models\OutgoingPostback;
use App\Services\Money\Money;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\Width;
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

    protected static string|BackedEnum|null $navigationIcon = 'lucide-receipt';

    protected static string|\UnitEnum|null $navigationGroup = 'Activity';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'trans_id';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Transaction')->columnSpanFull()->columns(4)->schema([
                TextEntry::make('trans_id')->label('TX ID')->copyable(),
                TextEntry::make('end_user_id')->label('End user'),
                TextEntry::make('app.name')->label('App'),
                TextEntry::make('offer_name')->label('Offer'),
                TextEntry::make('payout')->label('You earned')->fontFamily(FontFamily::Mono)
                    ->formatStateUsing(fn (string $state): string => Money::display($state)),
                TextEntry::make('currency_amount')->label('User reward'),
                TextEntry::make('status')->badge(),
                TextEntry::make('country')->placeholder('—'),
                TextEntry::make('created_at')->label('Converted')->dateTime(),
                TextEntry::make('clears_at')->label('Clears')->date()->placeholder('—'),
                TextEntry::make('reversed_at')->label('Reversed')->dateTime()->placeholder('—'),
            ]),

            Section::make('Click')
                ->columnSpanFull()
                ->columns(3)
                ->visible(fn (Conversion $record): bool => $record->offerClick !== null)
                ->schema([
                    TextEntry::make('offerClick.click_id')->label('Click ID')->copyable(),
                    TextEntry::make('offerClick.country')->label('Country')->placeholder('—'),
                    TextEntry::make('offerClick.device_type')->label('Device')->placeholder('—'),
                    TextEntry::make('offerClick.created_at')->label('Clicked')->dateTime(),
                ]),

            Section::make('Postback delivery')->columnSpanFull()->columns(3)->schema([
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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                AppColumns::eager(),
                'offerClick:id,click_id,country,device_type,created_at',
            ]))
            ->columns([
                TextColumn::make('trans_id')->label('TX ID')
                    ->copyable()
                    ->copyableState(fn (?string $state): ?string => $state)
                    ->limit(18)
                    ->searchable(),
                AppColumns::show(),
                TextColumn::make('offer_name')->label('Offer')->limit(30)->searchable(),
                TextColumn::make('type')
                    ->label('Type')
                    ->state(fn (Conversion $record): string => $record->status === ConversionStatus::Reversed ? '−' : '+')
                    ->badge()
                    ->color(fn (Conversion $record): string => $record->status === ConversionStatus::Reversed ? 'loss' : 'gain'),
                TextColumn::make('payout')
                    ->label('Amount (USD)')
                    ->sortable()
                    ->fontFamily(FontFamily::Mono)
                    ->formatStateUsing(fn (string $state, Conversion $record): string => Money::display(
                        $record->status === ConversionStatus::Reversed ? Money::negate($state) : $state,
                    ))
                    ->color(fn (Conversion $record): string => $record->status === ConversionStatus::Reversed ? 'loss' : 'gray')
                    ->weight(fn (Conversion $record): string => $record->status === ConversionStatus::Reversed ? 'bold' : 'normal'),
                TextColumn::make('currency_amount')->label('Currency')->numeric(decimalPlaces: 2),
                TextColumn::make('status')->badge(),
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
            ->recordActions([
                ViewAction::make()->slideOver()->modalWidth(Width::TwoExtraLarge),
            ])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTransactions::route('/'),
        ];
    }

    /** @return array<int, string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['trans_id', 'offer_name', 'end_user_id'];
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
