<?php

namespace App\Filament\Admin\Resources\Apps\RelationManagers;

use App\Enums\OutgoingPostbackKind;
use App\Enums\OutgoingPostbackStatus;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OutgoingPostbacksRelationManager extends RelationManager
{
    protected static string $relationship = 'outgoingPostbacks';

    protected static ?string $title = 'Postbacks';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['conversion:id,trans_id']))
            ->columns([
                TextColumn::make('created_at')->label('Sent')->dateTime()->sortable(),
                TextColumn::make('conversion.trans_id')->label('Conversion')
                    ->copyable()
                    ->copyableState(fn (?string $state): ?string => $state)
                    ->limit(16)
                    ->searchable(),
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
                SelectFilter::make('status')->options(OutgoingPostbackStatus::class),
                SelectFilter::make('kind')->options(OutgoingPostbackKind::class),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
