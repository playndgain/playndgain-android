<?php

namespace App\Filament\Admin\Resources\Conversions;

use App\Enums\ConversionStatus;
use App\Filament\Admin\Resources\Conversions\Pages\ListConversions;
use App\Filament\Admin\Resources\Conversions\Pages\ViewConversion;
use App\Filament\Admin\Support\ConversionColumns;
use App\Filament\Admin\Support\DateRangeFilter;
use App\Filament\Support\AppColumns;
use App\Models\Conversion;
use App\Services\Money\ConversionProcessor;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Every conversion the platform has ever recorded — docs/07 §6.
 *
 * Approve, reject and force-reverse all go through ConversionProcessor rather
 * than writing here, so an admin decision and an inbound postback produce the
 * same ledger entries and the same publisher callback.
 */
class ConversionResource extends Resource
{
    protected static ?string $model = Conversion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static string|\UnitEnum|null $navigationGroup = 'Money';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'trans_id';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                // company_name is the group title, and a relation loaded without it
                // groups every publisher under a blank heading.
                'publisher:id,pub_id,company_name', AppColumns::eager(), 'network:id,name',
            ]))
            ->columns(ConversionColumns::full())
            // "Which publisher is charging back, and how much" is a group and a
            // status filter, not an export into a spreadsheet. The money columns
            // summarise per group, so the answer is the row total next to the
            // name rather than something anyone has to add up.
            ->groups([
                Group::make('publisher.company_name')->label('Publisher'),
                Group::make('network.name')->label('Network'),
                Group::make('app.name')->label('App'),
                Group::make('offer_name')->label('Offer'),
                Group::make('status')->label('Status'),
                Group::make('created_at')->label('Date')->date(),
            ])
            ->filters([
                SelectFilter::make('status')->options(ConversionStatus::class),
                SelectFilter::make('publisher_id')->label('Publisher')->relationship('publisher', 'company_name')->searchable(),
                SelectFilter::make('app_id')->label('App')->relationship('app', 'name')->searchable(),
                SelectFilter::make('network_id')->label('Network')->relationship('network', 'name'),
                TernaryFilter::make('is_test')->label('Test traffic'),
                Filter::make('country')
                    ->schema([TextInput::make('country')->maxLength(2)])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['country'] ?? null)
                        ? $query->where('country', strtoupper($data['country']))
                        : $query),
                DateRangeFilter::make(),
                Filter::make('payout')
                    ->schema([
                        TextInput::make('min')->numeric()->prefix('$'),
                        TextInput::make('max')->numeric()->prefix('$'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(filled($data['min'] ?? null), fn (Builder $q) => $q->where('payout', '>=', $data['min']))
                        ->when(filled($data['max'] ?? null), fn (Builder $q) => $q->where('payout', '<=', $data['max']))),
            ])
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make([
                    Action::make('approve')
                        ->icon(Heroicon::OutlinedCheckBadge)
                        ->color('success')
                        ->visible(fn (Conversion $record): bool => $record->status === ConversionStatus::Pending)
                        ->requiresConfirmation()
                        ->modalDescription('Writes the ledger entry and fires the publisher postback.')
                        ->action(fn (Conversion $record) => app(ConversionProcessor::class)->approvePending($record, Auth::user())),
                    Action::make('reject')
                        ->icon(Heroicon::OutlinedNoSymbol)
                        ->color('danger')
                        ->visible(fn (Conversion $record): bool => $record->status === ConversionStatus::Pending)
                        ->schema([Textarea::make('reason')->required()->rows(2)->maxLength(191)])
                        ->action(fn (Conversion $record, array $data) => app(ConversionProcessor::class)
                            ->rejectPending($record, $data['reason'], Auth::user())),
                    Action::make('force_reverse')
                        ->label('Force reverse')
                        ->icon(Heroicon::OutlinedArrowUturnLeft)
                        ->color('danger')
                        ->visible(fn (Conversion $record): bool => $record->status !== ConversionStatus::Reversed)
                        ->schema([Textarea::make('reason')->required()->rows(2)->maxLength(191)])
                        ->requiresConfirmation()
                        ->modalDescription('Debits the publisher and sends a reversal callback. This is a chargeback with your name on it.')
                        ->action(fn (Conversion $record, array $data) => app(ConversionProcessor::class)
                            ->forceReverse($record, $data['reason'], Auth::user())),
                    Action::make('resend')
                        ->label('Re-send postback')
                        ->icon(Heroicon::OutlinedPaperAirplane)
                        ->requiresConfirmation()
                        ->action(function (Conversion $record): void {
                            $queued = app(ConversionProcessor::class)->resendPostback($record, Auth::user());

                            Notification::make()
                                ->title($queued ? 'Postback queued' : 'This app has no callback URL')
                                ->status($queued ? 'success' : 'warning')
                                ->send();
                        }),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConversions::route('/'),
            'view' => ViewConversion::route('/{record}'),
        ];
    }

    /**
     * Searching a `trans_id` a publisher pasted into a support email has to land
     * on the conversion in one step — docs/07 §10.
     *
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['trans_id', 'network_txid', 'click_id', 'end_user_id', 'offer_name'];
    }
}
