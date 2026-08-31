<?php

namespace App\Filament\Admin\Resources\Apps\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ConsentsRelationManager extends RelationManager
{
    protected static string $relationship = 'consents';

    protected static ?string $title = 'Consents';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('end_user_id')
            ->columns([
                TextColumn::make('end_user_id')->label('End user')->searchable()->copyable(),
                IconColumn::make('granted')->boolean(),
                TextColumn::make('country')->badge()->placeholder('—'),
                TextColumn::make('ip')->label('IP')->searchable(),
                TextColumn::make('purposes')->badge()->placeholder('—'),
                TextColumn::make('updated_at')->label('Updated')->dateTime()->sortable(),
            ])
            ->filters([
                TernaryFilter::make('granted'),
            ])
            ->defaultSort('updated_at', 'desc')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
