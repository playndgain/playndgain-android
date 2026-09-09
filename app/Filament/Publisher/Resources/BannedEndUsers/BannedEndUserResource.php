<?php

namespace App\Filament\Publisher\Resources\BannedEndUsers;

use App\Enums\BannedBy;
use App\Filament\Publisher\Resources\BannedEndUsers\Pages\ListBannedEndUsers;
use App\Filament\Publisher\Resources\PublisherScopedResource;
use App\Filament\Publisher\Support\CurrentPublisher;
use App\Filament\Support\AppColumns;
use App\Models\App;
use App\Models\BannedEndUser;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The publisher's own end-user bans — docs/08 §13.
 *
 * System bans are visible but not removable here. An auto-ban the publisher can
 * lift is not a control, and the fraud engine issues them for a reason.
 */
class BannedEndUserResource extends PublisherScopedResource
{
    protected static ?string $model = BannedEndUser::class;

    protected static string|BackedEnum|null $navigationIcon = 'lucide-user-x';

    protected static string|\UnitEnum|null $navigationGroup = 'Protection';

    protected static ?int $navigationSort = 41;

    protected static ?string $navigationLabel = 'Banned users';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(2)->schema([
                TextInput::make('end_user_id')
                    ->label('End user ID')
                    ->required()
                    ->maxLength(191)
                    ->helperText('The same ID you pass into the wall.'),
                Select::make('app_id')
                    ->label('Applies to')
                    ->placeholder('All apps')
                    ->options(fn (): array => App::query()
                        ->where('publisher_id', CurrentPublisher::id())
                        ->pluck('name', 'id')
                        ->all()),
                Textarea::make('reason')->rows(2)->maxLength(191),
                DateTimePicker::make('expires_at')
                    ->label('Expires')
                    ->helperText('Leave blank for a permanent ban.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(AppColumns::eager()))
            ->columns([
                TextColumn::make('end_user_id')->label('End user')->searchable()->copyable(),
                AppColumns::show()->placeholder('All apps'),
                TextColumn::make('reason')->placeholder('—')->wrap()->limit(60),
                TextColumn::make('banned_by')->label('Banned by')->badge()
                    ->formatStateUsing(fn (BannedBy $state): string => $state === BannedBy::System ? 'system' : 'you')
                    ->color(fn (BannedBy $state): string => $state === BannedBy::System ? 'warning' : 'gray'),
                TextColumn::make('expires_at')->label('Expires')->dateTime()->placeholder('permanent'),
                TextColumn::make('created_at')->label('Banned')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('banned_by')->label('Banned by')->options(BannedBy::class),
                SelectFilter::make('app_id')->label('App')->relationship('app', 'name'),
            ])
            ->headerActions([
                CreateAction::make()->mutateDataUsing(function (array $data): array {
                    $data['publisher_id'] = CurrentPublisher::id();
                    $data['banned_by'] = BannedBy::Publisher;

                    return $data;
                }),
                Action::make('bulk_ban')
                    ->label('Ban a list')
                    ->icon(Heroicon::OutlinedClipboardDocument)
                    ->schema([
                        Textarea::make('ids')
                            ->label('End user IDs')
                            ->required()
                            ->rows(6)
                            ->helperText('One per line, or comma separated.'),
                        Select::make('app_id')
                            ->label('Applies to')
                            ->placeholder('All apps')
                            ->options(fn (): array => App::query()
                                ->where('publisher_id', CurrentPublisher::id())
                                ->pluck('name', 'id')
                                ->all()),
                        Textarea::make('reason')->rows(2)->maxLength(191),
                    ])
                    ->action(function (array $data): void {
                        $ids = collect(preg_split('/[\s,]+/', (string) $data['ids']) ?: [])
                            ->filter()
                            ->unique();

                        foreach ($ids as $id) {
                            // updateOrCreate against the unique index: pasting a
                            // list twice must not throw halfway through.
                            BannedEndUser::query()->updateOrCreate([
                                'publisher_id' => CurrentPublisher::id(),
                                'app_id' => $data['app_id'] ?? null,
                                'end_user_id' => $id,
                            ], [
                                'banned_by' => BannedBy::Publisher,
                                'reason' => $data['reason'] ?? null,
                            ]);
                        }

                        Notification::make()->title("Banned {$ids->count()} users")->send();
                    }),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->label('Unban')
                    // System bans stay. The publisher sees why they exist and
                    // can ask, but cannot quietly undo the fraud engine.
                    ->visible(fn (BannedEndUser $record): bool => $record->banned_by === BannedBy::Publisher),
            ])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBannedEndUsers::route('/'),
        ];
    }
}
