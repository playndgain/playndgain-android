<?php

namespace App\Filament\Admin\Resources\Offers;

use App\Enums\ConversionType;
use App\Enums\DeviceType;
use App\Enums\OfferStatus;
use App\Filament\Admin\Resources\Offers\Pages\ListOffers;
use App\Filament\Support\InitialsChip;
use App\Filament\Support\ViewOfferEventsAction;
use App\Models\AuditLog;
use App\Models\Network;
use App\Models\Offer;
use App\Networks\Drivers\DirectDriver;
use App\Networks\NetworkDriverManager;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Leek\FilamentRightClick\Menu\ContextMenuItem;
use Leek\FilamentRightClick\Menu\ContextMenuSeparator;

/**
 * The catalogue — docs/07 §4.
 *
 * Read-only apart from `status` and `is_featured`. Everything else is the
 * network's data and the next sync would overwrite an edit, so the panel does
 * not offer one.
 */
class OfferResource extends Resource
{
    protected static ?string $model = Offer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Supply';

    protected static ?int $navigationSort = 2;

    /**
     * Only ever rendered for a `direct` offer — the create and edit actions are
     * the sole entry points and both are limited to that network. A network's
     * own offers are overwritten by the next sync, so a form for them would be
     * an edit that silently reverts within the hour.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Offer')->columns(2)
                ->description('What the advertiser is selling and what they pay us. Publishers never see this payout — only their rev-share of it.')
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(191)
                        ->columnSpanFull()
                        ->placeholder('Cases.gg')
                        ->helperText('The name on the wall card and in both panels. Use the title users already know.'),
                    TextInput::make('tracking_url')
                        ->label('Tracking URL')
                        ->required()
                        ->columnSpanFull()
                        ->placeholder('https://advertiser.example/lp?subid={click_id}')
                        // Not `->url()`: the macro braces make the value fail that
                        // rule, so the check runs against the substituted URL. And
                        // appending the macro silently would hide a typo until the
                        // first conversion arrived with nothing to attach it to.
                        ->rules([
                            fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                if (! str_contains((string) $value, '{click_id}')) {
                                    $fail('The tracking URL must contain {click_id} — that is what comes back on the postback.');

                                    return;
                                }

                                if (filter_var(str_replace('{click_id}', 'CLICKID', (string) $value), FILTER_VALIDATE_URL) === false) {
                                    $fail('The tracking URL must be a valid URL.');
                                }
                            },
                        ])
                        ->helperText('The advertiser landing page. Must contain {click_id} — we substitute it on click and they must echo it on the postback, or the conversion cannot be attached.'),
                    TextInput::make('payout')
                        ->label('Payout (USD)')
                        ->required()
                        ->numeric()
                        ->prefix('$')
                        ->minValue(0)
                        ->step('0.000001')
                        ->placeholder('4.500000')
                        ->helperText('What the advertiser pays us, in USD. The publisher sees their rev-share of this figure, never the figure itself.'),
                    Select::make('conversion_type')
                        ->options(ConversionType::class)
                        ->required()
                        ->placeholder('What the user does')
                        ->helperText('CPI install, CPA action, CPL lead, survey, signup, purchase, or other. Badge on the wall card and in the catalogue.'),
                    Textarea::make('description')
                        ->rows(2)
                        ->columnSpanFull()
                        ->placeholder('A two-week village builder. Reach village 15 to finish.')
                        ->helperText('Short pitch on the offer detail. Users read this before they leave the wall.'),
                    Textarea::make('instructions')
                        ->rows(2)
                        ->columnSpanFull()
                        ->placeholder("Install the game.\nCreate an account.\nReach village 5.")
                        ->helperText('What the user has to do to convert, one step per line. Shown on the wall detail and in View events.'),
                ]),
            Section::make('Targeting')->columns(2)
                ->description('Who can see this offer. Empty geo or devices means everyone — the wall filters on these exact values.')
                ->schema([
                    TagsInput::make('countries')
                        ->placeholder('US')
                        ->helperText('ISO-2 country codes (US, EG, DE). Empty means worldwide. A code that is not two letters never matches the wall geo, so the offer is shown to nobody.')
                        // Two letters, refused at the form rather than cleaned on
                        // the way out. A code that is not ISO-2 can never match the
                        // country the wall resolves, so a typo here is an offer
                        // nobody is ever shown and no error anybody sees — and the
                        // same column is rendered into an attribute and a filename
                        // on the wall, so "malformed" and "hostile" are one class.
                        ->nestedRecursiveRules(['string', 'regex:/^[A-Za-z]{2}$/'])
                        ->validationMessages(['regex' => 'Each country must be a two-letter ISO-2 code.'])
                        // Stored uppercase because that is what the wall filters on.
                        ->formatStateUsing(fn (?array $state): array => array_map('strtoupper', $state ?? []))
                        ->dehydrateStateUsing(fn (?array $state): array => array_values(array_unique(
                            array_map(fn (string $code): string => strtoupper(trim($code)), $state ?? []),
                        ))),
                    Select::make('devices')
                        ->multiple()
                        ->options(DeviceType::class)
                        ->placeholder('Every device')
                        ->helperText('Android, iOS, desktop, or other. Empty means the wall shows this offer on every device.'),
                    TagsInput::make('categories')
                        ->placeholder('games')
                        ->helperText('Tags on the wall card and in the category filter (games, survey, shopping). Not used to hide the offer.'),
                    TextInput::make('os_min_version')
                        ->label('Minimum OS version')
                        ->maxLength(191)
                        ->placeholder('14.0')
                        ->helperText('Lowest OS the offer runs on (e.g. 14.0). The wall hides the offer from older versions. Leave blank if any version is fine.'),
                    TextInput::make('icon_url')
                        ->label('Icon URL')
                        ->url()
                        ->maxLength(2048)
                        ->placeholder('https://cdn.advertiser.example/icon.png')
                        ->helperText('Square icon on the wall card and in the catalogue. Must be a reachable https URL.'),
                    TextInput::make('daily_cap')
                        ->label('Daily cap')
                        ->numeric()
                        ->minValue(1)
                        ->placeholder('500')
                        ->helperText('Optional. The advertiser\'s daily conversion cap, when they give one. Blank means none was set.'),
                ]),
            Section::make('Placement')->columns(2)
                ->description('Whether the wall serves it, and how it is ranked.')
                ->schema([
                    Select::make('status')
                        ->options(OfferStatus::class)
                        ->required()
                        ->default(OfferStatus::Active)
                        ->helperText('Active is served. Inactive is hidden until you turn it back on. Blocked is a global ban with a reason.'),
                    Toggle::make('is_featured')
                        ->label('Feature on every wall')
                        ->helperText('Pins this offer to the top of every publisher wall, ahead of payout sort.'),
                    Toggle::make('is_multi_reward')
                        ->label('Pays on several events')
                        ->helperText('On when the user can convert more than once (level-ups, deposits). Completing one event does not hide the offer.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withCount([
                    'offerClicks as clicks_7d' => fn (Builder $query) => $query->where('created_at', '>=', now()->subDays(7)),
                    'conversions as conversions_7d' => fn (Builder $query) => $query->where('created_at', '>=', now()->subDays(7)),
                ]))
            ->columns([
                ImageColumn::make('icon_url')
                    ->label('')
                    ->circular()
                    ->imageSize(32)
                    // A missing icon left a hole in the row and a dead one left
                    // the browser's broken-image glyph. The fallback is drawn
                    // from the offer's own name, matching the publisher-side
                    // column so the two lists read alike.
                    // `defaultImageUrl` covers a null column; `onerror` covers
                    // an icon URL that stops resolving, which happens on its
                    // own schedule upstream.
                    ->defaultImageUrl(fn (Offer $record): string => InitialsChip::for($record->name))
                    ->extraImgAttributes(fn (Offer $record): array => [
                        'onerror' => "this.onerror=null;this.src='".InitialsChip::for($record->name)."'",
                    ]),
                TextColumn::make('name')->searchable()->limit(40)->sortable()
                    ->description(fn (Offer $record): string => $record->external_id),
                // The four filters an admin reaches for most sit in the column
                // headers they filter; the rest stay in the Filters panel.
                TextColumn::make('network.name')->sortable()
                    // A native select: the searchable one entangles deferred
                    // filter state the header row does not carry.
                    ->headerFilter(SelectFilter::make('network_id')->label('Network')->relationship('network', 'name')),
                TextColumn::make('payout')->money('USD')->sortable()
                    ->headerFilter(Filter::make('payout')
                        ->columns(2)
                        ->schema([
                            TextInput::make('min')->numeric()->placeholder('Min'),
                            TextInput::make('max')->numeric()->placeholder('Max'),
                        ])
                        ->query(fn (Builder $query, array $data): Builder => $query
                            ->when(filled($data['min'] ?? null), fn (Builder $q) => $q->where('payout', '>=', $data['min']))
                            ->when(filled($data['max'] ?? null), fn (Builder $q) => $q->where('payout', '<=', $data['max'])))),
                TextColumn::make('conversion_type')->badge()
                    ->headerFilter(SelectFilter::make('conversion_type')->options(ConversionType::class)),
                // Views, not formatters: Filament hands a `formatStateUsing`
                // closure one array item at a time, so a whole-list renderer
                // never sees the list.
                ViewColumn::make('countries')->label('Geo')->view('filament.tables.columns.geo-stack'),
                ViewColumn::make('devices')->view('filament.tables.columns.device-stack'),
                TextColumn::make('status')->badge()->color(fn (OfferStatus $state): string => match ($state) {
                    OfferStatus::Active => 'success',
                    OfferStatus::Blocked => 'danger',
                    OfferStatus::Inactive => 'gray',
                })->description(fn (Offer $record): ?string => $record->blocked_reason)
                    ->headerFilter(SelectFilter::make('status')->options(OfferStatus::class)),
                TextColumn::make('clicks_7d')->label('Clicks 7d')->numeric()->sortable(),
                TextColumn::make('conversions_7d')->label('Conv 7d')->numeric()->sortable(),
                TextColumn::make('last_seen_at')->label('Last seen')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('featured')->label('Featured')
                    ->options(['1' => 'Featured only'])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->where('is_featured', true)
                        : $query),
                Filter::make('country')
                    ->schema([TextInput::make('country')->label('Country code')->maxLength(2)])
                    // JSON containment, not LIKE: `IN` would match "IN" inside
                    // "INDIA" and a two-letter LIKE matches almost everything.
                    ->query(fn (Builder $query, array $data): Builder => filled($data['country'] ?? null)
                        ? $query->whereJsonContains('countries', Str::upper($data['country']))
                        : $query),
                SelectFilter::make('device')
                    ->options(DeviceType::class)
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereJsonContains('devices', $data['value'])
                        : $query),
                Filter::make('has_conversions')
                    ->label('Has conversions')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereHas('conversions')),
            ])
            ->recordActions([
                ViewOfferEventsAction::make(),
                ActionGroup::make([
                    self::editAction('edit'),
                    self::blockAction('block'),
                    self::unblockAction('unblock'),
                    self::featureAction('feature'),
                    Action::make('preview')
                        ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                        ->url(fn (Offer $record): string => self::previewUrl($record), shouldOpenInNewTab: true),
                ]),
            ])
            // The same actions on right-click, under their own names: the menu
            // registers them as table actions too, and two actions called
            // `block` would collide. A hidden action (edit on a synced offer,
            // block on an already-blocked one) refuses to mount server-side.
            ->contextMenuActions([
                ContextMenuItem::for(self::editAction('ctx_edit'))->label('Edit offer')->icon(Heroicon::OutlinedPencilSquare),
                ContextMenuItem::for(self::featureAction('ctx_feature'))->label('Feature / unfeature')->icon(Heroicon::OutlinedStar),
                ContextMenuSeparator::make(),
                ContextMenuItem::for(self::blockAction('ctx_block'))->label('Block globally')->icon(Heroicon::OutlinedNoSymbol)->color('danger'),
                ContextMenuItem::for(self::unblockAction('ctx_unblock'))->label('Unblock')->icon(Heroicon::OutlinedCheck),
            ])
            ->defaultSort('last_seen_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOffers::route('/'),
        ];
    }

    /** @return Builder<Offer> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->scopes('onActiveNetwork');
    }

    /** @return array<int, string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'external_id'];
    }

    /**
     * The home for offers we sell ourselves. Seeded, so this is only null on a
     * database that has not been seeded — the create action hides rather than
     * offering a form that cannot save.
     */
    public static function directNetwork(): ?Network
    {
        return Network::query()->where('driver', DirectDriver::key())->first();
    }

