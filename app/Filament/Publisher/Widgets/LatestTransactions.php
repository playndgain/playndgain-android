<?php

namespace App\Filament\Publisher\Widgets;

use App\Enums\ConversionStatus;
use App\Filament\Publisher\Support\CurrentPublisher;
use App\Filament\Publisher\Widgets\Concerns\FiltersBySelectedApp;
use App\Models\Conversion;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/** The last ten transactions, reversals included and marked as such. */
class LatestTransactions extends TableWidget
{
    use FiltersBySelectedApp;

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Latest transactions')
            ->query(fn (): Builder => Conversion::query()
                ->where('publisher_id', CurrentPublisher::id())
                ->when($this->selectedAppId(), fn (Builder $query, int $appId): Builder => $query->where('app_id', $appId))
                ->with('app:id,name')
                ->latest('created_at')
                ->limit(10))
            ->columns([
                TextColumn::make('created_at')->label('When')->since(),
                TextColumn::make('trans_id')->label('TX')->copyable()->limit(16),
                TextColumn::make('app.name')->label('App')->limit(18),
                TextColumn::make('offer_name')->label('Offer')->limit(28),
                TextColumn::make('payout')->label('Amount')
                    ->money('USD')
                    ->color(fn (Conversion $record): string => $record->status === ConversionStatus::Reversed ? 'danger' : 'success')
                    ->formatStateUsing(fn (string $state, Conversion $record): string => ($record->status === ConversionStatus::Reversed ? '−$' : '$')
                        .number_format((float) $state, 4)),
                TextColumn::make('status')->badge()->color(fn (ConversionStatus $state): string => match ($state) {
                    ConversionStatus::Approved => 'success',
                    ConversionStatus::Pending => 'warning',
                    ConversionStatus::Reversed => 'danger',
                    ConversionStatus::Rejected => 'gray',
                }),
            ])
            ->paginated(false);
    }
}
