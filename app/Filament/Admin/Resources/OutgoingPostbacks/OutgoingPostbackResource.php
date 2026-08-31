<?php

namespace App\Filament\Admin\Resources\OutgoingPostbacks;

use App\Enums\OutgoingPostbackKind;
use App\Enums\OutgoingPostbackStatus;
use App\Filament\Admin\Resources\OutgoingPostbacks\Pages\ListOutgoingPostbacks;
use App\Models\OutgoingPostback;
use App\Services\Money\ConversionProcessor;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/** Our calls to publishers, one row per attempt — docs/07 §8. */
class OutgoingPostbackResource extends Resource
{
    protected static ?string $model = OutgoingPostback::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpOnSquare;

    protected static string|\UnitEnum|null $navigationGroup = 'Logs';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Attempt')->columns(2)->schema([
                TextEntry::make('url')->label('URL (redacted)')->columnSpanFull(),
                TextEntry::make('http_status')->label('HTTP status')->placeholder('—'),
                TextEntry::make('duration_ms')->label('Duration')->suffix(' ms')->placeholder('—'),
                TextEntry::make('next_retry_at')->label('Next retry')->dateTime()->placeholder('—'),
                TextEntry::make('error')->placeholder('—'),
                TextEntry::make('response_body')->label('Response body')->placeholder('(empty)')->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['conversion:id,trans_id', 'app:id,name']))
            ->columns([
                TextColumn::make('created_at')->label('Sent')->dateTime()->sortable(),
                TextColumn::make('conversion.trans_id')->label('Conversion')->copyable()->limit(16)->searchable(),
                TextColumn::make('app.name')->label('App')->limit(18),
                TextColumn::make('kind')->badge()
                    ->color(fn (OutgoingPostbackKind $state): string => $state === OutgoingPostbackKind::Reversal ? 'danger' : 'gray'),
                TextColumn::make('attempt')->numeric(),
                TextColumn::make('http_status')->label('HTTP')->placeholder('—'),
                TextColumn::make('duration_ms')->label('Duration')->suffix(' ms')->placeholder('—'),
                TextColumn::make('status')->badge()->color(fn (OutgoingPostbackStatus $state): string => match ($state) {
                    OutgoingPostbackStatus::Success => 'success',
                    OutgoingPostbackStatus::Retrying => 'warning',
                    OutgoingPostbackStatus::Failed => 'danger',
                    OutgoingPostbackStatus::Queued => 'gray',
                }),
            ])
            ->filters([
                SelectFilter::make('app_id')->label('App')->relationship('app', 'name')->searchable(),
                SelectFilter::make('status')->options(OutgoingPostbackStatus::class),
                SelectFilter::make('kind')->options(OutgoingPostbackKind::class),
                SelectFilter::make('http_status')->label('HTTP status')->options(fn (): array => OutgoingPostback::query()
                    ->whereNotNull('http_status')
                    ->distinct()
                    ->orderBy('http_status')
                    ->pluck('http_status', 'http_status')
                    ->all()),
            ])
            ->recordActions([
                // The infolist above is only reachable through this. A modal
                // rather than a page: there is nothing on the detail worth a URL
                // of its own, and a page would be a second file to keep in step.
                ViewAction::make(),
                Action::make('resend')
                    ->label('Re-send')
                    ->icon(Heroicon::OutlinedArrowPath)
                    // A failed attempt is the only one worth re-sending. A
                    // `retrying` row is already coming back on its own, and
                    // re-sending a success posts the same conversion twice.
                    ->visible(fn (OutgoingPostback $record): bool => $record->status === OutgoingPostbackStatus::Failed)
                    ->requiresConfirmation()
                    ->action(function (OutgoingPostback $record): void {
                        $conversion = $record->conversion;

                        $queued = $conversion !== null
                            && app(ConversionProcessor::class)->resendPostback($conversion, Auth::user());

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
            'index' => ListOutgoingPostbacks::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