    /**
     * Only ours. A network's offer is rewritten by the next sync, so editing one
     * is a change that quietly reverts.
     */
    private static function editAction(string $name): EditAction
    {
        return EditAction::make($name)
            ->label('Edit offer')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->visible(fn (Offer $record): bool => $record->network?->driver === DirectDriver::key())
            ->after(fn (Offer $record) => AuditLog::record('offer.updated', $record));
    }

    private static function blockAction(string $name): Action
    {
        return Action::make($name)
            ->label('Block globally')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->visible(fn (Offer $record): bool => $record->status !== OfferStatus::Blocked)
            ->schema([TextInput::make('reason')->required()->maxLength(191)])
            ->action(function (Offer $record, array $data): void {
                $record->update(['status' => OfferStatus::Blocked, 'blocked_reason' => $data['reason']]);

                AuditLog::record('offer.blocked', $record, null, ['reason' => $data['reason']]);
            });
    }

    private static function unblockAction(string $name): Action
    {
        return Action::make($name)
            ->label('Unblock')
            ->icon(Heroicon::OutlinedCheck)
            ->visible(fn (Offer $record): bool => $record->status === OfferStatus::Blocked)
            ->requiresConfirmation()
            ->action(function (Offer $record): void {
                $record->update(['status' => OfferStatus::Active, 'blocked_reason' => null]);

                AuditLog::record('offer.unblocked', $record);
            });
    }

    private static function featureAction(string $name): Action
    {
        return Action::make($name)
            ->label(fn (Offer $record): string => $record->is_featured ? 'Unfeature' : 'Feature')
            ->icon(Heroicon::OutlinedStar)
            ->action(fn (Offer $record) => $record->update(['is_featured' => ! $record->is_featured]));
    }

    /**
     * The driver builds the URL, so a preview click looks exactly like a real
     * one apart from the click ID.
     */
    private static function previewUrl(Offer $record): string
    {
        $manager = app(NetworkDriverManager::class);

        return $manager->has($record->network->driver)
            ? $manager->make($record->network)->buildTrackingUrl($record, 'admin-preview')
            : $record->tracking_url;
    }
}
