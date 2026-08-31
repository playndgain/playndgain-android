<?php

namespace App\Filament\Admin\Resources\Apps\RelationManagers;

use App\Enums\ConversionStatus;
use App\Filament\Admin\Resources\Conversions\ConversionResource;
use App\Filament\Admin\Support\ConversionColumns;
use App\Models\Conversion;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ConversionsRelationManager extends RelationManager
{
    protected static string $relationship = 'conversions';

    protected static ?string $title = 'Conversions';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('trans_id')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['network:id,name']))
            ->columns(ConversionColumns::forPublisherContext())
            ->filters([
                SelectFilter::make('status')->options(ConversionStatus::class),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn (Conversion $record): string => ConversionResource::getUrl('view', ['record' => $record])),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([])
            ->toolbarActions([]);
    }
}
