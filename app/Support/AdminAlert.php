<?php

namespace App\Support;

use App\Models\Setting;
use App\Notifications\AdminAlertNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * The one place an operational alert leaves the application — docs/11 §7.
 *
 * The log line is the audit trail and is written on every raise, no matter
 * what. Delivery is the part that has to be rationed: a nightly drift check
 * across four hundred publishers raises the same key four hundred times, and
 * four hundred emails is an alert nobody reads. So the log records every
 * occurrence and the mailer hears about the first one per window.
 *
 * ponytail: cooldown is per key, not per key+subject. Two different publishers
 * drifting in the same hour is still one bug in the same writer, and the log
 * carries both rows for whoever opens the email. Per-subject keys if the day
 * comes that two unrelated subjects genuinely need separate mails.
 */
final class AdminAlert
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function raise(string $key, ?Model $subject = null, array $context = []): void
    {
        // The class and key, never the model — a Network carries encrypted
        // credentials and log files are not the place for them. The same
        // string is all the notification gets, for the same reason: a queued
        // notification is a serialised model sitting in the jobs table.
        $subjectLabel = $subject === null ? null : $subject::class.'#'.$subject->getKey();

        Log::critical("admin-alert: {$key}", ['subject' => $subjectLabel, ...$context]);

        $recipients = self::recipients();

        // No recipients configured is a legitimate state — a fresh install, or
        // an operator who watches the log aggregator instead. It degrades to
        // the line above rather than throwing inside whatever money path
        // happened to notice the problem.
        if ($recipients === [] || ! self::claimWindow($key)) {
            return;
        }

        Notification::route('mail', $recipients)
            ->notify(new AdminAlertNotification($key, self::pages($key), $subjectLabel, $context));
    }

    /**
     * True the first time this key is seen in the cooldown window.
     *
     * `Cache::add` is atomic on every store that matters here, so two workers
     * hitting the same condition at the same moment still send one email.
     */
    private static function claimWindow(string $key): bool
    {
        $minutes = (int) Setting::get('alert_cooldown_minutes', config('advault.alerts.cooldown_minutes'));

        if ($minutes <= 0) {
            return true;
        }

        return Cache::add("alert-cooldown:{$key}", true, $minutes * 60);
    }

    /**
     * Recipients from the settings table, falling back to config for a database
     * that has not been seeded. Anything that is not an address is dropped
     * rather than handed to the mailer: `TagsInput` accepts any string, and one
     * typo throwing inside the transport would kill the alert it was carrying.
     *
     * @return list<string>
     */
    public static function recipients(): array
    {
        $configured = json_decode((string) Setting::get('alert_emails', '[]'), true);

        if (! is_array($configured) || $configured === []) {
            $configured = config('advault.alerts.recipients');
        }

        return array_values(array_filter(
            is_array($configured) ? $configured : [],
            fn (mixed $email): bool => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
        ));
    }

    /** Whether docs/11 §7 rates this signal as a page rather than an email. */
    private static function pages(string $key): bool
    {
        $paging = config('advault.alerts.page');

        return is_array($paging) && in_array($key, $paging, true);
    }
}
