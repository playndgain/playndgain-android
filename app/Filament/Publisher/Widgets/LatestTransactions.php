<?php

namespace App\Filament\Publisher\Widgets;

use App\Enums\ConversionStatus;
use App\Filament\Publisher\Support\CurrentPublisher;
use App\Filament\Publisher\Widgets\Concerns\FiltersBySelectedApp;
use App\Filament\Support\AppColumns;
use App\Models\Conversion;
use App\Services\Money\Money;
use Filament\Support\Enums\FontFamily;
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
                ->with(AppColumns::eager())
                ->latest('created_at')
                ->limit(10))
            ->columns([
                TextColumn::make('created_at')->label('When')->since(),
                TextColumn::make('trans_id')->label('TX')
                    ->copyable()
                    ->copyableState(fn (?string $state): ?string => $state)
                    ->limit(16),
                AppColumns::show()->searchable(false),
                TextColumn::make('offer_name')->label('Offer')->limit(28),
                TextColumn::make('payout')->label('Amount')
                    ->fontFamily(FontFamily::Mono)
                    ->color(fn (Conversion $record): string => $record->status === ConversionStatus::Reversed ? 'loss' : 'gain')
                    ->formatStateUsing(fn (string $state, Conversion $record): string => Money::display(
                        $record->status === ConversionStatus::Reversed ? Money::negate($state) : $state,
                    )),
                TextColumn::make('status')->badge(),
            ])
            ->paginated(false);
    }
}
