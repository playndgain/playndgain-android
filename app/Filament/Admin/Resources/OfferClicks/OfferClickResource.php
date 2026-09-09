<?php

namespace App\Filament\Admin\Resources\OfferClicks;

use App\Enums\DeviceType;
use App\Filament\Admin\Resources\Conversions\ConversionResource;
use App\Filament\Admin\Resources\OfferClicks\Pages\ListOfferClicks;
use App\Filament\Support\AppColumns;
use App\Models\OfferClick;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
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

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Click')->columnSpanFull()->columns(3)->schema([
                TextEntry::make('click_id')->label('Click ID')->copyable(),
                TextEntry::make('offer.name')->label('Offer')->placeholder('Embedded wall'),
                TextEntry::make('app.name')->label('App'),
                TextEntry::make('network.name')->label('Network'),
                TextEntry::make('end_user_id')->label('End user')->copyable(),
                TextEntry::make('created_at')->label('Clicked')->dateTime(),
                TextEntry::make('converted_at')->label('Converted')->dateTime()->placeholder('—'),
                TextEntry::make('conversion.trans_id')->label('Conversion')
                    ->placeholder('—')
                    ->copyable()
                    ->url(fn (OfferClick $record): ?string => $record->conversion === null
                        ? null
                        : ConversionResource::getUrl('view', ['record' => $record->conversion])),
                IconEntry::make('is_test')->label('Test')->boolean(),
            ]),

            Section::make('Visitor')->columnSpanFull()->columns(3)->schema([
                TextEntry::make('ip')->label('IP')->copyable(),
                TextEntry::make('country')->placeholder('—'),
                TextEntry::make('device_type')->label('Device')->badge()->placeholder('—'),
                TextEntry::make('os')->label('OS')->placeholder('—'),
                TextEntry::make('browser')->placeholder('—'),
                TextEntry::make('referrer')->placeholder('—'),
                TextEntry::make('user_agent')->label('User agent')->placeholder('—')->columnSpanFull(),
            ]),

            Section::make('Risk')->columnSpanFull()->columns(3)->schema([
                TextEntry::make('risk_score')->label('Risk score'),
                IconEntry::make('blocked')->boolean(),
                TextEntry::make('blocked_reason')->label('Blocked reason')->placeholder('—')->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                AppColumns::eager(), 'offer:id,name', 'network:id,name', 'conversion:id,trans_id',
            ]))
            ->columns([
                TextColumn::make('created_at')->label('Time')->dateTime()->sortable(),
                AppColumns::show(),
                TextColumn::make('click_id')->label('Click ID')
                    ->copyable()
                    ->copyableState(fn (?string $state): ?string => $state)
                    ->limit(14)
                    ->searchable(),
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
                SelectFilter::make('offer_id')->label('Offer')->relationship('offer', 'name')->searchable(),
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
                Filter::make('risk')
                    ->schema([
                        TextInput::make('min')->numeric()->label('Min risk'),
                        TextInput::make('max')->numeric()->label('Max risk'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(filled($data['min'] ?? null), fn (Builder $q) => $q->where('risk_score', '>=', $data['min']))
                        ->when(filled($data['max'] ?? null), fn (Builder $q) => $q->where('risk_score', '<=', $data['max']))),
                Filter::make('created')
                    ->schema([
                        DatePicker::make('from')->default(now()->subDay()),
                        DatePicker::make('until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, string $date) => $q->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make()->slideOver()->modalWidth(Width::TwoExtraLarge),
            ])
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
