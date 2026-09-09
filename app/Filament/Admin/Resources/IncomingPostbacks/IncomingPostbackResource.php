<?php

namespace App\Filament\Admin\Resources\IncomingPostbacks;

use App\Enums\IncomingPostbackResult;
use App\Filament\Admin\Resources\Conversions\ConversionResource;
use App\Filament\Admin\Resources\IncomingPostbacks\Pages\ListIncomingPostbacks;
use App\Filament\Admin\Support\DateRangeFilter;
use App\Models\AuditLog;
use App\Models\IncomingPostback;
use App\Models\Network;
use App\Models\OfferClick;
use App\Networks\NetworkDriverManager;
use App\Services\Money\ConversionProcessor;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Summarizers\Count;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Raw log of everything the networks sent us — docs/07 §8.
 *
 * When a network claims they sent a conversion, this table settles it. The
 * unmatched rows carry a **Match to click** action, because a truncated subid
 * is the single most common reason a real conversion never credited.
 */
class IncomingPostbackResource extends Resource
{
    protected static ?string $model = IncomingPostback::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownOnSquare;

    protected static string|\UnitEnum|null $navigationGroup = 'Logs';

    protected static ?int $navigationSort = 2;

    public static function getRecordTitle(?Model $record): string
    {
        if (! $record instanceof IncomingPostback) {
            return 'Incoming postback';
        }

        $partner = $record->network->name ?? $record->driver;

        return $partner.' #'.$record->id;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Result')->columnSpanFull()->columns(3)->schema([
                TextEntry::make('result')->badge(),
                TextEntry::make('created_at')->label('Received')->dateTime(),
                TextEntry::make('conversion.trans_id')->label('Conversion')
                    ->placeholder('—')
                    ->copyable()
                    ->url(fn (IncomingPostback $record): ?string => self::conversionUrl($record)),
                TextEntry::make('reject_reason')->label('Reason')->placeholder('—')->columnSpanFull(),
                TextEntry::make('network.name')
                    ->label('Network')
                    ->state(fn (IncomingPostback $record): string => $record->network->name ?? $record->driver),
                TextEntry::make('driver')->badge(),
                TextEntry::make('method')->badge(),
                TextEntry::make('ip')->label('IP')->copyable(),
                IconEntry::make('signature_valid')->label('Signature')->boolean(),
                IconEntry::make('ip_allowed')->label('IP allowed')->boolean(),
            ]),

            Section::make('What they sent')->columnSpanFull()->columns(3)->schema([
                TextEntry::make('payout')
                    ->state(fn (IncomingPostback $record): ?string => $record->queryValue('payout', 'payout_usd', 'pay', 'revenue', 'payout_amount'))
                    ->placeholder('—'),
                TextEntry::make('user_payout')
                    ->label('User payout')
                    ->state(fn (IncomingPostback $record): ?string => $record->queryValue('user_payout', 'user_payout_in_vc'))
                    ->placeholder('—'),
                TextEntry::make('postback_id')
                    ->label('Postback ID')
                    ->state(fn (IncomingPostback $record): ?string => $record->queryValue('postback_id', 'transaction_id', 'txid', 'convid', 'conversion_id'))
                    ->placeholder('—')
                    ->copyable(),
                TextEntry::make('click_id')
                    ->label('Click ID')
                    ->state(fn (IncomingPostback $record): ?string => $record->queryValue('click_id', 'aff_sub1', 'subid', 'subId', 'custom_1'))
                    ->placeholder('—')
                    ->copyable(),
                TextEntry::make('user_id')
                    ->label('User ID')
                    ->state(fn (IncomingPostback $record): ?string => $record->queryValue('user_id', 'userId', 'USER_ID'))
                    ->placeholder('—')
                    ->copyable(),
                TextEntry::make('event')
                    ->state(fn (IncomingPostback $record): ?string => $record->queryValue('event_name', 'eventName', 'EVENT_NAME', 'goalname'))
                    ->placeholder('—'),
                TextEntry::make('campaign')
                    ->state(fn (IncomingPostback $record): ?string => $record->queryValue('campaign_name', 'offer_name', 'offerName', 'campaign_id', 'offer_id'))
                    ->placeholder('—'),
                TextEntry::make('country')
                    ->state(fn (IncomingPostback $record): ?string => $record->queryValue('conversion_country', 'country'))
                    ->placeholder('—'),
                TextEntry::make('query_string')
                    ->label('Query string')
                    ->state(fn (IncomingPostback $record): string => filled($record->query) ? http_build_query($record->query) : '—')
                    ->copyable()
                    ->columnSpanFull(),
            ]),

            Section::make('Query')->columnSpanFull()->schema([
                KeyValueEntry::make('query_pairs')
                    ->label('')
                    ->state(fn (IncomingPostback $record): array => $record->stringMap($record->query)),
            ]),

            Section::make('Body')
                ->columnSpanFull()
                ->collapsed()
                ->visible(fn (IncomingPostback $record): bool => filled($record->body))
                ->schema([
                    KeyValueEntry::make('body_pairs')
                        ->label('')
                        ->state(fn (IncomingPostback $record): array => $record->stringMap($record->body)),
                ]),

            Section::make('Headers')
                ->columnSpanFull()
                ->collapsed()
                ->visible(fn (IncomingPostback $record): bool => filled($record->headers))
                ->schema([
                    KeyValueEntry::make('header_pairs')
                        ->label('')
                        ->state(fn (IncomingPostback $record): array => $record->stringMap($record->headers)),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['network:id,name,driver', 'conversion:id,trans_id']))
            ->columns([
                TextColumn::make('created_at')->label('Received')->dateTime()->sortable()
                    ->summarize(Count::make()->label('Postbacks')),
                TextColumn::make('network.name')->label('Network')
                    ->placeholder(fn (IncomingPostback $record): string => $record->driver)
                    ->description(fn (IncomingPostback $record): string => $record->driver)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('driver', 'like', "%{$search}%")
                        ->orWhereHas('network', fn (Builder $network): Builder => $network->where('name', 'like', "%{$search}%"))),
                TextColumn::make('result')->badge()->sortable(),
                TextColumn::make('payout')
                    ->state(fn (IncomingPostback $record): ?string => $record->queryValue('payout', 'payout_usd', 'pay', 'revenue', 'payout_amount'))
                    ->placeholder('—')
                    ->alignEnd(),
                TextColumn::make('user_payout')
                    ->label('User payout')
                    ->state(fn (IncomingPostback $record): ?string => $record->queryValue('user_payout', 'user_payout_in_vc'))
                    ->placeholder('—')
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('click_id')
                    ->label('Click ID')
                    ->state(fn (IncomingPostback $record): ?string => $record->queryValue('click_id', 'aff_sub1', 'subid', 'subId', 'custom_1'))
                    ->placeholder('—')
                    ->copyable()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('query', 'like', "%{$search}%")),
                TextColumn::make('user_id')
                    ->label('User')
                    ->state(fn (IncomingPostback $record): ?string => $record->queryValue('user_id', 'userId', 'USER_ID'))
                    ->placeholder('—')
                    ->copyable(),
                TextColumn::make('event')
                    ->state(fn (IncomingPostback $record): ?string => $record->queryValue('event_name', 'eventName', 'EVENT_NAME', 'goalname'))
                    ->placeholder('—')
                    ->description(fn (IncomingPostback $record): ?string => $record->queryValue('campaign_name', 'offer_name', 'campaign_id', 'offer_id'))
                    ->wrap(),
                TextColumn::make('conversion.trans_id')->label('Conversion')
                    ->placeholder('—')
                    ->copyable()
                    ->copyableState(fn (?string $state): ?string => $state)
                    ->limit(16)
                    ->searchable()
                    ->url(fn (IncomingPostback $record): ?string => self::conversionUrl($record)),
                TextColumn::make('reject_reason')->label('Reason')
                    ->placeholder('—')
                    ->wrap()
                    ->limit(48)
                    ->toggleable(),
                TextColumn::make('ip')->label('IP')->searchable()->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('signature_valid')->label('Sig')->boolean()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('ip_allowed')->label('IP ok')->boolean()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            // Grouped by reason, "why is this network not crediting" is one
            // screen: the reject reasons with their counts, newest first.
            ->groups([
                Group::make('driver')->label('Driver'),
                Group::make('network.name')->label('Network'),
                Group::make('result')->label('Result'),
                Group::make('reject_reason')->label('Reason'),
                Group::make('created_at')->label('Date')->date(),
            ])
            ->filters([
                SelectFilter::make('network_id')->label('Network')->relationship('network', 'name'),
                SelectFilter::make('result')->options(IncomingPostbackResult::class),
                Filter::make('unmatched')
                    ->label('Unmatched only')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->where('result', IncomingPostbackResult::UnknownClick)),
                DateRangeFilter::make(),
            ])
            ->recordActions([
                ViewAction::make()->slideOver()->modalWidth(Width::TwoExtraLarge),
                Action::make('match')
                    ->label('Match to click')
                    ->icon(Heroicon::OutlinedLink)
                    ->visible(fn (IncomingPostback $record): bool => $record->result === IncomingPostbackResult::UnknownClick)
                    ->schema([
                        TextInput::make('click_id')
                            ->label('Click ID')
                            ->required()
                            ->helperText('The UUID from offer_clicks this postback belongs to.'),
                    ])
                    ->action(fn (IncomingPostback $record, array $data) => self::matchToClick($record, $data['click_id'])),
            ])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIncomingPostbacks::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canView(Model $record): bool
    {
        return true;
    }

    private static function conversionUrl(IncomingPostback $record): ?string
    {
        return $record->conversion_id === null
            ? null
            : ConversionResource::getUrl('view', ['record' => $record->conversion_id]);
    }

    /**
     * Re-runs the normal credit path with the click supplied by hand.
     *
     * The driver re-parses the stored payload rather than the admin retyping
     * the fields, so a manually attached conversion is identical to one that
     * matched on its own.
     */
    private static function matchToClick(IncomingPostback $log, string $clickId): void
    {
        $network = $log->network;

        if (! $network instanceof Network) {
            Notification::make()->title('This row has no network')->danger()->send();

            return;
        }

        $click = OfferClick::query()->withoutGlobalScopes()->where('click_id', $clickId)->first();

        if ($click === null) {
            Notification::make()->title('No click with that ID')->danger()->send();

            return;
        }

        $manager = app(NetworkDriverManager::class);

        if (! $manager->has($network->driver)) {
            Notification::make()->title("No driver registered for {$network->driver}")->danger()->send();

            return;
        }

        $request = Request::create('/postback/'.$network->driver, 'GET', $log->query ?? []);
        $dto = $manager->make($network)->parsePostback($request);

        if ($dto === null) {
            Notification::make()->title('The stored payload no longer parses')->danger()->send();

            return;
        }

        $conversion = app(ConversionProcessor::class)->record($dto->withClickId($clickId), $network, $log);

        AuditLog::record('incoming_postback.matched', $log, null, [
            'click_id' => $clickId,
            'conversion_id' => $conversion?->id,
        ]);

        Notification::make()
            ->title($conversion === null ? 'Still unmatched' : "Credited as {$conversion->trans_id}")
            ->status($conversion === null ? 'warning' : 'success')
            ->send();
    }
}
