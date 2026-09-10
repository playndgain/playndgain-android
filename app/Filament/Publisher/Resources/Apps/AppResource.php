<?php

namespace App\Filament\Publisher\Resources\Apps;

use App\Enums\AppPlatform;
use App\Enums\AppStatus;
use App\Enums\PostbackMethod;
use App\Enums\PublisherStatus;
use App\Filament\Publisher\Resources\Apps\Pages\CreateApp;
use App\Filament\Publisher\Resources\Apps\Pages\EditApp;
use App\Filament\Publisher\Resources\Apps\Pages\ListApps;
use App\Filament\Publisher\Resources\PublisherScopedResource;
use App\Filament\Publisher\Support\CurrentPublisher;
use App\Filament\Publisher\Support\PublisherTestPostbackAction;
use App\Filament\Support\AppColumns;
use App\Models\App;
use App\Services\Money\MacroResolver;
use App\Services\Wall\WallContext;
use App\Support\Contrast;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Placements — docs/08 §4.
 *
 * The Integration tab is the page that decides whether a publisher ever
 * launches, so everything needed to embed the wall is on it and copyable: the
 * app ID, the secret, and a snippet with their real ID already in it.
 */
class AppResource extends PublisherScopedResource
{
    protected static ?string $model = App::class;

    protected static string|BackedEnum|null $navigationIcon = 'lucide-smartphone';

    protected static string|\UnitEnum|null $navigationGroup = 'Monetize';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Editing: every step is open, and the page's own Save button sits under
     * the form, so a name change is one click and not a walk to the last
     * step. Creation (CreateApp) wires the same steps into a linear wizard.
     */
    public static function form(Schema $schema): Schema
    {
        return self::wizardForm($schema, Wizard::make(self::steps())
            ->skippable()
            ->contained(false));
    }

