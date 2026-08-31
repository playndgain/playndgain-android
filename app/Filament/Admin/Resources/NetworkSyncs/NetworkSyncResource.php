<?php

namespace App\Filament\Admin\Resources\NetworkSyncs;

use App\Enums\SyncStatus;
use App\Filament\Admin\Resources\NetworkSyncs\Pages\ListNetworkSyncs;
use App\Models\NetworkSync;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * One row per catalogue run — docs/07 §4.
 *
 * This is where you look when a publisher says the wall went empty: the
 * `networks` table only remembers the last run, and the run that emptied the
 * wall is rarely the last one. Read-only for the same reason the ledger is —
 * an editable history of what happened is not a history.
 */
class NetworkSyncResource extends Resource
{
    protected static ?string $model = NetworkSync::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static string|\UnitEnum|null $navigationGroup = 'Supply';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Sync log';

    protected static ?string $modelLabel = 'sync run';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('network:id,name,driver'))
            ->columns([
                TextColumn::make('network.name')->label('Network')->sortable()
                    ->description(fn (NetworkSync $record): string => (string) $record->network?->driver),
                TextColumn::make('started_at')->label('Started')->dateTime()->sortable(),
                TextColumn::make('duration_ms')->label('Duration')
                    // Seconds once a pull is long enough for milliseconds to stop
                    // meaning anything — a ten-minute run reads as 600000 ms.
                    ->state(fn (NetworkSync $record): string => (int) $record->duration_ms >= 10_000
                        ? number_format((int) $record->duration_ms / 1000, 1).' s'
                        : (int) $record->duration_ms.' ms')
                    ->sortable(),
                TextColumn::make('offers_seen')->label('Offers found')->numeric()->sortable(),
                TextColumn::make('offers_created')->label('New')->numeric()->placeholder('0'),
                TextColumn::make('offers_updated')->label('Updated')->numeric()->placeholder('0'),
                // The number a complaint is about: every one of these left a wall.
                TextColumn::make('offers_deactivated')->label('Deactivated')->numeric()->sortable()
                    ->color(fn (NetworkSync $record): string => (int) $record->offers_deactivated > 0 ? 'warning' : 'gray'),
                TextColumn::make('status')->badge()
                    ->color(fn (SyncStatus $state): string => $state === SyncStatus::Ok ? 'success' : 'danger'),
                TextColumn::make('error')->placeholder('—')->wrap()->limit(80)->searchable(),
            ])
            ->filters([
                SelectFilter::make('network_id')->label('Network')->relationship('network', 'name'),
                SelectFilter::make('status')->options(SyncStatus::class),
            ])
            ->recordActions([])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNetworkSyncs::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
