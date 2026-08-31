<?php

namespace App\Filament\Admin\Resources\BannedEndUsers;

use App\Enums\BannedBy;
use App\Filament\Admin\Resources\BannedEndUsers\Pages\ListBannedEndUsers;
use App\Models\AuditLog;
use App\Models\BannedEndUser;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** End-user bans across every publisher — docs/07 §7. */
class BannedEndUserResource extends Resource
{
    protected static ?string $model = BannedEndUser::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserMinus;

    protected static string|\UnitEnum|null $navigationGroup = 'Risk';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Banned users';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['publisher:id,pub_id', 'app:id,name']))
            ->columns([
                TextColumn::make('publisher.pub_id')->label('Pub')->prefix('#'),
                TextColumn::make('app.name')->label('App')->placeholder('all apps'),
                TextColumn::make('end_user_id')->label('End user')->searchable()->copyable(),
                TextColumn::make('banned_by')->label('By')->badge()
                    ->color(fn (BannedBy $state): string => $state === BannedBy::System ? 'warning' : 'gray'),
                TextColumn::make('reason')->placeholder('—')->wrap()->limit(60),
                TextColumn::make('expires_at')->label('Expires')->dateTime()->placeholder('permanent'),
                TextColumn::make('created_at')->label('Banned')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('banned_by')->label('Banned by')->options(BannedBy::class),
                SelectFilter::make('publisher_id')->label('Publisher')->relationship('publisher', 'company_name')->searchable(),
                Filter::make('active')->label('Currently active')->toggle()
                    ->query(fn (Builder $query): Builder => $query->where(fn (Builder $q) => $q
                        ->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now()))),
            ])
            ->recordActions([
                Action::make('unban')
                    ->icon(Heroicon::OutlinedLockOpen)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (BannedEndUser $record): void {
                        AuditLog::record('end_user.unbanned', $record, ['end_user_id' => $record->end_user_id]);

                        $record->delete();
                    }),
                Action::make('extend')
                    ->icon(Heroicon::OutlinedClock)
                    ->requiresConfirmation()
                    ->modalDescription('Adds another 7 days from now.')
                    ->visible(fn (BannedEndUser $record): bool => $record->expires_at !== null)
                    ->action(fn (BannedEndUser $record) => $record->update([
                        'expires_at' => now()->addDays(BannedEndUser::AUTO_BAN_DAYS),
                    ])),
                Action::make('permanent')
                    ->label('Make permanent')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->requiresConfirmation()
                    ->visible(fn (BannedEndUser $record): bool => $record->expires_at !== null)
                    ->action(function (BannedEndUser $record): void {
                        $record->update(['expires_at' => null]);

                        AuditLog::record('end_user.ban_made_permanent', $record);
                    }),
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

    public static function canCreate(): bool
    {
        return false;
    }
}