    /** The steps beside the live preview — the layout both pages share. */
    public static function wizardForm(Schema $schema, Component $wizard): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Grid::make(['default' => 1, 'xl' => 12])
                    ->columnSpanFull()
                    ->schema([
                        $wizard->columnSpan(['default' => 12, 'xl' => 7]),
                        self::previewPane()->columnSpan(['default' => 12, 'xl' => 5]),
                    ]),
            ]);
    }

    /**
     * Live preview — docs/08 §2 and §5, "change the theme, see it".
     *
     * Rendered here rather than framed from `/wall`, and the reasons are in the
     * view: the wall reads the *saved* palette out of the database, and it
     * refuses to serve a pending app at all — which every app is on the day its
     * appearance gets set. What it does share is `WallContext::sanitiseTheme`
     * and `::vars`, and the wall's own classes via wall-ui.css, so a colour
     * this rejects is a colour the wall rejects and the cards are the cards.
     */
    private static function previewPane(): Group
    {
        return Group::make([
            Text::make('Live preview')->weight('semibold'),
            Html::make(fn (Get $get, ?App $record): HtmlString => self::preview($get, $record)),
            // The one thing a local render cannot show: whether the real wall
            // serves. Only offered once it can — a link to the unavailable
            // notice teaches the publisher their integration is broken when it
            // is only unapproved.
            Text::make(fn (?App $record): HtmlString => new HtmlString(self::liveWallLink($record))),
        ])->extraAttributes(['class' => 'wall-preview-pane']);
    }

    /**
     * In the order a publisher sets an app up: what it is, what its coins are
     * worth, where to tell it about conversions, how the wall looks, what to
     * keep out — and last the keys and snippet, which only exist once saved.
     *
     * @return list<Step>
     */
    public static function steps(): array
    {
        return [
            Step::make('Details')->icon('lucide-app-window')->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(191)->live(onBlur: true),
                TextInput::make('url')->label('Website or store URL')->url()->maxLength(191),
                Select::make('platform')->options(AppPlatform::class)->required()->default(AppPlatform::Web),
                FileUpload::make('icon_path')->label('Icon')->image()->directory('app-icons')->imageEditor(),
            ]),

            Step::make('Currency')->icon('lucide-coins')->columns(2)->schema([
                TextInput::make('currency_name')->required()->maxLength(64)->default('Coins')->live(onBlur: true),
                TextInput::make('currency_rate')
                    ->label('Units per $1')
                    ->numeric()
                    ->required()
                    ->minValue(0.0001)
                    ->default(1000)
                    ->live(onBlur: true),
                // The single line that prevents the most common integration
                // error on the platform — docs/08 §4.
                Text::make(fn (Get $get): HtmlString => new HtmlString(self::currencyExample($get)))
                    ->columnSpanFull(),
            ]),

            Step::make('Postback')->icon('lucide-webhook')->columns(1)->schema([
                TextInput::make('postback_url')
                    ->label('Callback URL')
                    ->maxLength(2048)
                    // Not `->url()`: the template holds `{user_id}` and the rest
                    // of the macros listed below, and Laravel's url rule rejects
                    // the braces. Same reason the partner wall URL is https-only.
                    // Trim: a trailing space from a mobile paste is the other
                    // way this field used to fail a URL that was otherwise fine.
                    ->dehydrateStateUsing(fn (mixed $state): ?string => filled($state) ? trim((string) $state) : null)
                    ->rules([
                        fn (): Closure => MacroResolver::validateCallbackUrl(...),
                    ])
                    ->helperText('Leave blank to poll the dashboard instead. We never retry into a URL you did not give us.'),
                Select::make('postback_method')->options(PostbackMethod::class)->default(PostbackMethod::GET),
                Text::make(new HtmlString(self::macroReference()))->columnSpanFull(),
            ]),

            Step::make('Appearance')->icon('lucide-palette')->columns(2)->schema([
                ColorPicker::make('theme.primary')->label('Primary')->default(WallContext::DEFAULT_THEME['primary'])->live(),
                ColorPicker::make('theme.background')->label('Background')->default(WallContext::DEFAULT_THEME['background'])->live(),
                ColorPicker::make('theme.text')->label('Text')->default(WallContext::DEFAULT_THEME['text'])->live(),
                // Stored with its unit, because WallContext::sanitiseTheme
                // whitelists `12px` and drops a bare `12` — which is what
                // this field used to save, so the control moved a number
                // that never reached the wall.
                TextInput::make('theme.radius')
                    ->label('Corner radius (px)')
                    ->numeric()
                    ->default(4)
                    ->minValue(0)
                    ->maxValue(40)
                    ->live(onBlur: true)
                    ->formatStateUsing(fn (mixed $state): ?int => is_scalar($state) ? (int) $state : null)
                    ->dehydrateStateUsing(fn (mixed $state): ?string => filled($state) ? ((int) $state).'px' : null),
                // The switch moves the surfaces with it: a "light" wall on an
                // ink background is no wall anyone asked for. The primary is
                // the publisher's and stays.
                Toggle::make('theme.dark')
                    ->label('Dark wall')
                    ->default(true)
                    ->live()
                    ->afterStateUpdated(function (Set $set, mixed $state): void {
                        $surfaces = $state ? WallContext::DEFAULT_THEME : WallContext::LIGHT_SURFACES;

                        $set('theme.background', $surfaces['background']);
                        $set('theme.text', $surfaces['text']);
                    }),
                // Publishers pick unreadable pairs constantly. Warning them
                // here is cheaper than the support thread afterwards.
                Text::make(fn (Get $get): HtmlString => new HtmlString(Contrast::verdict(
                    (string) $get('theme.text'),
                    (string) $get('theme.background'),
                )))->columnSpanFull(),
            ]),

            Step::make('Restrictions')->icon('lucide-shield-check')->columns(2)->schema([
                TagsInput::make('blocked_countries')
                    ->label('Blocked countries')
                    ->helperText('ISO-2 codes. Offers are hidden from users in these countries.'),
                TextInput::make('daily_conversion_cap')
                    ->label('Daily conversion cap')
                    ->numeric()
                    ->minValue(1)
                    ->helperText('Blank means uncapped.'),
                Toggle::make('anonymize_ip')
                    ->label('Anonymise IPs')
                    ->helperText('Zeroes the last octet before storage. Applies to your own click log too.'),
                Toggle::make('test_mode')
                    ->label('Test mode')
                    ->helperText('Adds `&country=XX` to your wall URL so you can preview any geo, and pins a $0.01 test offer that runs the real conversion pipeline. Turn it off before you ship — while it is on, anyone with your wall URL can pick their own country.'),
            ]),

            Step::make('Integration')->icon('lucide-plug')->columns(2)->schema([
                TextInput::make('app_id')
                    ->label('App ID')
                    ->disabled()
                    ->dehydrated(false)
                    ->copyable()
                    ->placeholder('assigned when you save'),
                TextInput::make('secret_display')
                    ->label('Secret key')
                    ->disabled()
                    ->dehydrated(false)
                    ->copyable()
                    // Shown in full once, on the screen that created it.
                    // After that it is masked and regenerating is the only
                    // way back — a secret you can read forever is a secret
                    // that ends up in a support thread.
                    ->formatStateUsing(fn (?App $record): string => $record === null
                        ? 'generated when you save'
                        : session()->pull("app-secret.{$record->id}", Str::mask((string) $record->secret_key, '*', 4))),
                Textarea::make('snippet')
                    ->label('Embed this')
                    ->disabled()
                    ->dehydrated(false)
                    ->rows(3)
                    ->columnSpanFull()
                    ->formatStateUsing(fn (?App $record): string => self::snippet($record)),
                Toggle::make('require_signed_wall')
                    ->label('Require signed wall URLs')
                    ->helperText('When on, the iframe URL must carry a HMAC signature. Stops anyone loading your wall with someone else\'s user ID.')
                    ->live(),
                Text::make(fn (Get $get): HtmlString => new HtmlString(
                    $get('require_signed_wall')
                        ? '<code>sig = hash_hmac(\'sha256\', appid . userid, secret_key)</code>'
                        : '<span class="text-gray-400">Signing is off. Anyone who learns your app ID can render your wall.</span>'
                ))->columnSpanFull(),
            ]),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount([
                'offerClicks as clicks_7d' => fn (Builder $query) => $query->where('created_at', '>=', now()->subDays(7)),
                'conversions as conversions_7d' => fn (Builder $query) => $query->where('created_at', '>=', now()->subDays(7)),
            ])->withSum([
                'conversions as earnings_7d' => fn (Builder $query) => $query->where('created_at', '>=', now()->subDays(7)),
            ], 'payout'))
            ->columns([
                AppColumns::identity(),
                AppColumns::platform(),
                AppColumns::status(),
                AppColumns::postback(),
                ...AppColumns::activity('earnings_7d', 'Earnings'),
            ])
            ->filters([
                SelectFilter::make('status')->options(AppStatus::class),
                SelectFilter::make('platform')->options(AppPlatform::class),
            ])
            ->recordClasses(AppColumns::recordClasses(...))
            // "Settings" stays a visible button — it is the one action every
            // app needs regularly. The two integration tools sit behind the
            // ellipsis so a row does not read as three equal calls to action.
            ->recordActions([
                EditAction::make()
                    ->label('Settings')
                    ->icon('lucide-settings-2'),
                ActionGroup::make([
                    Action::make('copy_snippet')
                        ->label('Embed code')
                        ->icon('lucide-code')
                        ->modalHeading('Embed code')
                        ->modalSubmitAction(false)
                        ->modalContent(fn (App $record) => view('filament.publisher.snippet', ['snippet' => self::snippet($record)]))
                        ->action(fn () => Notification::make()->title('Copied from the modal')->send()),
                    PublisherTestPostbackAction::make(),
                ])
                    ->label('Integration')
                    ->icon('lucide-ellipsis')
                    ->tooltip('Integration tools'),
            ])
            ->emptyStateHeading('No apps yet')
            ->emptyStateDescription('Create one to get an app ID, a secret and an embed snippet.')
            ->emptyStateIcon('lucide-smartphone')
            // The heading promised a create step and gave nothing to press;
            // the header's CreateAction is the same one, repeated where the
            // reader already is.
            ->emptyStateActions([CreateAction::make()])
            // The only one of the three tables without a default order — rows
            // were arriving in primary-key order.
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApps::route('/'),
            'create' => CreateApp::route('/create'),
            'edit' => EditApp::route('/{record}/edit'),
        ];
    }

    /** @return array<int, string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'app_id'];
    }

    /** Suspended accounts are read-only — docs/08 §1. Pending accounts are not. */
    public static function canCreate(): bool
    {
        $publisher = CurrentPublisher::get();

        // An admin in this panel has no publisher row. `null !== Suspended`
        // used to be true, so they could submit and we wrote publisher_id=0,
        // which the foreign key then rejected.
        return $publisher !== null && $publisher->status !== PublisherStatus::Suspended;
    }

    /** The wall as the form currently describes it. */
    private static function preview(Get $get, ?App $record): HtmlString
    {
        $publisher = CurrentPublisher::get();
        $radius = $get('theme.radius');

        return new HtmlString(view('filament.publisher.wall-preview', [
            'vars' => WallContext::vars(WallContext::sanitiseTheme([
                'primary' => $get('theme.primary'),
                'background' => $get('theme.background'),
                'text' => $get('theme.text'),
                // Unit re-attached: form state carries the number, the
                // sanitiser whitelists the CSS length.
                'radius' => filled($radius) ? ((int) $radius).'px' : null,
                'dark' => $get('theme.dark'),
            ])),
            'appName' => (string) ($get('name') ?: 'Your app'),
            'currencyName' => (string) ($get('currency_name') ?: 'Coins'),
            'rate' => (float) ($get('currency_rate') ?: 1000),
            'revShare' => $publisher === null ? 70.0 : (float) $publisher->rev_share,
            'dark' => (bool) ($get('theme.dark') ?? true),
            'showSamples' => $record?->isActive() ?? false,
        ])->render());
    }

    /**
     * The real thing, for when there is a real thing to see: `WallGate` refuses
     * an app that is not active under an approved publisher, so before that the
     * link would only ever open the unavailable notice.
     *
     * The signature is the wall's own — sha256(appid + userid + secret) — so a
     * signed app's preview URL passes the same check a production embed does.
     */
    private static function liveWallLink(?App $record): string
    {
        if ($record === null || ! $record->isActive() || ! (bool) $record->publisher?->isApproved()) {
            return '<span class="text-xs text-gray-500">Your live wall opens from here once the app is approved and active.</span>';
        }

        $url = route('wall', array_filter([
            'appid' => $record->app_id,
            'userid' => 'PREVIEW',
            'sig' => $record->require_signed_wall
                ? hash('sha256', $record->app_id.'PREVIEW'.(string) $record->secret_key)
                : null,
        ]));

        return sprintf(
            '<a href="%s" target="_blank" rel="noopener" class="text-xs text-primary-400 underline underline-offset-2">'
            .'Open your live wall</a><span class="text-xs text-gray-500"> — real inventory, saved theme.</span>',
            e($url),
        );
    }

    public static function snippet(?App $record): string
    {
        $appId = $record === null ? 'YOUR_APP_ID' : (string) $record->app_id;

        return sprintf(
            '<iframe src="%s/?appid=%s&userid=USER_ID"'."\n".'        width="100%%" height="800" frameborder="0"></iframe>',
            rtrim((string) config('app.url'), '/').'/wall',
            $appId,
        );
    }

    /**
     * "A conversion that pays you $0.35 credits your player 350 Coins." —
     * recalculated as they type.
     *
     * The illustration is anchored on the publisher's own payout rather than
     * on an offer's gross, so it states the only arithmetic they implement
     * and discloses nothing about what sits upstream of it.
     */
    private const EXAMPLE_PAYOUT = 0.35;

    private static function currencyExample(Get $get): string
    {
        $rate = (float) $get('currency_rate');
        $name = (string) ($get('currency_name') ?: 'units');

        if ($rate <= 0) {
            return '<span class="text-danger-400">Set a rate above zero — at zero your users are paid nothing.</span>';
        }

        $reward = self::EXAMPLE_PAYOUT * $rate;

        return sprintf(
            '<span class="text-gray-300">A conversion that pays you <strong>$%s</strong> credits your player '
            .'<strong class="text-primary-400">%s %s</strong>.</span>',
            number_format(self::EXAMPLE_PAYOUT, 2),
            number_format($reward, $reward < 10 ? 2 : 0),
            e($name),
        );
    }

    private static function macroReference(): string
    {
        $macros = [
            '{user_id}' => 'the end user ID you passed into the wall',
            '{trans_id}' => 'our transaction ID — use this to deduplicate',
            '{offer_id}' => 'the offer ID at conversion time',
            '{offer_name}' => 'offer name at conversion time',
            '{payout_usd}' => 'your payout in USD, negative on a reversal',
            '{currency_amount}' => 'the same amount in your currency',
            '{app_id}' => 'this app',
            '{country}' => 'ISO-2 country of the click',
            '{ip}' => 'IP of the click',
            '{status}' => 'credited or reversed',
            '{timestamp}' => 'unix seconds',
            '{signature}' => 'sha256(trans_id + payout_usd + secret_key)',
        ];

        $rows = collect($macros)
            ->map(fn (string $meaning, string $macro): string => sprintf(
                '<div><dt>%s</dt><dd>%s</dd></div>',
                e($macro),
                e($meaning),
            ))
            ->implode('');

        return '<p class="pb-2 text-sm text-gray-300">Reversals arrive at the same URL with a negative payout and '
            .'<code>status=reversed</code>. Handle them, or your users keep currency you were charged back for.</p>'
            ."<dl class=\"app-macros\">{$rows}</dl>";
    }
}
