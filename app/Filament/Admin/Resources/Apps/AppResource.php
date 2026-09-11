<?php

namespace App\Filament\Admin\Resources\Apps;

use App\Enums\AppPlatform;
use App\Enums\AppStatus;
use App\Enums\PostbackMethod;
use App\Filament\Admin\Resources\Apps\Pages\EditApp;
use App\Filament\Admin\Resources\Apps\Pages\ListApps;
use App\Filament\Admin\Resources\Apps\Pages\ViewApp;
use App\Filament\Admin\Resources\Apps\RelationManagers\BannedEndUsersRelationManager;
use App\Filament\Admin\Resources\Apps\RelationManagers\BlacklistsRelationManager;
use App\Filament\Admin\Resources\Apps\RelationManagers\ConsentsRelationManager;
use App\Filament\Admin\Resources\Apps\RelationManagers\ConversionsRelationManager;
use App\Filament\Admin\Resources\Apps\RelationManagers\DailyStatsRelationManager;
use App\Filament\Admin\Resources\Apps\RelationManagers\FraudEventsRelationManager;
use App\Filament\Admin\Resources\Apps\RelationManagers\OfferClicksRelationManager;
use App\Filament\Admin\Resources\Apps\RelationManagers\OutgoingPostbacksRelationManager;
use App\Filament\Admin\Support\TestPostbackAction;
use App\Filament\Support\AppColumns;
use App\Filament\Support\AppNetworksField;
use App\Filament\Tables\Columns\UserColumn;
use App\Models\App;
use App\Models\AuditLog;
use App\Services\Money\MacroResolver;
use App\Services\Wall\WallContext;
use App\Services\Wall\WallGate;
use App\Support\Contrast;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Placements — docs/07 §5.
 *
 * The app is what the wall is served for, so approving one is the moment a
 * publisher's traffic starts costing us money. It is a deliberate action with
 * an audit row, not an inline toggle.
 */
