<?php

namespace App\Filament\Admin\Resources\Publishers\RelationManagers;

use App\Filament\Admin\Resources\Apps\AppResource;
use App\Filament\Support\AppColumns;
use App\Models\App;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AppsRelationManager extends RelationManager
{
    protected static string $relationship = 'apps';

    protected static ?string $title = 'Apps';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount([
                'offerClicks as clicks_7d' => fn (Builder $query) => $query->where('created_at', '>=', now()->subDays(7)),
                'conversions as conversions_7d' => fn (Builder $query) => $query->where('created_at', '>=', now()->subDays(7)),
            ]))
            ->columns([
                AppColumns::identity(),
                AppColumns::platform(),
                AppColumns::status(),
                AppColumns::postback(),
                TextColumn::make('clicks_7d')
                    ->label('Clicks 7d')
                    ->numeric()
                    ->alignEnd()
                    ->fontFamily(FontFamily::Mono),
                TextColumn::make('conversions_7d')
                    ->label('Conv 7d')
                    ->numeric()
                    ->alignEnd()
                    ->fontFamily(FontFamily::Mono),
            ])
            ->recordClasses(AppColumns::recordClasses(...))
            ->headerActions([])
            ->recordActions([
                ViewAction::make()
                    ->url(fn (App $record): string => AppResource::getUrl('view', ['record' => $record])),
                EditAction::make()
                    ->url(fn (App $record): string => AppResource::getUrl('edit', ['record' => $record])),
            ])
            ->toolbarActions([]);
    }
}
