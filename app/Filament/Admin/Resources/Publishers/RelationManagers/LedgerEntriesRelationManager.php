<?php

namespace App\Filament\Admin\Resources\Publishers\RelationManagers;

use App\Enums\LedgerEntryType;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Read-only, like every other view of the ledger. */
class LedgerEntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'ledgerEntries';

    protected static ?string $title = 'Ledger';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->columns([
                TextColumn::make('created_at')->label('Date')->dateTime()->sortable(),
                TextColumn::make('type')->badge()->color(fn (LedgerEntryType $state): string => match ($state) {
                    LedgerEntryType::Earning, LedgerEntryType::Referral => 'success',
                    LedgerEntryType::Chargeback => 'danger',
                    LedgerEntryType::Withdrawal => 'warning',
                    LedgerEntryType::Adjustment => 'info',
                }),
                TextColumn::make('amount')->money('USD')
                    ->color(fn (string $state): string => (float) $state < 0 ? 'danger' : 'success'),
                TextColumn::make('balance_after')->label('Balance after')->money('USD'),
                TextColumn::make('available_at')->label('Clears')->date()->placeholder('immediately'),
                TextColumn::make('description')->limit(50)->wrap(),
                TextColumn::make('creator.name')->label('By')->placeholder('system'),
            ])
            ->filters([
                SelectFilter::make('type')->options(LedgerEntryType::class),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
