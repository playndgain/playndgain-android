<?php

namespace App\Filament\Admin\Resources\Publishers\RelationManagers;

use App\Enums\ConversionStatus;
use App\Filament\Admin\Support\ConversionColumns;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ConversionsRelationManager extends RelationManager
{
    protected static string $relationship = 'conversions';

    protected static ?string $title = 'Conversions';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('trans_id')
            ->columns(ConversionColumns::forPublisherContext())
            ->filters([
                SelectFilter::make('status')->options(ConversionStatus::class),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
