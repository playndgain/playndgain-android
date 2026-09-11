<?php

namespace App\Filament\Admin\Resources\Networks;

use App\Enums\ConnectorCategory;
use App\Enums\NetworkIntegration;
use App\Enums\NetworkStatus;
use App\Enums\SyncStatus;
use App\Filament\Admin\Resources\Networks\Pages\CreateNetwork;
use App\Filament\Admin\Resources\Networks\Pages\EditNetwork;
use App\Filament\Admin\Resources\Networks\Pages\ListNetworks;
use App\Filament\Admin\Resources\Offers\OfferResource;
use App\Filament\Support\InitialsChip;
use App\Jobs\SyncNetworkOffersJob;
use App\Models\AuditLog;
use App\Models\Network;
use App\Networks\Connector;
use App\Networks\Contracts\NetworkDriver;
use App\Networks\NetworkDriverManager;
use App\Networks\OfferCacheFlusher;
use App\Services\Money\Redactor;
use App\Services\Wall\PartnerWall;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Enums\IconSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Js;
use JaOcero\RadioDeck\Forms\Components\RadioDeck;
use Throwable;

/**
 * Supply side — docs/07 §4.
 *
 * The credentials block is rendered from `driver::credentialSchema()`, so
 * adding a network never means editing this file. Stored values are hydrated
 * into the form but masked; the eye reveals them. A blank field on save still
 * keeps the stored value.
 */
class NetworkResource extends Resource
{
    protected static ?string $model = Network::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|\UnitEnum|null $navigationGroup = 'Supply';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Grid::make(1)->schema([

                    Section::make('Identity')->columns(2)->schema([
                        // Most of the list is one integration with one partner, and
                        // a second row on one of those is always a mistake. Hiding
                        // them behind a toggle puts the reusable connectors first
                        // without pretending the others are gone.
                        Toggle::make('show_advanced_connectors')
                            ->label('Show direct connectors')
                            ->helperText('The per-partner integrations. Each holds one account, so a second row is refused.')
                            ->live()
                            ->dehydrated(false)
                            ->visibleOn('create')
                            ->columnSpanFull(),
                        // Cards on create so the connector is picked from the
                        // catalogue, not typed out of a grouped dropdown. Edit
                        // shows only the stored connector: a disabled deck of
                        // every driver would bury the rest of the form, but
                        // the stored value still has to appear in the option
                        // list or Filament refuses the save.
                        RadioDeck::make('driver')
                            ->label('Connector')
                            ->view('filament.forms.radio-deck-connectors')
                            ->options(fn (string $operation, Get $get, ?Network $record): array => collect(self::visibleDriverConnectors($operation, $get, $record))
                                ->map(fn (Connector $connector): string => $connector->label)
                                ->all())
                            ->descriptions(fn (string $operation, Get $get, ?Network $record): array => self::driverCardDescriptions($operation, $get, $record))
                            ->icons(fn (string $operation, Get $get, ?Network $record): array => self::driverCardIcons($operation, $get, $record))
                            ->colors('primary')
                            ->iconSize(IconSize::Large)
                            ->iconPosition(IconPosition::Before)
                            ->alignment(Alignment::Start)
                            ->optionsGap('gap-4')
                            ->padding('px-4 py-5')
                            ->extraCardsAttributes([
                                'class' => 'rounded-xl',
                            ])
                            ->columns([
                                'default' => 1,
                                'md' => 2,
                                'xl' => 3,
                            ])
                            ->columnSpanFull()
                            ->required()
                            ->live()
                            ->helperText(fn (Get $get): ?string => self::driverHelp((string) $get('driver')))
                            ->disableOptionWhen(fn (string $value, string $operation): bool => $operation === 'create' && self::driverAlreadyTaken($value))
                            // Wrapped: Filament evaluates a closure handed to
                            // `rule()` to obtain the rule.
                            ->rule(fn (string $operation): Closure => function (string $attribute, mixed $value, Closure $fail) use ($operation): void {
                                if ($operation !== 'create') {
                                    return;
                                }

                                self::uniqueDriverRule()($attribute, $value, $fail);
                            })
                            ->afterStateUpdated(function (mixed $state, mixed $old, Set $set, Get $get, string $operation): void {
                                if ($operation !== 'create' || ! is_string($state) || $state === '') {
                                    return;
                                }

                                self::applyConnectorDefaults($state, is_string($old) ? $old : null, $set, $get);
                            })
                            // The driver decides the credential fields and the
                            // postback macros. Changing it on a network that
                            // already has conversions would orphan both.
                            ->disabledOn('edit'),
                        TextInput::make('name')->required()->maxLength(191),
                        TextInput::make('website')->url()->maxLength(191),
                        FileUpload::make('logo_path')
                            ->label('Logo')
                            ->image()
                            ->disk('web')
                            ->directory('img/networks')
                            ->visibility('public')
                            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'])
                            ->helperText('Filled from the connector. Replace it here if this account needs its own mark.'),
                        Select::make('status')
                            ->options(NetworkStatus::class)
                            ->required()
                            ->default(NetworkStatus::Disabled)
                            // A new partner is switched on by pasting credentials
                            // and testing them, never at the moment the row is
                            // typed. Shown rather than hidden so the form is not
                            // saying something the save will quietly overrule.
                            ->disabledOn('create')
                            ->helperText(fn (string $operation): ?string => $operation === 'create'
                                ? 'A new network is always saved disabled. Enable it from the list once Test connection passes.'
                                : null),
                        TextInput::make('priority')->numeric()->minValue(0)->maxValue(255)->default(0)
                            ->helperText('Higher wins when two networks carry the same offer.'),
                        TextInput::make('sync_interval_minutes')->numeric()->minValue(5)->default(60)->suffix('minutes')
                            ->visible(fn (Get $get, ?Network $record): bool => NetworkIntegration::fromForm($get('integration'))->usesCatalogue()
                                && ($record?->driverSyncsCatalogue() ?? true)),

                        // The one thing about a partner an admin declares rather than
                        // the driver deciding. The driver still owns "is there an
                        // API"; this is "will we call it, frame their wall, or both"
                        // — and embed-only is what drops the required catalogue keys.
                        Select::make('integration')
                            ->label('Integration')
                            ->options(NetworkIntegration::class)
                            ->default(NetworkIntegration::Api)
                            ->required()
                            ->live()
                            ->helperText('Embedded wall only needs the iframe URL. Catalogue API keys are optional then — paste a postback secret if this partner signs callbacks.'),
                    ]),
                ]),

