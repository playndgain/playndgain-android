<?php

namespace App\Filament\Admin\Resources\Publishers\RelationManagers;

use App\Enums\WithdrawalStatus;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class WithdrawalsRelationManager extends RelationManager
{
    protected static string $relationship = 'withdrawals';

    protected static ?string $title = 'Withdrawals';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('id')->label('#'),
                TextColumn::make('amount')->money('USD')->sortable(),
                TextColumn::make('method')->badge(),
                TextColumn::make('status')->badge()->color(fn (WithdrawalStatus $state): string => match ($state) {
                    WithdrawalStatus::Paid => 'success',
                    WithdrawalStatus::Requested => 'warning',
                    WithdrawalStatus::Processing => 'info',
                    WithdrawalStatus::Rejected => 'danger',
                }),
                TextColumn::make('requested_at')->dateTime()->sortable(),
                TextColumn::make('paid_at')->dateTime()->placeholder('—'),
                TextColumn::make('tx_reference')->label('Reference')->placeholder('—')->copyable(),
            ])
            ->defaultSort('requested_at', 'desc')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
