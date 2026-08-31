<?php

namespace App\Filament\Publisher\Resources\OfferClicks;

use App\Enums\DeviceType;
use App\Filament\Publisher\Resources\OfferClicks\Pages\ListOfferClicks;
use App\Filament\Publisher\Resources\PublisherScopedResource;
use App\Models\OfferClick;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
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
 * The publisher's own click log — docs/08 §8.
 *
 * IPs are masked here when the app has `anonymize_ip` on. The publisher's own
 * privacy setting applies to the publisher's own view: a toggle that only
 * protects users from everyone except the person who set it is not a privacy
 * control.
 */
class OfferClickResource extends PublisherScopedResource
{
    protected static ?string $model = OfferClick::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCursorArrowRays;

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Offerwall clicks';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['app:id,name,anonymize_ip', 'offer:id,name']))
            ->columns([
                TextColumn::make('created_at')->label('Time')->dateTime()->sortable(),
                TextColumn::make('app.name')->label('App')->limit(18),
                TextColumn::make('click_id')->label('Click ID')->copyable()->limit(14)->searchable(),
                // A click with no offer is an embedded partner wall open (docs/03 §2).
                // The supplier name is ours; the publisher sees that it was
                // the iframe, not which company hosted it.
                TextColumn::make('offer.name')->label('Offer')->limit(28)->placeholder('Embedded wall')
                    ->getStateUsing(fn (OfferClick $record): ?string => $record->offer?->name),
                TextColumn::make('end_user_id')->label('End user')->limit(20)->searchable(),
                TextColumn::make('ip')
                    ->label('IP')
                    ->state(fn (OfferClick $record): string => self::ip($record))
                    ->description(fn (OfferClick $record): string => $record->country ?? '??'),
                TextColumn::make('device_type')->label('Device')->badge()->placeholder('—'),
                IconColumn::make('converted')->label('Converted')->boolean()
                    ->state(fn (OfferClick $record): bool => $record->converted_at !== null),
            ])
            ->filters([
                SelectFilter::make('app_id')->label('App')->relationship('app', 'name'),
                // What the Offers page's "View performance" links into. Clicks
                // are the only surface with an offer dimension — `daily_stats`
                // aggregates without one — so this filter is the whole answer to
                // "how is that offer doing for me".
                SelectFilter::make('offer_id')
                    ->label('Offer')
                    ->relationship('offer', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('device_type')->label('Device')->options(DeviceType::class),
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
                        // High volume. The default range is a filter the
                        // publisher can see and clear, not a hidden cap.
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

    private static function ip(OfferClick $record): string
    {
        $ip = (string) $record->ip;

        if ($record->app?->anonymize_ip !== true) {
            return $ip;
        }

        return str_contains($ip, ':')
            ? implode(':', array_slice(explode(':', $ip), 0, 4)).'::'
            : preg_replace('/\.\d+$/', '.0', $ip) ?? $ip;
    }
}
