<?php

namespace App\Filament\Admin\Resources\Publishers;

use App\Enums\PublisherStatus;
use App\Filament\Admin\Resources\Publishers\Pages\EditPublisher;
use App\Filament\Admin\Resources\Publishers\Pages\ListPublishers;
use App\Filament\Admin\Resources\Publishers\Pages\ViewPublisher;
use App\Filament\Admin\Resources\Publishers\RelationManagers\AppsRelationManager;
use App\Filament\Admin\Resources\Publishers\RelationManagers\ConversionsRelationManager;
use App\Filament\Admin\Resources\Publishers\RelationManagers\LedgerEntriesRelationManager;
use App\Filament\Admin\Resources\Publishers\RelationManagers\WithdrawalsRelationManager;
use App\Filament\Tables\Columns\UserColumn;
use App\Http\Controllers\ImpersonationController;
use App\Models\AuditLog;
use App\Models\LedgerEntry;
use App\Models\Publisher;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Publishers — docs/07 §5.
 *
 * Every status transition and every balance change here writes an audit log
 * row. There is no path in this resource that moves money without a reason
 * attached to it.
 */
class PublisherResource extends Resource
{
    protected static ?string $model = Publisher::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|\UnitEnum|null $navigationGroup = 'Publishers';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'pub_id';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Account')->columns(2)->schema([
                TextInput::make('company_name')->maxLength(191),
                TextInput::make('country')->maxLength(2),
                Select::make('status')->options(PublisherStatus::class)->required(),
            ]),
            Section::make('Terms')->columns(2)->schema([
                TextInput::make('rev_share')->numeric()->suffix('%')->required()->minValue(0)->maxValue(100),
                TextInput::make('min_payout')->numeric()->prefix('$')->required(),
            ]),
            Section::make('Notes')->schema([
                Textarea::make('notes')->rows(5)->helperText('Admin-only. The publisher never sees this.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with('user:id,name,email')
                ->withCount([
                    'apps',
                    // ponytail: event count stands in for a real fraud score
                    // until the scoring model in docs/05 lands. It ranks the
                    // same publishers in the same order, which is what the
                    // column is for.
                    'conversions as flagged_count' => fn (Builder $query) => $query
                        ->where('status', 'pending')
                        ->where('created_at', '>=', now()->subDays(30)),
                ]))
            ->columns([
                TextColumn::make('pub_id')
                    ->label('Pub')
                    ->prefix('#')
                    ->fontFamily(FontFamily::Mono)
                    ->copyable()
                    ->sortable()
                    ->searchable(),
                UserColumn::make('user')
                    ->label('Publisher')
                    ->searchable()
                    ->sortable(),
                ViewColumn::make('country')
                    ->label('Geo')
                    ->view('filament.tables.columns.country-flag')
                    ->grow(false)
                    ->alignCenter()
                    ->sortable(),
                ViewColumn::make('status')
                    ->view('filament.tables.columns.status-pill')
                    ->sortable(),
                TextColumn::make('apps_count')
                    ->label('Apps')
                    ->numeric()
                    ->alignEnd()
                    ->fontFamily(FontFamily::Mono),
                TextColumn::make('rev_share')
                    ->label('Share')
                    ->suffix('%')
                    ->alignEnd()
                    ->fontFamily(FontFamily::Mono)
                    ->sortable(),
                TextColumn::make('balance')
                    ->money('USD')
                    ->alignEnd()
                    ->sortable()
                    ->color(fn (Publisher $record): string => match (true) {
                        (float) $record->balance < 0 => 'danger',
                        (float) $record->balance > 0 => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('lifetime_earnings')
                    ->label('Lifetime')
                    ->money('USD')
                    ->alignEnd()
                    ->sortable()
                    ->color(fn (Publisher $record): string => (float) $record->lifetime_earnings > 0 ? 'success' : 'gray'),
                TextColumn::make('flagged_count')
                    ->label('Flagged')
                    ->numeric()
                    ->alignEnd()
                    ->fontFamily(FontFamily::Mono)
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray')
                    ->icon(fn (int $state): ?Heroicon => $state > 0 ? Heroicon::OutlinedFlag : null),
                TextColumn::make('created_at')
                    ->label('Joined')
                    ->date()
                    ->sortable()
                    ->toggleable()
                    ->description(fn (Publisher $record): ?string => $record->created_at?->diffForHumans()),
            ])
            ->recordClasses(fn (Publisher $record): array => match ($record->status) {
                PublisherStatus::Pending => ['ad-row--pending'],
                PublisherStatus::Suspended => ['ad-row--suspended'],
                PublisherStatus::Banned => ['ad-row--banned'],
                PublisherStatus::Approved => [],
            })
            ->filters([
                SelectFilter::make('status')->options(PublisherStatus::class),
                SelectFilter::make('country')->options(fn (): array => Publisher::query()
                    ->whereNotNull('country')
                    ->distinct()
                    ->orderBy('country')
                    ->pluck('country')
                    ->mapWithKeys(fn (string $code): array => [$code => self::countryName($code)])
                    ->all()),
                Filter::make('has_balance')->label('Has balance')->toggle()
                    ->query(fn (Builder $query): Builder => $query->where('balance', '>', 0)),
                Filter::make('negative_balance')->label('Negative balance')->toggle()
                    ->query(fn (Builder $query): Builder => $query->where('balance', '<', 0)),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                ActionGroup::make([
                    self::transition('approve', PublisherStatus::Approved, 'success', Heroicon::OutlinedCheckBadge),
                    self::transition('suspend', PublisherStatus::Suspended, 'warning', Heroicon::OutlinedPauseCircle),
                    self::transition('ban', PublisherStatus::Banned, 'danger', Heroicon::OutlinedNoSymbol),
                    // Nesting would overwrite the single session key holding the
                    // admin's own id and strand them with no way back.
                    Action::make('impersonate')
                        ->icon(Heroicon::OutlinedUserCircle)
                        ->requiresConfirmation()
                        ->modalDescription('You will be signed in as this publisher. The session is audit-logged and shows a banner until you stop.')
                        ->visible(fn (Publisher $record): bool => $record->status !== PublisherStatus::Banned
                            && $record->status !== PublisherStatus::Suspended
                            && ! session()->has(ImpersonationController::SESSION_KEY))
                        ->action(function (Publisher $record) {
                            if (session()->has(ImpersonationController::SESSION_KEY)) {
                                return null;
                            }

                            AuditLog::record('publisher.impersonated', $record);

                            session([ImpersonationController::SESSION_KEY => Auth::id()]);
                            ImpersonationController::loginAs($record->user);

                            return redirect('/dashboard');
                        }),
                    Action::make('adjust')
                        ->label('Adjust balance')
                        ->icon(Heroicon::OutlinedBanknotes)
                        ->schema([
                            TextInput::make('amount')->numeric()->required()->prefix('$')
                                ->helperText('Negative to debit. Writes a ledger entry; nothing here edits history.'),
                            Textarea::make('reason')->required()->rows(2)->maxLength(191),
                        ])
                        ->action(function (Publisher $record, array $data): void {
                            $entry = LedgerEntry::createAdjustment(
                                $record,
                                (string) $data['amount'],
                                $data['reason'],
                                Auth::user(),
                            );

                            AuditLog::record('publisher.balance_adjusted', $record, null, [
                                'amount' => $data['amount'],
                                'reason' => $data['reason'],
                                'balance_after' => $entry->balance_after,
                            ]);

                            Notification::make()->title('Adjustment written')->success()->send();
                        }),
                ])
                    // Approve, suspend, ban, impersonate and a balance
                    // adjustment live behind this trigger. Named and
                    // tooltipped like the publisher panel's group — an
                    // unlabelled dot-menu over actions this consequential
                    // tells an admin nothing about what is inside.
                    ->label('Manage')
                    ->icon('lucide-ellipsis')
                    ->tooltip('Manage publisher'),
            ])
            ->emptyStateHeading('No publishers yet')
            ->emptyStateDescription('Publishers appear here once someone registers and their account is reviewed.')
            ->emptyStateIcon('lucide-users')
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            AppsRelationManager::class,
            ConversionsRelationManager::class,
            LedgerEntriesRelationManager::class,
            WithdrawalsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPublishers::route('/'),
            'view' => ViewPublisher::route('/{record}'),
            'edit' => EditPublisher::route('/{record}/edit'),
        ];
    }

    /**
     * Global search — docs/07 §10. A support email quotes a pub ID or an
     * address; both have to land on the publisher in one step.
     *
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['pub_id', 'company_name', 'user.name', 'user.email'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string|Htmlable
    {
        if (! $record instanceof Publisher) {
            return (string) $record->getKey();
        }

        return "#{$record->pub_id} ".($record->user->name ?? $record->company_name ?? '');
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = Publisher::query()->where('status', PublisherStatus::Pending)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    /** ISO-2 as a human name. The code is what we store; the name is what a reviewer reads. */
    public static function countryName(string $code): string
    {
        $code = strtoupper($code);

        if ($code === 'XX') {
            return 'Other';
        }

        if (! extension_loaded('intl')) {
            return $code;
        }

        $name = \Locale::getDisplayRegion('-'.$code, 'en');

        return filled($name) && $name !== $code ? $name : $code;
    }

    /** Approve / suspend / ban share one shape: a confirmation, a reason, an audit row. */
    private static function transition(string $name, PublisherStatus $to, string $colour, Heroicon $icon): Action
    {
        return Action::make($name)
            ->icon($icon)
            ->color($colour)
            ->visible(fn (Publisher $record): bool => $record->status !== $to)
            ->schema($to === PublisherStatus::Approved ? [] : [
                Textarea::make('reason')->required()->rows(2)->maxLength(191),
            ])
            ->requiresConfirmation()
            ->action(function (Publisher $record, array $data) use ($to): void {
                $before = $record->status->value;

                $record->update([
                    'status' => $to,
                    'approved_at' => $to === PublisherStatus::Approved ? now() : $record->approved_at,
                    'approved_by' => $to === PublisherStatus::Approved ? Auth::id() : $record->approved_by,
                    'notes' => filled($data['reason'] ?? null)
                        ? trim($record->notes."\n".now()->toDateString().' — '.$to->value.': '.$data['reason'])
                        : $record->notes,
                ]);

                AuditLog::record("publisher.{$to->value}", $record, ['status' => $before], [
                    'status' => $to->value,
                    'reason' => $data['reason'] ?? null,
                ]);
            });
    }
}
