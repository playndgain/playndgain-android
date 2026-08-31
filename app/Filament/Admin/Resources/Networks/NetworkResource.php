<?php

namespace App\Filament\Admin\Resources\Networks;

use App\Enums\NetworkIntegration;
use App\Enums\NetworkStatus;
use App\Enums\SyncStatus;
use App\Filament\Admin\Resources\Networks\Pages\EditNetwork;
use App\Filament\Admin\Resources\Networks\Pages\ListNetworks;
use App\Filament\Admin\Resources\Offers\OfferResource;
use App\Jobs\SyncNetworkOffersJob;
use App\Models\AuditLog;
use App\Models\Network;
use App\Networks\Contracts\NetworkDriver;
use App\Networks\NetworkDriverManager;
use App\Networks\OfferCacheFlusher;
use App\Services\Wall\PartnerWall;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
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
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Js;
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
        return $schema->components([
            Grid::make(1)->schema([

                Section::make('Identity')->columns(2)->schema([
                    Select::make('driver')
                        ->options(fn (): array => collect(app(NetworkDriverManager::class)->all())
                            ->keys()
                            ->mapWithKeys(fn (string $key): array => [$key => $key])
                            ->all())
                        ->required()
                        ->live()
                        // The driver decides the credential fields and the postback
                        // route segment. Changing it on a network that already has
                        // conversions would orphan both.
                        ->disabledOn('edit'),
                    TextInput::make('name')->required()->maxLength(191),
                    TextInput::make('website')->url()->maxLength(191),
                    TextInput::make('logo_path')
                        ->label('Logo')
                        ->maxLength(191)
                        // A path under `public/`, not a URL. The seeded partners
                        // ship their marks in `public/img/networks/` so the list
                        // renders without a request to someone else's CDN — which
                        // is also the CDN that rotates the file and leaves a broken
                        // image behind.
                        ->placeholder('img/networks/example.svg')
                        ->helperText('Path under public/, e.g. img/networks/notik.png'),
                    Select::make('status')->options(NetworkStatus::class)->required(),
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

                Section::make('Postback')->columns(1)->schema([
                    TextInput::make('postback_url_display')
                        ->label('Postback URL for this network')
                        ->disabled()
                        ->dehydrated(false)
                        ->copyable()
                        ->formatStateUsing(fn (?Network $record): string => self::postbackUrl($record))
                        ->helperText(fn (?Network $record): string => self::postbackUrlHelp($record)),
                    Repeater::make('postback_ips')
                        ->label('Allowed postback IPs')
                        ->helperText('Used when Allow any source IP is off. Empty then rejects every fire.')
                        ->simple(TextInput::make('ip')->required()->maxLength(45)),
                    Toggle::make('allow_any_postback_ip')
                        ->label('Allow any source IP')
                        ->default(true)
                        ->helperText('On by default. Turn off and fill the list above to restrict which IPs can fire. Signature is then the only check while this is on.'),
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
                    // Contained, not cropped: these are supplied marks with
                    // their own aspect ratios, and a circular crop of a
                    // wordmark is a smudge.
                    ->extraImgAttributes(['class' => 'object-contain']),
                TextColumn::make('name')->searchable()->sortable()->description(fn (Network $record): string => $record->driver),
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
                ->alpineClickHandler(function (Network $record): string {
                    $url = Js::from(self::postbackUrl($record));
                    $message = Js::from('Copied');

                    return <<<JS
                        window.navigator.clipboard.writeText({$url})
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

    public static function postbackUrl(?Network $record): string
    {
        if ($record === null) {
            return 'Save the network to see its postback URL';
        }

        $class = self::driverClass($record->driver);

        return $class === null
            ? url("/postback/{$record->driver}")
            : $class::postbackUrl();
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
            return TextInput::make("credentials.{$key}")
                ->label($field['label'])
                ->required($required && ($field['required'] ?? false))
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
