<?php

namespace App\Filament\Publisher\Pages;

use App\Enums\AppStatus;
use App\Enums\BlacklistType;
use App\Enums\ConversionType;
use App\Enums\DeviceType;
use App\Filament\Publisher\Resources\OfferClicks\OfferClickResource;
use App\Filament\Publisher\Support\CurrentPublisher;
use App\Filament\Publisher\Support\EligibleOffers;
use App\Filament\Publisher\Support\PublisherPayout;
use App\Filament\Support\InitialsChip;
use App\Filament\Support\ViewOfferEventsAction;
use App\Models\App;
use App\Models\Blacklist;
use App\Models\Offer;
use App\Networks\NetworkDriverManager;
use App\Networks\OfferCacheFlusher;
use App\Support\UserReward;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

/**
 * The browsable catalogue — docs/08 §5.
 *
 * **The publisher sees their own payout, never the network's.** Exposing what a
 * network pays us turns every renewal into a margin negotiation, and the
 * rev-share percentage they agreed to already tells them what they are entitled
 * to know. Nothing on this page reads `offers.payout` without applying the
 * share first.
 *
 * Eligibility is per app — blacklists and country blocks differ between
 * placements — so the selector defaults to their first active app and never to
 * "all".
 */
class Offers extends Page implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'lucide-layout-grid';

    protected static string|\UnitEnum|null $navigationGroup = 'Monetize';

    protected static ?int $navigationSort = 11;

    protected static ?string $title = 'Offers';

    protected string $view = 'filament.publisher.pages.offers';

    #[Url]
    public ?int $appId = null;

    public function mount(): void
    {
        $this->appId ??= App::query()
            ->where('publisher_id', CurrentPublisher::id())
            ->where('status', AppStatus::Active)
            ->orderBy('id')
            ->value('id');
    }

    /** @return array<int, string> */
    public function getAppOptionsProperty(): array
    {
        return App::query()
            ->where('publisher_id', CurrentPublisher::id())
            ->pluck('name', 'id')
            ->all();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => EligibleOffers::query($this->appId)
                // `driver` is part of the load, not an extra: the preview link
                // resolves the network's driver per row, and a column left out
                // of the select reads as null and fails the page. The name is
                // not loaded — which network an offer came from is ours, not
                // the publisher's, and a column nobody renders is a leak
                // waiting for someone to add a tooltip.
                ->with('network:id,driver')
                ->withCount([
                    'offerClicks as clicks_7d' => fn (Builder $query) => $query
                        ->where('publisher_id', CurrentPublisher::id())
                        ->where('created_at', '>=', now()->subDays(7)),
                    'conversions as conversions_7d' => fn (Builder $query) => $query
                        ->where('publisher_id', CurrentPublisher::id())
                        ->where('created_at', '>=', now()->subDays(7)),
                ]))
            ->columns([
                ImageColumn::make('icon_url')
                    ->label('')
                    ->circular()
                    ->imageSize(32)
                    // A missing icon left a hole in the row and a dead one left
                    // the browser's broken-image glyph. The fallback is drawn
                    // from the offer's own name — never the network's, which a
                    // publisher must not be able to infer (AGENTS.md §1–2).
                    // `defaultImageUrl` covers a null column; `onerror` covers
                    // an icon URL that stops resolving, which happens on its
                    // own schedule upstream.
                    ->defaultImageUrl(fn (Offer $record): string => InitialsChip::for($record->name))
                    ->extraImgAttributes(fn (Offer $record): array => [
                        'onerror' => "this.onerror=null;this.src='".InitialsChip::for($record->name)."'",
                    ]),
                TextColumn::make('name')->searchable()->limit(40)->wrap(),
                TextColumn::make('your_payout')
                    ->label('Your payout')
                    ->state(fn (Offer $record): string => PublisherPayout::label($record->payout))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('payout', $direction)),
                TextColumn::make('user_reward')
                    ->label('User reward')
                    ->state(fn (Offer $record): string => $this->reward($record))
                    // Sorted by the network payout, which is what the reward is
                    // derived from: the share and the app's rate are the same
                    // multipliers on every row, so the order is identical and
                    // the database does not have to compute the reward to sort
                    // by it.
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('payout', $direction)),
                // Views, not formatters: Filament hands a `formatStateUsing`
                // closure one array item at a time, so a whole-list renderer
                // never sees the list.
                ViewColumn::make('countries')->label('Geo')->view('filament.tables.columns.geo-stack'),
                ViewColumn::make('devices')->view('filament.tables.columns.device-stack'),
                TextColumn::make('conversion_type')
                    ->label('Type')
                    ->badge(),
                TextColumn::make('clicks_7d')->label('Clicks 7d')->numeric()->sortable(),
                TextColumn::make('conversions_7d')->label('Conv 7d')->numeric()->sortable(),
                TextColumn::make('cr_7d')
                    ->label('CR 7d')
                    ->state(function (Offer $record): string {
                        $clicks = (int) $record->getAttribute('clicks_7d');
                        $conversions = (int) $record->getAttribute('conversions_7d');

                        return $clicks > 0 ? number_format($conversions / $clicks * 100, 2).'%' : '—';
                    })
                    // The ratio is computed in SQL over the two withCount
                    // aliases. An offer with no clicks has no rate at all, so
                    // it sorts below a genuine 0% rather than tying with it —
                    // "nobody tried this" and "nobody converted" are different
                    // answers and the publisher is reading the column to tell
                    // them apart.
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw(
                        'CASE WHEN clicks_7d = 0 THEN -1 ELSE conversions_7d / clicks_7d END '
                        .($direction === 'desc' ? 'desc' : 'asc')
                    )),
            ])
            ->filters([
                SelectFilter::make('conversion_type')->options(ConversionType::class),
                Filter::make('country')
                    ->schema([TextInput::make('country')->label('Country code')->maxLength(2)])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['country'] ?? null)
                        ? $query->whereJsonContains('countries', strtoupper($data['country']))
                        : $query),
                SelectFilter::make('device')
                    ->options(DeviceType::class)
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereJsonContains('devices', $data['value'])
                        : $query),
                Filter::make('payout')
                    ->schema([
                        TextInput::make('min')->numeric()->prefix('$')->label('Min payout to you'),
                        TextInput::make('max')->numeric()->prefix('$')->label('Max payout to you'),
                    ])
                    // Compared against the network payout scaled up by the
                    // publisher's share, so the filter matches the column the
                    // publisher is actually looking at.
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(filled($data['min'] ?? null), fn (Builder $q) => $q->where('payout', '>=', $this->grossFor((string) $data['min'])))
                        ->when(filled($data['max'] ?? null), fn (Builder $q) => $q->where('payout', '<=', $this->grossFor((string) $data['max'])))),
                Filter::make('has_conversions')
                    ->label('Converted for me')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'conversions',
                        fn (Builder $q) => $q->where('publisher_id', CurrentPublisher::id())
                    )),
            ])
            ->recordActions([
                ViewOfferEventsAction::make(
                    publisherFacing: true,
                    resolveApp: fn (): ?App => $this->appId === null
                        ? null
                        : App::query()->with('publisher')->find($this->appId),
                ),
                ActionGroup::make([
                    Action::make('block')
                        ->label('Block')
                        ->icon(Heroicon::OutlinedNoSymbol)
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalDescription(fn (): string => $this->appId === null
                            ? 'This blocks the offer across all of your apps.'
                            : 'This removes the offer from this app\'s wall within seconds. Your other apps keep it.')
                        ->action(fn (Offer $record) => $this->block($record)),
                    Action::make('preview')
                        ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                        ->url(fn (Offer $record): string => $this->previewUrl($record), shouldOpenInNewTab: true),
                    // docs/08 §5's third row action. It points at the clicks log
                    // rather than Statistics because `daily_stats` aggregates
                    // without an offer dimension — clicks are the only surface that
                    // can answer "how is *this* offer doing", and the filter is
                    // pre-applied so the publisher does not have to find the offer
                    // a second time.
                    Action::make('performance')
                        ->label('View performance')
                        ->icon(Heroicon::OutlinedChartBar)
                        ->url(fn (Offer $record): string => OfferClickResource::getUrl('index', [
                            'tableFilters' => [
                                'offer_id' => ['value' => $record->id],
                                ...($this->appId === null ? [] : ['app_id' => ['value' => $this->appId]]),
                            ],
                        ])),
                ])
                    ->icon('lucide-ellipsis')
                    ->tooltip('More actions'),
            ])
            ->defaultSort('payout', 'desc')
            ->emptyStateHeading(fn (): string => EligibleOffers::catalogueOpen($this->appId)
                ? 'Nothing eligible'
                : 'Waiting for approval')
            ->emptyStateDescription(fn (): string => EligibleOffers::catalogueOpen($this->appId)
                ? 'No active offers match this app right now. Check your blacklists and blocked countries.'
                : ($this->appId === null
                    ? 'Offers appear after your account and an app are approved.'
                    : 'Offers appear after your account and this app are approved.'));
    }

    /**
     * Only the payout filter needs the raw share — every figure this page
     * prints comes from PublisherPayout, which is the one place the share is
     * applied. Null means the session has no publisher: an admin looking at
     * the panel, not a publisher on a zero share.
     */
    private function revShare(): ?float
    {
        $publisher = CurrentPublisher::get();

        return $publisher === null ? null : (float) $publisher->rev_share;
    }

    /** The same figure in the app's own currency, at the app's own rate. */
    private function reward(Offer $offer): string
    {
        $app = $this->appId === null ? null : App::query()->find($this->appId);
        $publisher = CurrentPublisher::get();

        if ($app === null || $publisher === null) {
            return '—';
        }

        return UserReward::forApp($offer->payout, $publisher->rev_share, $app);
    }

    /** Turn a publisher-facing payout back into the network payout it filters on. */
    private function grossFor(string $net): string
    {
        $revShare = $this->revShare();

        return ($revShare === null || $revShare <= 0) ? '0' : bcdiv(bcmul($net, '100', 8), (string) $revShare, 6);
    }

    private function block(Offer $offer): void
    {
        $publisherId = CurrentPublisher::id();

        Blacklist::query()->updateOrCreate([
            'publisher_id' => $publisherId,
            'app_id' => $this->appId,
            'type' => BlacklistType::Offer,
            'value' => (string) $offer->id,
        ], [
            'reason' => 'Blocked from the offers page',
        ]);

        $apps = $this->appId === null
            ? App::query()->where('publisher_id', $publisherId)->get()
            : App::query()->whereKey($this->appId)->get();

        foreach ($apps as $app) {
            OfferCacheFlusher::flushForApp($app);
        }

        Notification::make()
            ->title('Blocked')
            ->body('It will stop appearing on the wall within seconds.')
            ->send();
    }

    private function previewUrl(Offer $offer): string
    {
        $manager = app(NetworkDriverManager::class);
        $network = $offer->network;

        return $manager->has($network->driver)
            ? $manager->make($network)->buildTrackingUrl($offer, 'publisher-preview')
            : $offer->tracking_url;
    }
}
