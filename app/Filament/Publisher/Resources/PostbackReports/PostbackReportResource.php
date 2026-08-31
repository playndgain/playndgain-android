<?php

namespace App\Filament\Publisher\Resources\PostbackReports;

use App\Enums\OutgoingPostbackStatus;
use App\Filament\Publisher\Resources\PostbackReports\Pages\ListPostbackReports;
use App\Filament\Publisher\Support\CurrentPublisher;
use App\Models\OutgoingPostback;
use App\Services\Money\ConversionProcessor;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Delivery log for the publisher's own callbacks — docs/08 §9.
 *
 * This page is the answer to "your postbacks aren't working". It shows the
 * resolved URL with the signature masked, what came back, and how long it took.
 *
 * `outgoing_postbacks` has no publisher column, so scoping goes through the app
 * rather than through PublisherScopedResource.
 */
class PostbackReportResource extends Resource
{
    protected static ?string $model = OutgoingPostback::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static ?int $navigationSort = 7;

    protected static ?string $navigationLabel = 'Postback reports';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereHas('app', fn (Builder $query) => $query->where('publisher_id', CurrentPublisher::id()));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Attempt')->columns(2)->schema([
                TextEntry::make('url')->label('Resolved URL')->columnSpanFull()
                    ->helperText('The signature is masked. Everything else is exactly what we requested.'),
                TextEntry::make('http_status')->label('HTTP status')->placeholder('no response'),
                TextEntry::make('duration_ms')->label('Duration')->suffix(' ms')->placeholder('—'),
                TextEntry::make('attempt'),
                TextEntry::make('next_retry_at')->label('Next retry')->dateTime()->placeholder('not retrying'),
                TextEntry::make('error')->placeholder('—')->columnSpanFull(),
                TextEntry::make('response_body')->label('Response body')->placeholder('(empty)')->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['app:id,name', 'conversion:id,trans_id,click_id']))
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('status')->badge()->color(fn (OutgoingPostbackStatus $state): string => match ($state) {
                    OutgoingPostbackStatus::Success => 'success',
                    OutgoingPostbackStatus::Retrying => 'warning',
                    OutgoingPostbackStatus::Failed => 'danger',
                    OutgoingPostbackStatus::Queued => 'gray',
                }),
                TextColumn::make('app.name')->label('App')->limit(18),
                TextColumn::make('conversion.click_id')->label('Click ID')->limit(14)->copyable()->placeholder('—'),
                TextColumn::make('url')->label('URL')->limit(60)->wrap(),
                TextColumn::make('http_status')->label('HTTP')->placeholder('—'),
                TextColumn::make('attempt')->numeric(),
                TextColumn::make('duration_ms')->label('Duration')->suffix(' ms')->placeholder('—'),
                TextColumn::make('created_at')->label('Created')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('app_id')->label('App')->relationship('app', 'name'),
                SelectFilter::make('status')->options(OutgoingPostbackStatus::class),
                Filter::make('created')
                    ->schema([DatePicker::make('from'), DatePicker::make('until')])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, string $date) => $q->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('resend')
                    ->label('Re-send')
                    ->icon(Heroicon::OutlinedArrowPath)
                    // Failed attempts only — docs/08 §9. A re-send button on a
                    // delivered postback invites a publisher to double-credit
                    // their own users while debugging something else, and one
                    // still retrying does not need help: the queue is already
                    // on it, and a manual attempt only races it.
                    ->visible(fn (OutgoingPostback $record): bool => $record->status === OutgoingPostbackStatus::Failed)
                    ->requiresConfirmation()
                    ->action(function (OutgoingPostback $record): void {
                        $key = "postback-resend:{$record->app_id}";

                        // Ten a minute per app. Enough to debug, not enough to
                        // turn our queue into a load generator for someone
                        // else's endpoint.
                        if (RateLimiter::tooManyAttempts($key, 10)) {
                            Notification::make()
                                ->title('Slow down')
                                ->body('Re-sends are limited to 10 a minute per app. Try again in '.RateLimiter::availableIn($key).'s.')
                                ->warning()
                                ->send();

                            return;
                        }

                        RateLimiter::hit($key);

                        // Taken fresh, not off `$record->conversion`. The table
                        // eager-loads three columns for the click-ID cell, and
                        // a partially selected model answers null for every
                        // column it did not fetch — including `app_id`, which
                        // is how the resend decided there was no postback URL
                        // and quietly queued nothing every single time.
                        $conversion = $record->conversion()->first();
                        $queued = $conversion !== null && app(ConversionProcessor::class)->resendPostback($conversion);

                        Notification::make()
                            ->title($queued ? 'Queued' : 'Nothing to send')
                            ->status($queued ? 'success' : 'warning')
                            ->send();
                    }),
            ])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPostbackReports::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
