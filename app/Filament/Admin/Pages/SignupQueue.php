<?php

namespace App\Filament\Admin\Pages;

use App\Enums\PublisherStatus;
use App\Filament\Admin\Resources\Publishers\PublisherResource;
use App\Filament\Tables\Columns\UserColumn;
use App\Models\AuditLog;
use App\Models\Publisher;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Textarea;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Pending publishers — docs/07 §5.
 *
 * A dedicated page rather than a filter on the publishers table, because of one
 * column: whether this applicant's email or IP already belongs to a banned
 * account. That signal is what makes the queue reviewable in seconds, and it
 * does not belong on a table used for everything else.
 */
class SignupQueue extends Page implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|\UnitEnum|null $navigationGroup = 'Publishers';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Signup queue';

    protected string $view = 'filament.admin.pages.signup-queue';

    public static function getNavigationBadge(): ?string
    {
        $pending = Publisher::query()->where('status', PublisherStatus::Pending)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    /**
     * Banned emails and IPs, loaded once per request. The match column is why
     * this page exists — it cannot query the banned set once per row.
     *
     * @var array{emails: array<string, true>, ips: array<string, true>}|null
     */
    private ?array $bannedFingerprints = null;

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Publisher::query()
                ->with('user:id,name,email,last_login_ip')
                ->where('status', PublisherStatus::Pending))
            ->columns([
                TextColumn::make('pub_id')
                    ->label('Pub')
                    ->prefix('#')
                    ->fontFamily(FontFamily::Mono)
                    ->copyable()
                    ->searchable(),
                UserColumn::make('user')
                    ->label('Applicant')
                    ->searchable()
                    ->sortable(),
                ViewColumn::make('country')
                    ->label('Geo')
                    ->view('filament.tables.columns.country-flag')
                    ->grow(false)
                    ->alignCenter(),
                TextColumn::make('user.last_login_ip')
                    ->label('IP')
                    ->fontFamily(FontFamily::Mono)
                    ->copyable()
                    ->placeholder('—'),
                ViewColumn::make('match')
                    ->label('Banned match')
                    ->view('filament.tables.columns.banned-match')
                    ->state(fn (Publisher $record): array => $this->bannedHits($record))
                    ->grow(false),
                TextColumn::make('traffic_source')
                    ->label('Traffic')
                    ->wrap()
                    ->limit(72)
                    ->tooltip(fn (Publisher $record): ?string => filled($record->traffic_source)
                        ? $record->traffic_source
                        : null)
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Applied')
                    ->date()
                    ->sortable()
                    ->description(fn (Publisher $record): ?string => $record->created_at?->diffForHumans()),
            ])
            ->recordClasses(fn (Publisher $record): array => $this->bannedHits($record) === []
                ? []
                : ['ad-row--pending'])
            ->recordActions([
                Action::make('approve')
                    ->icon(Heroicon::OutlinedCheckBadge)
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(fn (Publisher $record) => $this->decide($record, PublisherStatus::Approved, null)),
                Action::make('reject')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->schema([Textarea::make('reason')->required()->rows(2)->maxLength(191)])
                    ->action(fn (Publisher $record, array $data) => $this->decide($record, PublisherStatus::Banned, $data['reason'])),
                Action::make('view')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (Publisher $record): string => PublisherResource::getUrl('view', ['record' => $record])),
            ])
            ->defaultSort('created_at', 'asc')
            ->emptyStateHeading('Nothing waiting')
            ->emptyStateDescription('Every application has been reviewed.');
    }

    private function decide(Publisher $publisher, PublisherStatus $to, ?string $reason): void
    {
        $before = $publisher->status->value;

        $publisher->update([
            'status' => $to,
            'approved_at' => $to === PublisherStatus::Approved ? now() : null,
            'approved_by' => Auth::id(),
            'notes' => filled($reason)
                ? trim($publisher->notes."\n".now()->toDateString().' — rejected: '.$reason)
                : $publisher->notes,
        ]);

        AuditLog::record("publisher.{$to->value}", $publisher, ['status' => $before], [
            'status' => $to->value,
            'reason' => $reason,
        ]);
    }

    /**
     * Does this applicant look like an account we already banned?
     *
     * Email is matched on the local part with any `+tag` stripped, so
     * `me+1@gmail.com` collides with `me@gmail.com` — the trick that makes a
     * plain equality check useless here.
     *
     * ponytail: `users.last_login_ip` stands in for a signup IP, which the
     * schema does not carry. It is populated on first sign-in, which is early
     * enough to be useful and honest about what it is.
     *
     * @return list<string>
     */
    private function bannedHits(Publisher $publisher): array
    {
        $prints = $this->bannedFingerprints();
        $hits = [];

        $email = self::normaliseEmail((string) $publisher->user?->email);

        if ($email !== '' && isset($prints['emails'][$email])) {
            $hits[] = 'email';
        }

        $ip = $publisher->user?->last_login_ip;

        if (is_string($ip) && $ip !== '' && isset($prints['ips'][$ip])) {
            $hits[] = 'IP';
        }

        return $hits;
    }

    /**
     * @return array{emails: array<string, true>, ips: array<string, true>}
     */
    private function bannedFingerprints(): array
    {
        return $this->bannedFingerprints ??= $this->loadBannedFingerprints();
    }

    /**
     * @return array{emails: array<string, true>, ips: array<string, true>}
     */
    private function loadBannedFingerprints(): array
    {
        $banned = Publisher::query()
            ->with('user:id,email,last_login_ip')
            ->where('status', PublisherStatus::Banned)
            ->get();

        $emails = [];
        $ips = [];

        foreach ($banned as $other) {
            $email = self::normaliseEmail((string) $other->user?->email);

            if ($email !== '') {
                $emails[$email] = true;
            }

            $ip = $other->user?->last_login_ip;

            if (is_string($ip) && $ip !== '') {
                $ips[$ip] = true;
            }
        }

        return ['emails' => $emails, 'ips' => $ips];
    }

    private static function normaliseEmail(string $email): string
    {
        if (! str_contains($email, '@')) {
            return '';
        }

        [$local, $domain] = explode('@', Str::lower($email), 2);

        return Str::before($local, '+').'@'.$domain;
    }
}
