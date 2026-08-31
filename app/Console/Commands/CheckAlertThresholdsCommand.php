<?php

namespace App\Console\Commands;

use App\Enums\OutgoingPostbackStatus;
use App\Fraud\MaxMindIpIntelligence;
use App\Models\Conversion;
use App\Models\OutgoingPostback;
use App\Models\Setting;
use App\Support\AdminAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;

/**
 * The threshold half of docs/04 §6 and docs/11 §7.
 *
 * Only the signals the application is the sole witness to live here. Site down,
 * disk usage, certificate expiry and slow-query counts are all in the spec's
 * table and all deliberately absent: docs/11 §7 names Sentry, Pulse and an
 * external HTTP checker for those, and an in-app monitor cannot report that the
 * app is down. Rebuilding them here would produce a monitor that goes quiet at
 * exactly the moment it matters.
 *
 * Runs on the same fifteen minutes the postback window is measured over, so
 * each run looks at roughly the period since the last one.
 */
class CheckAlertThresholdsCommand extends Command
{
    protected $signature = 'alerts:check';

    protected $description = 'Evaluate the alert thresholds the application can see and raise what has crossed them.';

    public function handle(): int
    {
        $this->postbackFailureRate();
        $this->queueDepth();
        $this->silentConversions();
        $this->geoipDatabases();

        return self::SUCCESS;
    }

    /**
     * Global failure rate, not per-app. docs/04 §6 gives the two separate
     * thresholds precisely because they mean different things: one publisher's
     * endpoint failing is their problem, every publisher's endpoint failing at
     * once is ours.
     *
     * `retrying` counts as failed. A postback still walking its six-hour
     * backoff has not been delivered, and waiting for the terminal state before
     * counting it would put the alert four hours behind the outage.
     */
    private function postbackFailureRate(): void
    {
        $window = now()->subMinutes((int) config('advault.alerts.postback_window_minutes'));
        $threshold = (float) Setting::get('alert_postback_failure_rate', config('advault.alerts.postback_failure_rate'));

        $total = OutgoingPostback::query()->where('created_at', '>=', $window)->count();

        // A quiet window is not a healthy one and not a broken one. One failure
        // out of two attempts is 50% and means nothing.
        if ($total < 20) {
            return;
        }

        $failed = OutgoingPostback::query()
            ->where('created_at', '>=', $window)
            ->whereIn('status', [OutgoingPostbackStatus::Failed, OutgoingPostbackStatus::Retrying])
            ->count();

        $rate = $failed / $total * 100;

        if ($rate <= $threshold) {
            return;
        }

        AdminAlert::raise('postback.failure_rate', null, [
            'rate' => round($rate, 1),
            'threshold' => $threshold,
            'failed' => $failed,
            'total' => $total,
            'window_minutes' => (int) config('advault.alerts.postback_window_minutes'),
        ]);
    }

    /**
     * `Queue::size()` rather than a count of the `jobs` table, because the
     * table is only where the work sits under the database driver. On Redis —
     * which Horizon in the schedule implies production runs — a table count is
     * zero forever, which is the most convincing way for a depth alert to fail.
     */
    private function queueDepth(): void
    {
        $queue = (string) config('advault.alerts.queue_depth_queue');
        $threshold = (int) Setting::get('alert_queue_depth', config('advault.alerts.queue_depth'));

        $depth = Queue::size($queue);

        if ($depth <= $threshold) {
            return;
        }

        AdminAlert::raise('queue.depth', null, [
            'queue' => $queue,
            'depth' => $depth,
            'threshold' => $threshold,
        ]);
    }

    /**
     * Zero conversions for N hours — a silently broken network integration
     * looks exactly like a quiet night, so two guards separate them.
     *
     * The active-hours window keeps a 04:00 lull from paging anyone; hours are
     * read in config('app.timezone'), which is the only clock this application
     * has an opinion about.
     *
     * The day-of-traffic guard is the one that matters more: a platform with no
     * conversions in the last twenty-four hours is a platform nobody has
     * plugged in yet, and alerting on it would mean every fresh install starts
     * by emailing its operator that nothing is happening.
     */
    private function silentConversions(): void
    {
        $hour = (int) now()->format('G');
        [$from, $until] = config('advault.alerts.active_hours');

        if ($hour < (int) $from || $hour > (int) $until) {
            return;
        }

        $hours = (int) Setting::get('alert_conversion_silence_hours', config('advault.alerts.conversion_silence_hours'));

        if ($hours <= 0) {
            return;
        }

        $recent = Conversion::query()->where('created_at', '>=', now()->subHours($hours))->exists();

        if ($recent) {
            return;
        }

        if (! Conversion::query()->where('created_at', '>=', now()->subDay())->exists()) {
            return;
        }

        AdminAlert::raise('conversions.silent', null, ['silent_hours' => $hours]);
    }

    /**
     * The quietest way this platform can be completely dark.
     *
     * docs/03 §2 is fail-closed on purpose: an address GeoLite2 cannot place
     * gets an empty wall rather than the worldwide inventory, and it names "a
     * box with no database on disk" as one of the cases. That is the right
     * rule — serving an offer to a user whose eligibility was never
     * established is what a network charges back against.
     *
     * The rule is also indistinguishable, from the outside, from a platform
     * with no traffic. The databases are licensed downloads that `geoip:update`
     * skips without credentials, so a deploy that forgets `MAXMIND_LICENSE_KEY`
     * serves zero offers to every publisher, logs nothing, and errors nowhere.
     * `conversions.silent` would eventually notice, but only during active
     * hours, only after the silence window, and only on an install that had
     * conversions yesterday — never on the launch that shipped without the key.
     *
     * So the absence is reported directly. No cooldown concerns: AdminAlert
     * already deduplicates per key, and this condition is either true or it is
     * not for days at a time.
     */
    private function geoipDatabases(): void
    {
        $missing = [];

        foreach (MaxMindIpIntelligence::DATABASES as $label => $file) {
            if (! is_file(MaxMindIpIntelligence::file($file))) {
                $missing[] = $label;
            }
        }

        if ($missing === []) {
            return;
        }

        AdminAlert::raise('geoip.missing', null, [
            'missing' => implode(', ', $missing),
            // Named because it is almost always the answer, and because the
            // operator reading the email should not have to go and find it.
            'credentials' => filled(config('advault.geoip_maxmind_license_key'))
                ? 'set'
                : 'MAXMIND_LICENSE_KEY is empty',
        ]);
    }
}
