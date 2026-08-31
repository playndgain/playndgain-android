<?php

namespace App\Filament\Admin\Resources\Apps\RelationManagers;

use App\Enums\BannedBy;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class BannedEndUsersRelationManager extends RelationManager
{
    protected static string $relationship = 'bannedEndUsers';

    protected static ?string $title = 'Banned users';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('end_user_id')
            ->columns([
                TextColumn::make('end_user_id')->label('End user')->searchable()->copyable(),
                TextColumn::make('banned_by')->label('By')->badge()
                    ->color(fn (BannedBy $state): string => $state === BannedBy::System ? 'warning' : 'gray'),
                TextColumn::make('reason')->placeholder('—')->wrap()->limit(60),
                TextColumn::make('expires_at')->label('Expires')->dateTime()->placeholder('permanent'),
                TextColumn::make('created_at')->label('Banned')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('banned_by')->label('Banned by')->options(BannedBy::class),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
