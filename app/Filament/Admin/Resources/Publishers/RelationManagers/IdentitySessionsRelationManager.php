<?php

namespace App\Filament\Admin\Resources\Publishers\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class IdentitySessionsRelationManager extends RelationManager
{
    protected static string $relationship = 'identitySessions';

    protected static ?string $title = 'Sessions';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
                TextColumn::make('ip')->label('IP')->fontFamily(FontFamily::Mono)->copyable(),
                TextColumn::make('ua_hash')->label('UA hash')->fontFamily(FontFamily::Mono)->copyable()->limit(16),
                TextColumn::make('session_id')->label('Session')->fontFamily(FontFamily::Mono)->placeholder('—')->limit(12),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
