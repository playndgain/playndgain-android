<?php

namespace App\Filament\Publisher\Auth;

use App\Filament\Publisher\Support\CurrentPublisher;
use App\Models\Publisher;
use App\Services\Wall\DeviceDetector;
use DateTimeZone;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use SensitiveParameter;

/**
 * My Profile — docs/08 §14.
 *
 * Filament's page already carries name, email, password and the two-factor
 * block. What it has no opinion about is the rest of the section: the
 * notification preferences, the timezone that drives every date in the panel,
 * and the browser session list.
 *
 * The two preference columns live on `publishers`, not on `users`, so they are
 * merged into the form state on fill and pulled back out before the user row
 * is written.
 */
class EditProfile extends BaseEditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getNameFormComponent(),
            $this->getEmailFormComponent(),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
            $this->getCurrentPasswordFormComponent(),
            $this->getTimezoneFormComponent(),
            $this->getNotificationPreferencesFormComponent(),
        ]);
    }

    /**
     * Every date in the panel is rendered through FilamentTimezone, which the
     * panel provider points at this column. A publisher reconciling a payout
     * against their own books is comparing dates, and a date in a timezone
     * nobody told them about is a support thread.
     */
    protected function getTimezoneFormComponent(): Component
    {
        return Select::make('timezone')
            ->label('Timezone')
            ->options(array_combine(
                DateTimeZone::listIdentifiers(),
                DateTimeZone::listIdentifiers(),
            ))
            ->searchable()
            ->native(false)
            ->placeholder(config('app.timezone').' (platform default)')
            ->helperText('Every date and time shown in this panel is rendered in it.')
            // Hidden for an admin looking at the panel without a publisher row:
            // there is nowhere to store it and nothing it would change.
            ->visible(fn (): bool => CurrentPublisher::get() !== null);
    }

    /**
     * A list of enabled keys in the form, a full map of every key in the
     * column. The map is what lets `Publisher::wantsNotification()` tell
     * "switched off" from "never opened this page" — with a list, unticking
     * everything and never visiting are the same stored value.
     */
    protected function getNotificationPreferencesFormComponent(): Component
    {
        return CheckboxList::make('notification_preferences')
            ->label('Email me about')
            ->options(Publisher::NOTIFICATION_TYPES)
            // Said here rather than only in a changelog: a checkbox that
            // silently does nothing is worse than an absent one.
            ->helperText('A credit note is emailed when a payout is marked paid. The monthly run emails you if it cannot request a payout. Postback, account, and monthly summary senders are not live yet.')
            ->visible(fn (): bool => CurrentPublisher::get() !== null);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $publisher = CurrentPublisher::get();

        $data['timezone'] = $publisher?->timezone;
        $data['notification_preferences'] = array_keys(array_filter(
            Publisher::NOTIFICATION_TYPES,
            fn (string $label, string $type): bool => (bool) $publisher?->wantsNotification($type),
            ARRAY_FILTER_USE_BOTH,
        ));

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, #[SensitiveParameter] array $data): Model
    {
        $timezone = Arr::pull($data, 'timezone');
        $enabled = Arr::pull($data, 'notification_preferences');

        $publisher = CurrentPublisher::get();

        if ($publisher !== null) {
            $enabled = is_array($enabled) ? $enabled : [];

            $publisher->update([
                // Blank is "platform default", stored as null so a later change
                // to the default reaches everyone who never picked one.
                'timezone' => is_string($timezone) && $timezone !== '' ? $timezone : null,
                'notification_preferences' => array_map(
                    fn (string $type): bool => in_array($type, $enabled, true),
                    array_combine(array_keys(Publisher::NOTIFICATION_TYPES), array_keys(Publisher::NOTIFICATION_TYPES)),
                ),
            ]);
        }

        return parent::handleRecordUpdate($record, $data);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getFormContentComponent(),
            ...Arr::wrap($this->getMultiFactorAuthenticationContentComponent()),
            $this->getBrowserSessionsContentComponent(),
        ]);
    }

    /**
     * Browser sessions — docs/08 §14.
     *
     * Laravel already owns both halves of this: the `sessions` table is the
     * list, and `logoutOtherDevices()` is the button. Nothing here tracks
     * sessions of its own, which is the point — a second store of "who is
     * signed in" would disagree with the first one within a week.
     */
    protected function getBrowserSessionsContentComponent(): Component
    {
        return Section::make('Browser sessions')
            ->description('Where this account is signed in. Sign out of everything else if you do not recognise one.')
            ->schema([
                Html::make(fn (): Htmlable => $this->sessionList()),
                Actions::make([$this->logOutOtherSessionsAction()]),
            ]);
    }

    /**
     * Password-gated, because it re-hashes the password to invalidate the other
     * sessions — that is what `AuthenticateSession` compares against — and an
     * unattended laptop should not be able to lock out the real owner's phone.
     */
    protected function logOutOtherSessionsAction(): Action
    {
        return Action::make('logOutOtherSessions')
            ->label('Log out other sessions')
            ->color('danger')
            ->modalSubmitActionLabel('Log out')
            ->schema([
                TextInput::make('password')
                    ->label('Confirm your password')
                    ->password()
                    ->required()
                    ->currentPassword(guard: Filament::getAuthGuard()),
            ])
            ->action(function (#[SensitiveParameter] array $data): void {
                $guard = Auth::guard(Filament::getAuthGuard());

                // `logoutOtherDevices` belongs to SessionGuard: it works by
                // re-hashing the password, which is the value AuthenticateSession
                // compares a returning session against. A panel on a token guard
                // has no other browser sessions for it to end.
                if ($guard instanceof SessionGuard) {
                    $guard->logoutOtherDevices((string) $data['password']);
                }

                // The rehash above is what actually kills the other sessions;
                // this only stops the list above from showing rows that are
                // already dead. Rows for the current session are kept.
                if ($this->sessionsAreListable()) {
                    DB::connection(config('session.connection'))
                        ->table((string) config('session.table', 'sessions'))
                        ->where('user_id', $this->getUser()->getAuthIdentifier())
                        ->where('id', '!=', session()->getId())
                        ->delete();
                }

                Notification::make()
                    ->title('Other sessions signed out')
                    ->success()
                    ->send();
            });
    }

    /**
     * Only the database driver keeps a row per session. On redis or a cookie
     * there is nothing to enumerate — and inventing an entry per login in a
     * table of our own is exactly the parallel store this avoids — so the list
     * says so rather than showing an empty state that reads as "no sessions".
     */
    private function sessionList(): Htmlable
    {
        if (! $this->sessionsAreListable()) {
            return new HtmlString(
                '<p class="text-sm text-gray-400">This deployment keeps sessions in <code>'
                .e((string) config('session.driver')).'</code>, which stores no per-session row to list.'
                .' Signing out other sessions still works.</p>'
            );
        }

        $detector = app(DeviceDetector::class);
        $currentId = session()->getId();

        $rows = DB::connection(config('session.connection'))
            ->table((string) config('session.table', 'sessions'))
            ->where('user_id', $this->getUser()->getAuthIdentifier())
            ->orderByDesc('last_activity')
            ->get()
            ->map(function (object $session) use ($detector, $currentId): string {
                /** @var object{id: string, ip_address: ?string, user_agent: ?string, last_activity: int} $session */
                $agent = $detector->detect($session->user_agent);
                $isCurrent = $session->id === $currentId;

                return sprintf(
                    '<div class="flex items-baseline justify-between gap-4 py-2"><span class="text-sm text-gray-200">%s</span>'
                    .'<span class="text-xs %s">%s</span></div>',
                    e(trim(($agent['browser'] ?? 'Unknown browser').' · '.($agent['os'] ?? 'unknown OS').' · '.($session->ip_address ?? 'no IP'))),
                    $isCurrent ? 'text-success-400' : 'text-gray-400',
                    e($isCurrent ? 'this device' : 'last active '.now()->setTimestamp($session->last_activity)->diffForHumans()),
                );
            })
            ->implode('');

        return new HtmlString('<div class="divide-y divide-gray-700">'.$rows.'</div>');
    }

    private function sessionsAreListable(): bool
    {
        return config('session.driver') === 'database';
    }
}
