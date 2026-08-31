<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\ConversionStatus;
use App\Filament\Admin\Resources\Conversions\ConversionResource;
use App\Models\Conversion;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/** Row 4 — the last ten conversions, whatever their status. */
class LatestConversions extends TableWidget
{
    protected static ?int $sort = 7;

    protected int|string|array $columnSpan = 2;

    public function table(Table $table): Table
    {
        return $table
            ->heading('Latest conversions')
            ->query(fn (): Builder => Conversion::query()
                ->withoutGlobalScopes()
                ->with(['publisher:id,pub_id', 'app:id,name'])
                ->latest('created_at')
                ->limit(10))
            ->columns([
                TextColumn::make('created_at')->label('When')->since(),
                TextColumn::make('publisher.pub_id')->label('Pub')->prefix('#'),
                TextColumn::make('app.name')->label('App')->limit(16),
                TextColumn::make('offer_name')->label('Offer')->limit(24),
                TextColumn::make('payout')->money('USD'),
                TextColumn::make('status')->badge()->color(fn (ConversionStatus $state): string => match ($state) {
                    ConversionStatus::Approved => 'success',
                    ConversionStatus::Pending => 'warning',
                    ConversionStatus::Reversed => 'danger',
                    ConversionStatus::Rejected => 'gray',
                }),
            ])
            ->recordActions([
                Action::make('view')
                    ->url(fn (Conversion $record): string => ConversionResource::getUrl('view', ['record' => $record])),
            ])
            ->paginated(false);
    }
}
