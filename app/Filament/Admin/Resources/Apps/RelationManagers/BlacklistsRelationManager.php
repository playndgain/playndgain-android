<?php

namespace App\Filament\Admin\Resources\Apps\RelationManagers;

use App\Enums\BlacklistType;
use App\Filament\Publisher\Support\EligibleOffers;
use App\Models\Blacklist;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class BlacklistsRelationManager extends RelationManager
{
    protected static string $relationship = 'blacklists';

    protected static ?string $title = 'Blacklists';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('value')
            ->columns([
                TextColumn::make('type')->badge(),
                TextColumn::make('value')
                    ->formatStateUsing(fn (Blacklist $record): string => EligibleOffers::label($record)),
                TextColumn::make('reason')->placeholder('—')->wrap(),
                TextColumn::make('created_at')->label('Added')->date()->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')->options(BlacklistType::class),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
