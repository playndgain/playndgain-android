<?php

namespace App\Filament\Admin\Resources\NewsletterSubscribers;

use App\Filament\Admin\Resources\NewsletterSubscribers\Pages\ListNewsletterSubscribers;
use App\Models\NewsletterSubscriber;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The mailing list, which until now was a table nothing read.
 *
 * Read-mostly: addresses arrive from the site footer, never from here, so
 * there is no create form and no edit page. The two things an operator
 * actually needs are the current list as a file they can paste into whatever
 * sends the release note, and the ability to take somebody off by hand when
 * they ask by mail instead of by clicking the link.
 */
class NewsletterSubscriberResource extends Resource
{
    protected static ?string $model = NewsletterSubscriber::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelopeOpen;

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Mailing list';

    protected static ?string $modelLabel = 'subscriber';

    /** The number worth seeing on the sidebar is the one you can mail. */
    public static function getNavigationBadge(): ?string
    {
        return (string) NewsletterSubscriber::query()->whereNull('unsubscribed_at')->count();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('email')->searchable()->copyable()->sortable(),

                // Where the address was given. The footer renders site-wide, so
                // this is the page rather than a constant.
                TextColumn::make('source')->label('Signed up on')->placeholder('—')->searchable(),

                TextColumn::make('created_at')->label('Joined')->dateTime()->sortable(),

                TextColumn::make('unsubscribed_at')->label('Status')->badge()
                    ->formatStateUsing(fn (?object $state): string => $state === null ? 'Subscribed' : 'Unsubscribed')
                    ->color(fn (?object $state): string => $state === null ? 'success' : 'gray')
                    ->description(fn (NewsletterSubscriber $record): ?string => $record->unsubscribed_at?->toFormattedDateString())
                    ->sortable(),

                // Masked at the door — the network part is all that was ever
                // stored. Hidden by default because it is consent evidence, not
                // something to read down a list.
                TextColumn::make('ip')->label('From')->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('subscribed')->label('Subscribed only')->toggle()->default()
                    ->query(fn (Builder $query): Builder => $query->whereNull('unsubscribed_at')),
            ])
            ->recordActions([
                Action::make('unsubscribe')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->requiresConfirmation()
                    ->modalDescription('Takes the address off the list without deleting the row, which is what proves they asked.')
                    ->visible(fn (NewsletterSubscriber $record): bool => $record->isSubscribed())
                    ->action(fn (NewsletterSubscriber $record) => $record->update(['unsubscribed_at' => now()])),

                Action::make('resubscribe')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->requiresConfirmation()
                    ->modalDescription('Only when they have asked to come back. Putting an address on a list it left is what a complaint is made of.')
                    ->visible(fn (NewsletterSubscriber $record): bool => ! $record->isSubscribed())
                    ->action(fn (NewsletterSubscriber $record) => $record->update(['unsubscribed_at' => null])),
            ])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNewsletterSubscribers::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
