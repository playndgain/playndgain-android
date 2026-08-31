<?php

namespace App\Filament\Admin\Resources\OfferClicks;

use App\Enums\DeviceType;
use App\Filament\Admin\Resources\OfferClicks\Pages\ListOfferClicks;
use App\Models\OfferClick;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Click log — docs/07 §8.
 *
 * The highest-volume table on the platform. It defaults to the last 24 hours
 * because loading it unfiltered across all history is a query that will hurt,
 * and the default is a filter the admin can see and clear.
 */
class OfferClickResource extends Resource
{
    protected static ?string $model = OfferClick::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCursorArrowRays;

    protected static string|\UnitEnum|null $navigationGroup = 'Logs';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Offerwall clicks';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['app:id,name', 'offer:id,name']))
            ->columns([
                TextColumn::make('created_at')->label('Time')->dateTime()->sortable(),
                TextColumn::make('app.name')->label('App')->limit(18),
                TextColumn::make('click_id')->label('Click ID')->copyable()->limit(14)->searchable(),
                TextColumn::make('offer.name')->label('Offer')->limit(24)->placeholder('—'),
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
                SelectFilter::make('app_id')->label('App')->relationship('app', 'name')->searchable(),
                SelectFilter::make('publisher_id')->label('Publisher')->relationship('publisher', 'company_name')->searchable(),
                SelectFilter::make('device_type')->label('Device')->options(DeviceType::class),
                TernaryFilter::make('blocked'),
                TernaryFilter::make('converted')
                    ->label('Converted')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('converted_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('converted_at'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                Filter::make('country')
                    ->schema([TextInput::make('country')->maxLength(2)])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['country'] ?? null)
                        ? $query->where('country', strtoupper($data['country']))
                        : $query),
                Filter::make('created')
                    ->schema([
                        DatePicker::make('from')->default(now()->subDay()),
                        DatePicker::make('until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, string $date) => $q->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOfferClicks::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /** @return array<int, string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['click_id', 'end_user_id', 'ip'];
    }
}