class AppResource extends Resource
{
    protected static ?string $model = App::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    protected static string|\UnitEnum|null $navigationGroup = 'Publishers';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Grid::make(1)->schema([
                    Section::make('Placement')->columns(2)->schema([
                        TextInput::make('name')->required()->maxLength(191),
                        TextInput::make('url')->label('Website or store URL')->url()->maxLength(191),
                        Select::make('platform')->options(AppPlatform::class)->required(),
                        FileUpload::make('icon_path')->label('Icon')->image()->directory('app-icons')->imageEditor(),
                    ]),
                    Section::make('Currency')->columns(2)->schema([
                        TextInput::make('currency_name')->required()->maxLength(64),
                        TextInput::make('currency_rate')
                            ->label('Units per $1')
                            ->numeric()
                            ->required()
                            ->minValue(0.0001),
                    ]),
                    Section::make('Postback')->columns(1)->schema([
                        TextInput::make('postback_url')
                            ->label('Callback URL')
                            ->maxLength(2048)
                            // Same contract as the publisher form: this is a
                            // template, and `->url()` rejects `{user_id}`.
                            ->dehydrateStateUsing(fn (mixed $state): ?string => filled($state) ? trim((string) $state) : null)
                            ->rules([
                                fn (): Closure => MacroResolver::validateCallbackUrl(...),
                            ])
                            ->helperText('Leave blank if they poll the dashboard. We never retry into a URL they did not give us.'),
                        Select::make('postback_method')->options(PostbackMethod::class)->required(),
                    ]),
                ]),
                Grid::make(1)->schema([
                    Section::make('Integration')->columns(2)->schema([
                        TextInput::make('app_id')
                            ->label('App ID')
                            ->disabled()
                            ->dehydrated(false)
                            ->copyable(),
                        TextInput::make('secret_display')
                            ->label('Secret key')
                            ->disabled()
                            ->dehydrated(false)
                            ->formatStateUsing(fn (?App $record): string => $record === null
                                ? ''
                                : Str::mask((string) $record->secret_key, '*', 4)),
                        Toggle::make('require_signed_wall')
                            ->label('Require signed wall URLs')
                            ->helperText('When on, the iframe URL must carry a HMAC signature.')
                            ->columnSpanFull(),
                    ]),
                    Section::make('Appearance')->columns(2)->schema([
                        ColorPicker::make('theme.primary')->label('Primary')->default(WallContext::DEFAULT_THEME['primary']),
                        ColorPicker::make('theme.background')->label('Background')->default(WallContext::DEFAULT_THEME['background']),
                        ColorPicker::make('theme.text')->label('Text')->default(WallContext::DEFAULT_THEME['text']),
                        TextInput::make('theme.radius')
                            ->label('Corner radius (px)')
                            ->numeric()
                            ->default(4)
                            ->minValue(0)
                            ->maxValue(40)
                            ->formatStateUsing(fn (mixed $state): ?int => is_scalar($state) ? (int) $state : null)
                            ->dehydrateStateUsing(fn (mixed $state): ?string => filled($state) ? ((int) $state).'px' : null),
                        Toggle::make('theme.dark')->label('Dark wall')->default(true),
                        Text::make(fn (Get $get): HtmlString => new HtmlString(Contrast::verdict(
                            (string) $get('theme.text'),
                            (string) $get('theme.background'),
                        )))->columnSpanFull(),
                    ]),
                    Section::make('Restrictions')->columns(2)->schema([
                        TagsInput::make('blocked_countries')
                            ->label('Blocked countries')
                            ->helperText('ISO-2 codes.'),
                        TextInput::make('daily_conversion_cap')
                            ->label('Daily conversion cap')
                            ->numeric()
                            ->minValue(1)
                            ->helperText('Blank means uncapped.'),
                        Toggle::make('test_mode')
                            ->label('Test mode')
                            ->helperText('Pins a $0.01 test offer and lets the wall pick any geo.'),
                    ]),
                    Section::make('Networks')->schema([
                        AppNetworksField::make(),
                    ]),
                ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with('publisher.user:id,name,email')
                ->withCount([
                    'offerClicks as clicks_7d' => fn (Builder $query) => $query->where('created_at', '>=', now()->subDays(7)),
                    'conversions as conversions_7d' => fn (Builder $query) => $query->where('created_at', '>=', now()->subDays(7)),
                ])
                ->withSum([
                    'conversions as revenue_7d' => fn (Builder $query) => $query->where('created_at', '>=', now()->subDays(7)),
                ], 'revenue'))
            ->columns([
                AppColumns::identity(),
                UserColumn::make('publisher.user')
                    ->label('Publisher')
                    ->searchable()
                    ->sortable(),
                AppColumns::platform(),
                AppColumns::status(),
                AppColumns::postback(),
                ...AppColumns::activity('revenue_7d', 'Revenue'),
            ])
            ->recordClasses(AppColumns::recordClasses(...))
            ->filters([
                SelectFilter::make('status')->options(AppStatus::class),
                SelectFilter::make('platform')->options(AppPlatform::class),
                SelectFilter::make('publisher_id')->label('Publisher')->relationship('publisher', 'company_name')->searchable(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                // Named like the publisher panel's Integration group: this one
                // holds approve, pause, reject and the wall link, and a bare
                // dot-menu gives no clue which. A tooltip is the only label an
                // icon-only trigger gets.
                ActionGroup::make(self::managementActions())
                    ->label('Manage')
                    ->icon('lucide-ellipsis')
                    ->tooltip('Manage app'),
            ])
            // Apps arrive by publisher registration, never by an admin — so
            // the empty state explains where rows come from rather than
            // offering a create button that canCreate() refuses anyway.
            ->emptyStateHeading('No apps yet')
            ->emptyStateDescription('Apps appear here once a publisher creates a placement in their panel.')
            ->emptyStateIcon('lucide-smartphone')
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            OfferClicksRelationManager::class,
            ConversionsRelationManager::class,
            OutgoingPostbacksRelationManager::class,
            DailyStatsRelationManager::class,
            FraudEventsRelationManager::class,
            BlacklistsRelationManager::class,
            BannedEndUsersRelationManager::class,
            ConsentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApps::route('/'),
            'view' => ViewApp::route('/{record}'),
            'edit' => EditApp::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /** @return list<Action> */
    public static function managementActions(): array
    {
        return [
            self::transition('approve', AppStatus::Active, 'success', Heroicon::OutlinedCheckBadge),
            self::transition('pause', AppStatus::Paused, 'gray', Heroicon::OutlinedPauseCircle),
            self::transition('reject', AppStatus::Rejected, 'danger', Heroicon::OutlinedNoSymbol),
            TestPostbackAction::make(),
            Action::make('wall')
                ->label('Open wall')
                ->icon(Heroicon::OutlinedWindow)
                ->url(fn (App $record): string => self::wallUrl($record))
                ->openUrlInNewTab(),
        ];
    }

    /** @return array<int, string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['app_id', 'name'];
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = App::query()->where('status', AppStatus::Pending)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    /**
     * The live wall for this app, exactly as the publisher's iframe loads it.
     *
     * The signature is always appended. WallGate only verifies it when the app
     * has `require_signed_wall` on, so one URL that works either way beats a
     * branch that goes stale the day a publisher turns signing on — and the
     * end user ID has to survive WallGate's character whitelist.
     */
    private static function wallUrl(App $record): string
    {
        $appId = (string) $record->app_id;
        $userId = 'ADMIN_PREVIEW';

        return rtrim((string) config('app.url'), '/').'/wall?'.http_build_query([
            'appid' => $appId,
            'userid' => $userId,
            // The gate owns the formula; asking it is what keeps this preview
            // working the day the signing rule changes.
            'sig' => WallGate::sign($record, $userId),
        ]);
    }

    private static function transition(string $name, AppStatus $to, string $colour, Heroicon $icon): Action
    {
        return Action::make($name)
            ->icon($icon)
            ->color($colour)
            ->visible(fn (App $record): bool => $record->status !== $to)
            ->schema($to === AppStatus::Rejected ? [Textarea::make('reason')->required()->rows(2)] : [])
            ->requiresConfirmation()
            ->action(function (App $record, array $data) use ($to): void {
                $before = $record->status->value;

                $record->update([
                    'status' => $to,
                    'approved_at' => $to === AppStatus::Active ? ($record->approved_at ?? now()) : $record->approved_at,
                ]);

                AuditLog::record("app.{$to->value}", $record, ['status' => $before], [
                    'status' => $to->value,
                    'reason' => $data['reason'] ?? null,
                ]);
            });
    }
}
