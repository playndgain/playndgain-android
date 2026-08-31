<?php

namespace App\Filament\Admin\Resources\Apps\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DailyStatsRelationManager extends RelationManager
{
    protected static string $relationship = 'dailyStats';

    protected static ?string $title = 'Daily stats';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('date')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['network:id,name']))
            ->columns([
                TextColumn::make('date')->date()->sortable(),
                TextColumn::make('network.name')->label('Network')->placeholder('—'),
                TextColumn::make('country')->badge()->placeholder('—'),
                TextColumn::make('clicks')->numeric()->sortable(),
                TextColumn::make('conversions')->label('Conv')->numeric()->sortable(),
                TextColumn::make('reversals')->numeric()->sortable(),
                TextColumn::make('revenue')->money('USD')->sortable(),
                TextColumn::make('payout')->money('USD')->sortable(),
            ])
            ->defaultSort('date', 'desc')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
