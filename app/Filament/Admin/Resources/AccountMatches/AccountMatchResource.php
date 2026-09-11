<?php

namespace App\Filament\Admin\Resources\AccountMatches;

use App\Enums\IdentityKind;
use App\Enums\IdentitySignal;
use App\Filament\Admin\Resources\AccountMatches\Pages\ListAccountMatches;
use App\Filament\Admin\Resources\Publishers\PublisherResource;
use App\Filament\Admin\Support\DateRangeFilter;
use App\Models\AccountMatch;
use App\Models\IdentitySession;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Shared IP / device / session between two accounts — admin inbox, not a fraud rule. */
class AccountMatchResource extends Resource
{
    protected static ?string $model = AccountMatch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static string|\UnitEnum|null $navigationGroup = 'Risk';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Linked accounts';

    protected static ?string $modelLabel = 'linked account';

    protected static ?string $pluralModelLabel = 'linked accounts';

    public static function getNavigationBadge(): ?string
    {
        $recent = AccountMatch::query()->recent()->count();

        return $recent > 0 ? (string) $recent : null;
    }

    public static function getRecordTitle(?Model $record): string
    {
        if (! $record instanceof AccountMatch) {
            return 'Linked account';
        }

        return $record->scope->value.' · '.$record->signal->value;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Section::make('Match')->columns(4)->schema([
                TextEntry::make('scope')->badge(),
                TextEntry::make('signal')->badge(),
                TextEntry::make('seen_count')->label('Seen'),
                TextEntry::make('signal_value')
                    ->label('Value')
                    ->fontFamily(FontFamily::Mono)
                    ->copyable()
                    ->placeholder('—'),
                TextEntry::make('first_seen_at')->label('First seen')->dateTime(),
                TextEntry::make('last_seen_at')->label('Last seen')->dateTime(),
                TextEntry::make('publisher.pub_id')
                    ->label('Publisher')
                    ->prefix('#')
                    ->placeholder('—')
                    ->url(fn (AccountMatch $record): ?string => $record->publisher_id === null
                        ? null
                        : PublisherResource::getUrl('view', ['record' => $record->publisher_id])),
            ]),

            Section::make('Accounts')->columns(2)->schema([
                TextEntry::make('leftUser.email')
                    ->label('Left')
                    ->placeholder(fn (AccountMatch $record): string => $record->left_end_user_id ?: '—')
                    ->copyable(),
                TextEntry::make('rightUser.email')
                    ->label('Right')
                    ->placeholder(fn (AccountMatch $record): string => $record->right_end_user_id ?: '—')
                    ->copyable(),
                TextEntry::make('left_end_user_id')
                    ->label('Left end user')
                    ->placeholder('—')
                    ->copyable()
                    ->visible(fn (AccountMatch $record): bool => $record->scope === IdentityKind::Wall),
                TextEntry::make('right_end_user_id')
                    ->label('Right end user')
                    ->placeholder('—')
                    ->copyable()
                    ->visible(fn (AccountMatch $record): bool => $record->scope === IdentityKind::Wall),
            ]),

            Section::make('Recent sessions')
                ->columnSpanFull()
                ->description('Hashed UA and ds only — the raw values are never stored.')
                ->schema([
                    RepeatableEntry::make('recent_sessions')
                        ->label('')
                        ->columnSpanFull()
                        ->state(fn (AccountMatch $record): array => self::recentSessions($record))
                        ->schema([
                            TextEntry::make('created_at')->label('When')->dateTime(),
                            TextEntry::make('ip')->label('IP')->fontFamily(FontFamily::Mono)->copyable(),
                            TextEntry::make('account')->label('Account'),
                            TextEntry::make('ua_hash')->label('UA hash')->fontFamily(FontFamily::Mono)->copyable(),
                            TextEntry::make('ds_hash')->label('ds hash')->fontFamily(FontFamily::Mono)->placeholder('—')->copyable(),
                        ])
                        ->columns(5),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'publisher:id,pub_id',
                'leftUser:id,name,email',
                'rightUser:id,name,email',
            ]))
            ->columns([
                TextColumn::make('last_seen_at')->label('Last seen')->dateTime()->sortable(),
                TextColumn::make('scope')->badge(),
                ViewColumn::make('signal')
                    ->label('Signal')
                    ->view('filament.tables.columns.identity-signal'),
                TextColumn::make('left_label')
                    ->label('Left')
                    ->state(fn (AccountMatch $record): string => $record->scope === IdentityKind::Panel
                        ? (string) ($record->leftUser?->email ?: $record->left_user_id)
                        : (string) ($record->left_end_user_id ?: '—'))
                    ->copyable()
                    ->fontFamily(FontFamily::Mono)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(function (Builder $query) use ($search): void {
                        $query->where('left_end_user_id', 'like', "%{$search}%")
                            ->orWhereHas('leftUser', fn (Builder $query) => $query->where('email', 'like', "%{$search}%"));
                    })),
                TextColumn::make('right_label')
                    ->label('Right')
                    ->state(fn (AccountMatch $record): string => $record->scope === IdentityKind::Panel
                        ? (string) ($record->rightUser?->email ?: $record->right_user_id)
                        : (string) ($record->right_end_user_id ?: '—'))
                    ->copyable()
                    ->fontFamily(FontFamily::Mono)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(function (Builder $query) use ($search): void {
                        $query->where('right_end_user_id', 'like', "%{$search}%")
                            ->orWhereHas('rightUser', fn (Builder $query) => $query->where('email', 'like', "%{$search}%"));
                    })),
                TextColumn::make('publisher.pub_id')->label('Pub')->prefix('#')->placeholder('—'),
                TextColumn::make('seen_count')->label('Count')->numeric()->alignEnd(),
                TextColumn::make('first_seen_at')->label('First seen')->dateTime()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('scope')->options(IdentityKind::class),
                SelectFilter::make('signal')->options(IdentitySignal::class),
                SelectFilter::make('publisher_id')->label('Publisher')->relationship('publisher', 'company_name')->searchable(),
                DateRangeFilter::make('last_seen_at'),
            ])
            ->recordActions([
                ViewAction::make()->slideOver(),
            ])
            ->toolbarActions([])
            ->defaultSort('last_seen_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccountMatches::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * @return list<array{created_at: mixed, ip: string, account: string, ua_hash: string, ds_hash: ?string}>
     */
    public static function recentSessions(AccountMatch $record): array
    {
        $query = IdentitySession::query()->orderByDesc('created_at')->limit(20);

        if ($record->scope === IdentityKind::Panel) {
            $query->where('kind', IdentityKind::Panel)
                ->whereIn('user_id', array_filter([$record->left_user_id, $record->right_user_id]));
        } else {
            $query->where('kind', IdentityKind::Wall)
                ->where('publisher_id', $record->publisher_id)
                ->whereIn('end_user_id', array_filter([$record->left_end_user_id, $record->right_end_user_id]));
        }

        return $query->get()->map(fn (IdentitySession $session): array => [
            'created_at' => $session->created_at,
            'ip' => $session->ip,
            'account' => $session->kind === IdentityKind::Panel
                ? (string) ($session->user_id ?? '—')
                : (string) ($session->end_user_id ?? '—'),
            'ua_hash' => $session->ua_hash,
            'ds_hash' => $session->ds_hash,
        ])->all();
    }
}