                Grid::make(1)->schema([
                    Section::make('Credentials')
                        ->description(fn (Get $get): string => NetworkIntegration::fromForm($get('integration')) === NetworkIntegration::Embed
                            ? 'Optional on an embedded wall. Leave blank to skip catalogue keys. A postback secret still belongs here if this partner signs callbacks.'
                            : 'Masked until you reveal them. Leave a field blank to keep the stored value.')
                        ->columns(2)
                    // A partner with no catalogue still has credentials — they
                    // verify its postbacks — so this is keyed on the driver
                    // actually declaring fields, not on the catalogue.
                        ->visible(fn (Get $get): bool => self::credentialFields((string) $get('driver')) !== [])
                        ->schema(fn (Get $get): array => [
                            // Chrome/password-managers treat the first text+password
                            // pair on a page as a login form. These unused fields
                            // absorb that fill so stored credentials are not
                            // overwritten by a saved Google login.
                            Html::make(
                                '<div aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden">'
                                .'<input type="text" tabindex="-1" autocomplete="username">'
                                .'<input type="password" tabindex="-1" autocomplete="current-password">'
                                .'</div>'
                            )->columnSpanFull(),
                            ...self::credentialFields(
                                (string) $get('driver'),
                                NetworkIntegration::fromForm($get('integration'))->usesCatalogue(),
                            ),
                        ]),
                    Section::make('Postback')->columns(1)->schema([
                        TextInput::make('postback_url_display')
                            ->label('Postback URL for this network')
                            ->disabled()
                            ->dehydrated(false)
                            ->copyable()
                            // Masked on screen, whole on the clipboard. The URL is
                            // per account and may carry that account's secret, and
                            // this page is read over shoulders and in screenshots.
                            ->formatStateUsing(fn (?Network $record): string => self::maskedPostbackUrl($record))
                            ->helperText(fn (?Network $record): string => self::postbackUrlHelp($record)),
                        Repeater::make('postback_ips')
                            ->label('Allowed postback IPs')
                            ->helperText('Used when Allow any source IP is off. Empty then rejects every fire.')
                            // No blank row to start. The list is genuinely empty on
                            // most partners — they will not publish addresses — and
                            // a repeater that opens with one required empty field
                            // makes a form that cannot be saved until the admin
                            // deletes a row they never asked for.
                            ->defaultItems(0)
                            ->simple(TextInput::make('ip')->required()->maxLength(45)),
                        Toggle::make('allow_any_postback_ip')
                            ->label('Allow any source IP')
                            ->default(true)
                            ->helperText('On by default. Turn off and fill the list above to restrict which IPs can fire. Signature is then the only check while this is on.'),
                    ]),
                    Section::make('Embedded wall')
                        ->description('How we reach the wall this partner hosts.')
                        ->columns(1)
                        ->visible(fn (Get $get): bool => NetworkIntegration::fromForm($get('integration'))->hostsWall())
                        ->schema([
                            TextInput::make('wall_url')
                                ->label('Partner wall URL')
                                ->maxLength(2048)
                                // Required once the partner is marked as hosting a
                                // wall: marked with nothing to frame is a tab in the
                                // strip whose destination 404s.
                                ->required()
                                ->helperText('Macros: '.collect(PartnerWall::MACROS)->map(fn (string $m): string => '{'.$m.'}')->implode(', ').'. {sub_id} is the one the partner must echo in their postback. This URL is rendered into the wall\'s HTML and is visible to every end user who opens the tab — never put a key, token or secret in it. Credentials belong in the network\'s encrypted credentials, which never leave the server; this column is plaintext by necessity.')
                                ->placeholder('https://partner.test/wall?sub_id={sub_id}&user={user_id}')
                                // Not `->url()`: the template holds `{macros}`, which
                                // no URL validator accepts. The scheme is the part that
                                // matters — the wall is embedded in someone else's
                                // https page, and it is also where injection would land.
                                ->rule('starts_with:https://'),
                            Toggle::make('wall_new_tab')
                                ->label('Opens in a new tab')
                                ->helperText('For a partner whose frame-ancestors refuses us. The wall then shows a button instead of an empty frame.'),
                        ]),

                    Section::make('Last sync')
                        ->visible(fn (?Network $record): bool => filled($record?->last_sync_error) && $record->syncsCatalogue())
                        ->schema([
                            TextInput::make('last_sync_error')->disabled()->dehydrated(false),
                        ]),

                ]),

            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('logo_path')
                    ->label('')
                    ->imageHeight(24)
                    // Resolved to an absolute URL here rather than left to the
                    // column. `logo_path` is a path under `public/`, and an
                    // ImageColumn given a bare path resolves it against the
                    // configured storage disk — it would ask for
                    // `/storage/img/networks/notik.png`, which is a 404 and a
                    // broken image in every row. A full URL is used as-is.
                    ->getStateUsing(fn (Network $record): ?string => filled($record->logo_path)
                        ? asset((string) $record->logo_path)
                        : null)
                    // A network with no logo on file, or one whose file has
                    // gone, falls back to a letter chip rather than a hole or
                    // the browser's broken-image glyph. Admin-only surface, so
                    // the network's own name is the right source here.
                    ->defaultImageUrl(fn (Network $record): string => InitialsChip::for($record->name))
                    // Contained, not cropped: these are supplied marks with
                    // their own aspect ratios, and a circular crop of a
                    // wordmark is a smudge.
                    ->extraImgAttributes(fn (Network $record): array => [
                        'class' => 'object-contain',
                        'onerror' => "this.onerror=null;this.src='".InitialsChip::for($record->name)."'",
                    ]),
                TextColumn::make('name')->searchable()->sortable()->description(fn (Network $record): string => $record->driver),
                // Virtual: the category is a property of the driver class, not
                // a column, so this cannot be sorted and is not offered as
                // sortable. A row whose driver class is gone reads "Unknown"
                // rather than an empty cell, because that is a broken row an
                // admin needs to see rather than an uncategorised one.
                TextColumn::make('category')
                    ->label('Category')
                    ->badge()
                    ->getStateUsing(function (Network $record): ConnectorCategory|string {
                        $connector = $record->connector();

                        return $connector === null ? 'Unknown' : $connector->category;
                    })
                    ->formatStateUsing(fn (ConnectorCategory|string $state): string => $state instanceof ConnectorCategory
                        ? $state->getLabel()
                        : $state)
                    ->color(fn (ConnectorCategory|string $state): string => $state instanceof ConnectorCategory
                        ? $state->getColor()
                        : 'gray'),
                TextColumn::make('status')->badge()->color(fn (NetworkStatus $state): string => match ($state) {
                    NetworkStatus::Active => 'success',
                    NetworkStatus::Error => 'danger',
                    NetworkStatus::Disabled => 'gray',
                }),
                IconColumn::make('integration')
                    ->label('Type')
                    ->tooltip(fn (NetworkIntegration $state): string => $state->getDescription()),
                TextColumn::make('priority')
                    ->sortable()
                    ->tooltip('Higher wins when two networks carry the same offer'),
                // Blank rather than zero for a partner with no catalogue: `0`
                // reads as "synced and found nothing", which is a different
                // and alarming thing.
                TextColumn::make('offers_count')->label('Offers')->numeric()->sortable()
                    ->getStateUsing(fn (Network $record): ?int => $record->syncsCatalogue() ? $record->offers_count : null)
                    ->placeholder('—'),
                TextColumn::make('last_synced_at')->label('Last sync')->since()->sortable()->placeholder('never')
                    ->getStateUsing(fn (Network $record) => $record->syncsCatalogue() ? $record->last_synced_at : null),
                TextColumn::make('last_sync_status')->label('Sync')->badge()
                    ->color(fn (?SyncStatus $state): string => $state === SyncStatus::Ok ? 'success' : 'danger')
                    ->getStateUsing(fn (Network $record): ?SyncStatus => $record->syncsCatalogue() ? $record->last_sync_status : null)
                    ->tooltip(fn (Network $record): ?string => $record->last_sync_error),
            ])
            ->filters([
                SelectFilter::make('status')->options(NetworkStatus::class),
                // No `category` column to filter on, so the enum is resolved to
                // the driver keys that carry it and the query filters on those.
                SelectFilter::make('category')
                    ->label('Category')
                    ->options(fn (): array => collect(ConnectorCategory::cases())
                        ->mapWithKeys(fn (ConnectorCategory $category): array => [$category->value => $category->getLabel()])
                        ->all())
                    ->query(function (Builder $query, array $data): void {
                        $category = ConnectorCategory::tryFrom((string) ($data['value'] ?? ''));

                        if ($category === null) {
                            return;
                        }

                        $query->whereIn('driver', self::driverKeysFor($category));
                    }),
                SelectFilter::make('integration')
                    ->label('Type')
                    ->options(NetworkIntegration::class),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make(self::managementActions()),
            ])
            ->defaultSort('priority', 'desc')
            // Same direction the wall uses: drag a row to the top and it
            // outranks the rest. `desc` so the number we write matches the
            // column sort, not the opposite of it.
            ->reorderable('priority', direction: 'desc')
            // The whole partner list on one page. This table is a fixed set of
            // a dozen rows that an operator reads top to bottom — which network
            // is enabled, which one last synced badly — and Filament's default
            // of ten already pushes the house channel onto a second page nobody
            // thinks to open.
            ->defaultPaginationPageOption(25);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNetworks::route('/'),
            'create' => CreateNetwork::route('/create'),
            'edit' => EditNetwork::route('/{record}/edit'),
        ];
    }

    /** @return list<Action> */
    public static function managementActions(): array
    {
        return [
            Action::make('copy_postback')
                ->label('Copy postback URL')
                ->icon(Heroicon::OutlinedClipboardDocumentList)
                // The clipboard write stays in the click handler: it needs the
                // browser's transient activation, which a server round-trip
                // spends. The audit entry is the one part that needs a server,
                // so it is fired as a separate call afterwards — see
                // `LogsPostbackUrlCopy`.
                ->alpineClickHandler(function (Network $record): string {
                    $url = Js::from(self::postbackUrl($record));
                    $message = Js::from('Copied');
                    $key = Js::from($record->getKey());

                    return <<<JS
                        window.navigator.clipboard.writeText({$url})
                        \$wire.call('logPostbackUrlCopy', {$key})
                        \$tooltip({$message}, {
                            theme: \$store.theme,
                            timeout: 2000,
                        })
                        JS;
                }),
            Action::make('test')
                ->label('Test connection')
                ->icon(Heroicon::OutlinedSignal)
                // These three ask the partner's API something. A partner
                // that only hosts a wall has no API to ask.
                ->visible(fn (Network $record): bool => $record->syncsCatalogue())
                ->action(function (Network $record): void {
                    try {
                        $result = app(NetworkDriverManager::class)->make($record)->testConnection();
                    } catch (Throwable $e) {
                        Notification::make()->title('Test failed')->body($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()
                        ->title($result->ok ? 'Connection OK' : 'Connection failed')
                        ->body($result->offerCount === null
                            ? $result->message
                            : "{$result->message} — {$result->offerCount} offers visible")
                        ->status($result->ok ? 'success' : 'danger')
                        ->send();
                }),
            Action::make('sync')
                ->label('Sync now')
                ->icon(Heroicon::OutlinedArrowPath)
                ->visible(fn (Network $record): bool => $record->syncsCatalogue())
                ->requiresConfirmation()
                ->modalDescription('Queues a catalogue pull. Offers update when the job finishes.')
                ->action(function (Network $record): void {
                    SyncNetworkOffersJob::dispatch($record);

                    Notification::make()->title('Sync queued')->success()->send();
                }),
            Action::make('toggle')
                ->label(fn (Network $record): string => $record->isActive() ? 'Disable' : 'Enable')
                ->icon(Heroicon::OutlinedPower)
                ->color(fn (Network $record): string => $record->isActive() ? 'warning' : 'success')
                ->requiresConfirmation()
                ->modalDescription(fn (Network $record): string => $record->isActive()
                    ? 'Live traffic from this partner stops immediately.'
                    : 'This partner starts sending offers and postbacks again.')
                ->action(function (Network $record): void {
                    $before = $record->status;
                    $record->update([
                        'status' => $record->isActive() ? NetworkStatus::Disabled : NetworkStatus::Active,
                    ]);

                    AuditLog::record('network.status', $record, ['status' => $before->value], ['status' => $record->status->value]);

                    OfferCacheFlusher::flushForNetwork($record);
                }),
            Action::make('offers')
                ->label('View offers')
                ->icon(Heroicon::OutlinedRectangleStack)
                ->visible(fn (Network $record): bool => $record->syncsCatalogue())
                ->url(fn (Network $record): string => OfferResource::getUrl(parameters: [
                    'tableFilters' => ['network_id' => ['value' => $record->id]],
                ])),
        ];
    }

    /**
     * Connectors an admin may pick, platform first, then us, then directs.
     *
     * Radio Deck iterates a flat value => label list. Grouped arrays (the
     * shape the edit Select uses) would render the group name as a card.
     *
     * @return array<string, Connector>
     */
    public static function driverConnectors(bool $includeDirect): array
    {
        $byCategory = [];

        foreach (app(NetworkDriverManager::class)->all() as $key => $class) {
            $connector = $class::connector();

            if (! $connector->listedInAdmin) {
                continue;
            }

            if ($connector->category === ConnectorCategory::Direct && ! $includeDirect) {
                continue;
            }

            $byCategory[$connector->category->value][$key] = $connector;
        }

        $ordered = [];

        foreach ([ConnectorCategory::Platform, ConnectorCategory::Internal, ConnectorCategory::Direct] as $category) {
            foreach ($byCategory[$category->value] ?? [] as $key => $connector) {
                $ordered[$key] = $connector;
            }
        }

        return $ordered;
    }

    /**
     * The connectors the deck should render for this form state.
     *
     * @return array<string, Connector>
     */
    public static function visibleDriverConnectors(string $operation, Get $get, ?Network $record): array
    {
        if ($operation !== 'create') {
            $driver = (string) ($record instanceof Network ? $record->driver : ($get('driver') ?? ''));
            $connector = Network::connectorFor($driver);

            // A row whose driver class is gone still has to name that key
            // in the option list, or Filament treats the save as invalid.
            return $driver === ''
                ? []
                : [$driver => $connector ?? new Connector($driver, ConnectorCategory::Direct)];
        }

        return self::driverConnectors((bool) $get('show_advanced_connectors'));
    }

    /**
     * Driver options grouped by category, platform connectors first.
     *
     * The order is the order an admin should be choosing in: the reusable
     * connectors, then us, then the long tail of per-partner integrations. A
     * group with nothing in it is left out rather than rendered empty.
     *
     * @return array<string, array<string, string>>
     */
    public static function driverOptions(bool $includeDirect): array
    {
        $grouped = [];

        foreach (self::driverConnectors($includeDirect) as $key => $connector) {
            $grouped[$connector->category->groupLabel()][$key] = $connector->label;
        }

        return $grouped;
    }

    /**
     * @return array<string, string>
     */
    public static function driverCardDescriptions(string $operation, Get $get, ?Network $record): array
    {
        $descriptions = [];

        foreach (self::visibleDriverConnectors($operation, $get, $record) as $key => $connector) {
            $descriptions[$key] = $operation === 'create' && self::driverAlreadyTaken($key)
                ? 'Already added.'
                : $connector->category->cardDescription();
        }

        return $descriptions;
    }

    /**
     * Partner mark when one is on disk, otherwise the category icon.
     *
     * @return array<string, string>
     */
    public static function driverCardIcons(string $operation, Get $get, ?Network $record): array
    {
        $icons = [];

        foreach (self::visibleDriverConnectors($operation, $get, $record) as $key => $connector) {
            $icons[$key] = $connector->logoUrlFor($key) ?? $connector->category->icon();
        }

        return $icons;
    }

    /**
     * Write name, website and logo from the connector, without clobbering
     * values the admin already typed or that came from the previous card.
     */
    public static function applyConnectorDefaults(string $driver, ?string $previousDriver, Set $set, Get $get): void
    {
        $connector = Network::connectorFor($driver);

        if ($connector === null) {
            return;
        }

        $defaults = $connector->formDefaults($driver);
        $previous = $previousDriver === null || $previousDriver === ''
            ? []
            : (Network::connectorFor($previousDriver)?->formDefaults($previousDriver) ?? []);

        foreach (['name', 'website', 'logo_path'] as $field) {
            $current = $get($field);

            if (blank($current) || $current === ($previous[$field] ?? null)) {
                $set($field, $defaults[$field]);
            }
        }
    }

    public static function driverAlreadyTaken(string $driver): bool
    {
        $connector = Network::connectorFor($driver);

        if ($connector === null || $connector->supportsMultipleAccounts) {
            return false;
        }

        return Network::query()->where('driver', $driver)->exists();
    }

    /**
     * One account per connector unless the connector says otherwise.
     *
     * The database enforces this through `unique(driver, account_scope)`;
     * the rule is here so the admin is told which connector and why,
     * rather than getting an integrity violation.
     */
    public static function uniqueDriverRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (self::driverAlreadyTaken((string) $value)) {
                $fail('This connector allows one account.');
            }
        };
    }

    /**
     * The machine key behind the label an admin just picked.
     *
     * It is the postback route segment's fallback, the seeder's identifier and
     * the string in every log line, so hiding it entirely behind a brand name
     * would make the admin the only place it cannot be read.
     */
    public static function driverHelp(string $driver): ?string
    {
        $connector = Network::connectorFor($driver);

        if ($connector === null) {
            return null;
        }

        return $connector->supportsMultipleAccounts
            ? "Key: {$driver}. This connector hosts one account per partner, so more than one network may use it."
            : "Key: {$driver}. One account.";
    }

    /**
     * @return list<string>
     */
    public static function driverKeysFor(ConnectorCategory $category): array
    {
        return collect(app(NetworkDriverManager::class)->all())
            ->filter(fn (string $class): bool => $class::connector()->category === $category)
            ->keys()
            ->all();
    }

    public static function postbackUrl(?Network $record): string
    {
        if ($record === null) {
            return 'Save the network to see its postback URL';
        }

        return $record->postbackUrl() ?? url('/postback/'.(string) $record->postback_key);
    }

    /**
     * The same URL with any literal credential in it masked.
     *
     * The parameter names come from `Redactor`, the one rule the postback log
     * and the outbound redactor already share, so a connector that names its
     * shared key `token` or `postback_key` is covered without this method
     * learning about it.
     *
     * Only literals are masked. A driver's macro template carries
     * `secret={secret}` for the partner to substitute, and masking the token
     * hands the admin a paste string that fails silently — exactly what
     * `postbackUrlHelp()` warns about.
     */
    public static function maskedPostbackUrl(?Network $record): string
    {
        return (string) preg_replace_callback(
            '/([?&])([^=&]+)=([^&]*)/',
            fn (array $match): string => Redactor::isSensitive($match[2]) && ! self::isMacro($match[3])
                ? $match[1].$match[2].'='.Redactor::MASK
                : $match[0],
            self::postbackUrl($record),
        );
    }

    /** `{secret}`, `{{secretKey}}` and `#{…}` are all a partner's substitution token. */
    private static function isMacro(string $value): bool
    {
        return str_starts_with($value, '{') || str_starts_with($value, '#{');
    }

    public static function postbackUrlHelp(?Network $record): string
    {
        $class = self::driverClass($record?->driver);

        if ($class === null) {
            return 'Save the network to see the URL this driver expects.';
        }

        return $class::postbackQuery() === []
            ? 'This network sends a fixed payload to the bare URL. Paste it as-is.'
            : 'Copy this into the network dashboard. Every macro is one the driver reads — dropping one fails silently.';
    }

    /**
     * @return class-string<NetworkDriver>|null
     */
    private static function driverClass(?string $driver): ?string
    {
        $manager = app(NetworkDriverManager::class);

        if ($driver === null || $driver === '' || ! $manager->has($driver)) {
            return null;
        }

        return $manager->all()[$driver];
    }

    /**
     * @return list<TextInput>
     */
    public static function credentialFields(string $driver, bool $required = true): array
    {
        $class = self::driverClass($driver);

        if ($class === null) {
            return [];
        }

        $schema = $class::credentialSchema();

        return collect($schema)->map(function (array $field, string $key) use ($required) {
            // A tenant URL is a host this server will dial. It is not a secret,
            // it is the field most likely to be mistyped, and it is the one
            // credential a driver can refuse before anything is saved — so it
            // renders as a plain input with the driver's own predicate on it.
            $secret = $field['secret'] ?? true;
            $rule = $field['rule'] ?? null;

            $input = TextInput::make("credentials.{$key}")
                ->label($field['label'])
                ->required($required && ($field['required'] ?? false))
                ->placeholder($field['placeholder'] ?? null);

            if ($rule !== null) {
                $message = $field['rule_message'] ?? 'This value is not allowed.';

                // Wrapped: Filament evaluates a closure handed to `rule()` to
                // obtain the rule, so a validation closure passed directly
                // would be called with Filament's own injected arguments.
                $input->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($rule, $message): void {
                    // Blank means "keep the stored value" on edit; `required`
                    // is what refuses a blank one on create.
                    if (filled($value) && ! $rule(is_string($value) ? $value : null)) {
                        $fail($message);
                    }
                });
            }

            if (! $secret) {
                return $input;
            }

            return $input
                ->password()
                ->revealable()
                // Chrome treats a nearby text + password pair as a login form.
                // Mask the value, refuse autocomplete, and let password
                // managers skip these fields. Do not use readOnly() — Filament
                // then treats the input as non-editable even after the
                // attribute is removed in the DOM.
                ->autocomplete('new-password')
                ->extraInputAttributes([
                    'data-1p-ignore' => 'true',
                    'data-lpignore' => 'true',
                    'data-bwignore' => 'true',
                    'data-form-type' => 'other',
                ]);
        })->values()->all();
    }
}
