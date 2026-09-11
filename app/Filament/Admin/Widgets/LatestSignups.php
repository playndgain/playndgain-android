<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\PublisherStatus;
use App\Filament\Admin\Resources\Publishers\PublisherResource;
use App\Filament\Tables\Columns\UserColumn;
use App\Models\Publisher;
use Filament\Actions\Action;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/** Row 4 — who applied recently, and whether anyone has looked at them. */
class LatestSignups extends TableWidget
{
    protected static ?int $sort = 8;

    protected int|string|array $columnSpan = 2;

    public function table(Table $table): Table
    {
        return $table
            ->heading('Latest signups')
            ->query(fn (): Builder => Publisher::query()
                ->with('user:id,name,email')
                ->latest('created_at')
                ->limit(10))
            ->columns([
                TextColumn::make('created_at')->label('When')->since(),
                TextColumn::make('pub_id')->label('Pub')->prefix('#')->fontFamily(FontFamily::Mono),
                UserColumn::make('user')->label('Publisher'),
                ViewColumn::make('country')
                    ->label('Geo')
                    ->view('filament.tables.columns.country-flag')
                    ->grow(false),
                ViewColumn::make('status')->view('filament.tables.columns.status-pill'),
            ])
            ->recordClasses(fn (Publisher $record): array => match ($record->status) {
                PublisherStatus::Pending => ['ad-row--pending'],
                PublisherStatus::Suspended => ['ad-row--suspended'],
                PublisherStatus::Banned => ['ad-row--banned'],
                PublisherStatus::Approved => [],
            })
            ->recordActions([
                Action::make('view')
                    ->url(fn (Publisher $record): string => PublisherResource::getUrl('view', ['record' => $record])),
            ])
            ->paginated(false);
    }
}
