<?php

namespace App\Filament\Publisher\Resources\Blacklists;

use App\Enums\BlacklistType;
use App\Enums\DeviceType;
use App\Filament\Publisher\Resources\Blacklists\Pages\ListBlacklists;
use App\Filament\Publisher\Resources\PublisherScopedResource;
use App\Filament\Publisher\Support\CurrentPublisher;
use App\Filament\Publisher\Support\EligibleOffers;
use App\Filament\Publisher\Support\PublisherPayout;
use App\Models\App;
use App\Models\Blacklist;
use App\Models\Offer;
use App\Networks\OfferCacheFlusher;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;

/**
 * The publisher's own blocks — docs/08 §12.
 *
 * Saving flushes that app's offer cache immediately, so the change shows on the
 * wall in seconds rather than whenever the cache happens to expire.
 *
 * The impact preview exists because publishers blanket-block and then ask why
 * earnings dropped. Telling them before they save is cheaper than explaining
 * afterwards.
 */
class BlacklistResource extends PublisherScopedResource
{
    protected static ?string $model = Blacklist::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNoSymbol;

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(2)->schema([
                Select::make('app_id')
                    ->label('Applies to')
                    ->placeholder('All apps')
                    ->options(fn (): array => App::query()
                        ->where('publisher_id', CurrentPublisher::id())
                        ->pluck('name', 'id')
                        ->all())
                    ->live(),

                Select::make('type')
                    ->options(collect(BlacklistType::publisherCases())
                        ->mapWithKeys(fn (BlacklistType $type): array => [$type->value => ucfirst($type->value)])
                        ->all())
                    ->rules([
                        Rule::in(array_map(
                            fn (BlacklistType $type): string => $type->value,
                            BlacklistType::publisherCases(),
                        )),
                    ])
                    ->required()
                    ->live(),

                Select::make('value')
                    ->label('Offer')
                    ->searchable()
                    ->required()
                    ->visible(fn (Get $get): bool => self::typeOf($get) === BlacklistType::Offer)
                    // The price beside each name is the publisher's own, after
                    // their rev-share — the same figure the Offers page shows,
                    // through the same helper. `offers.payout` is what the
                    // network pays us, and a picker that printed it would hand
                    // a publisher our margin by comparing two screens.
                    ->options(fn (Get $get): array => EligibleOffers::query($get('app_id') === null ? null : (int) $get('app_id'))
                        ->limit(200)
                        ->get()
                        ->mapWithKeys(fn (Offer $offer): array => [
                            (string) $offer->id => $offer->name.' — '.PublisherPayout::label($offer->payout),
                        ])
                        ->all()),

                TextInput::make('value')
                    ->label('Country code')
                    ->maxLength(2)
                    ->required()
                    ->visible(fn (Get $get): bool => self::typeOf($get) === BlacklistType::Country),

                Select::make('value')
                    ->label('Device')
                    ->options(DeviceType::class)
                    ->required()
                    ->visible(fn (Get $get): bool => self::typeOf($get) === BlacklistType::Device),

                TextInput::make('reason')->maxLength(191)->helperText('Optional. Future you will want it.'),

                Text::make(fn (Get $get): HtmlString => new HtmlString(EligibleOffers::impact(
                    blank($get('app_id')) ? null : (int) $get('app_id'),
                    (string) self::typeOf($get)?->value,
                    (string) $get('value'),
                )))->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with('app:id,name')
                ->where('type', '!=', BlacklistType::Network))
            ->columns([
                TextColumn::make('type')->badge(),
                TextColumn::make('value')
                    ->formatStateUsing(fn (Blacklist $record): string => EligibleOffers::label($record)),
                TextColumn::make('app.name')->label('Applies to')->placeholder('All apps'),
                TextColumn::make('reason')->placeholder('—')->wrap(),
                TextColumn::make('created_at')->label('Added')->date()->sortable(),
            ])
            // Type is the tab, not a filter — see ListBlacklists.
            ->filters([
                SelectFilter::make('app_id')->label('App')->relationship('app', 'name'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->mutateDataUsing(function (array $data): array {
                        $data['publisher_id'] = CurrentPublisher::id();

                        return $data;
                    })
                    ->after(fn (Blacklist $record) => self::flush($record)),
            ])
            ->recordActions([
                DeleteAction::make()->after(fn (Blacklist $record) => self::flush($record)),
            ])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBlacklists::route('/'),
        ];
    }

    /**
     * A Select bound to an enum holds the enum, not its value — comparing it
     * against `->value` silently never matches and every dependent field stays
     * hidden. Normalising once here is what keeps that from happening per field.
     */
    private static function typeOf(Get $get): ?BlacklistType
    {
        $type = $get('type');

        return $type instanceof BlacklistType ? $type : BlacklistType::tryFrom((string) $type);
    }

    /**
     * Flush the affected app only. A publisher's edit must never clear another
     * publisher's wall cache, and an "all apps" entry touches only their own.
     */
    private static function flush(Blacklist $record): void
    {
        $apps = $record->app_id === null
            ? App::query()->where('publisher_id', $record->publisher_id)->get()
            : App::query()->whereKey($record->app_id)->get();

        foreach ($apps as $app) {
            OfferCacheFlusher::flushForApp($app);
        }
    }
}
