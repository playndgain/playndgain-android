<?php

namespace App\Filament\Admin\Resources\Apps\RelationManagers;

use App\Enums\DeviceType;
use App\Models\OfferClick;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OfferClicksRelationManager extends RelationManager
{
    protected static string $relationship = 'offerClicks';

    protected static ?string $title = 'Offerwall clicks';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('click_id')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['offer:id,name']))
            ->columns([
                TextColumn::make('created_at')->label('Time')->dateTime()->sortable(),
                TextColumn::make('click_id')->label('Click ID')
                    ->copyable()
                    ->copyableState(fn (?string $state): ?string => $state)
                    ->limit(14)
                    ->searchable(),
                TextColumn::make('offer.name')->label('Offer')->limit(24)->placeholder('—'),
                TextColumn::make('end_user_id')->label('End user')->limit(20)->searchable(),
                TextColumn::make('ip')->label('IP')->searchable()
                    ->description(fn (OfferClick $record): string => $record->country ?? '??'),
                TextColumn::make('device_type')->label('Device')->badge()->placeholder('—'),
                TextColumn::make('risk_score')->label('Risk')
                    ->color(fn (int $state): string => match (true) {
                        $state >= 70 => 'danger',
                        $state >= 40 => 'warning',
                        default => 'gray',
                    }),
                IconColumn::make('blocked')->boolean()->falseIcon(null)
                    ->tooltip(fn (OfferClick $record): ?string => $record->blocked_reason),
                IconColumn::make('converted')->label('Converted')->boolean()
                    ->state(fn (OfferClick $record): bool => $record->converted_at !== null),
            ])
            ->filters([
                SelectFilter::make('device_type')->label('Device')->options(DeviceType::class),
                TernaryFilter::make('blocked'),
                TernaryFilter::make('converted')
                    ->label('Converted')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('converted_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('converted_at'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
