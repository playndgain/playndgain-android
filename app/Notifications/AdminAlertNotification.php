<?php

namespace App\Notifications;

use App\Support\Identity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The delivery half of App\Support\AdminAlert.
 *
 * A Notification rather than a Mailable because the channel list is the whole
 * point: docs/11 §7 splits its table into signals that page and signals that
 * email, and the day a paging integration exists it is one more entry in
 * via() — not a second class, a second call site, or a second config block.
 *
 * Everything it carries is a scalar. It is queued, which means serialised into
 * the jobs table, and a model in the constructor would put a Network's
 * encrypted credentials there.
 */
class AdminAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(
        public string $key,
        public bool $pages,
        public ?string $subject = null,
        public array $context = [],
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        // ponytail: mail is the only channel that exists. A paging signal is
        // marked as one in the subject line instead of being quietly demoted,
        // so nobody reads the alert table and believes a pager will fire.
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $platform = Identity::name();
        $severity = $this->pages ? 'PAGE' : 'ALERT';

        $mail = (new MailMessage)
            ->subject("[{$severity}] {$platform}: {$this->key}")
            ->line($this->headline());

        if ($this->pages) {
            // Stated in the body as well as the subject, because a subject
            // prefix is the first thing a mail rule strips.
            $mail->line('docs/11 §7 rates this signal as a page. '.$platform.' has no paging channel yet — this email is the whole notification, so treat it as one.');
        }

        if ($this->subject !== null) {
            $mail->line("Subject: {$this->subject}");
        }

        foreach ($this->context as $name => $value) {
            $mail->line($name.': '.($value === null ? 'null' : var_export($value, true)));
        }

        return $mail->line('The application log holds every occurrence; this email is sent once per cooldown window.');
    }

    /**
     * Alert keys are dotted machine strings and the humans on this list should
     * not have to memorise them. Anything unmapped falls through to the key
     * itself rather than to a wrong description.
     */
    private function headline(): string
    {
        return match ($this->key) {
            'ledger.drift' => 'A cached publisher balance disagrees with its ledger entries. Money code wrote one without the other. Not auto-corrected — find the writer.',
            'postback.failed' => 'A publisher postback exhausted all six retries and will not be sent again without a manual re-fire.',
            'postback.failure_rate' => 'Global postback failure rate is over threshold. At this scale it is us, not them.',
            'queue.depth' => 'The postbacks queue is backing up. Conversions are credited but publishers have not been told.',
            'conversions.silent' => 'No conversions recorded for a platform that normally has them. Usually a broken network integration rather than a quiet day.',
            'network.catalogue_collapse' => 'A network returned a near-empty catalogue. The sync was abandoned with offers untouched rather than emptying the wall.',
            'network.sync_failing' => 'A network catalogue sync has failed repeatedly. Its offers are going stale and will start disappearing from walls.',
            'geoip.missing' => 'The IP intelligence databases are not on disk. Targeting is fail-closed, so every wall is serving zero offers to every publisher until `geoip:update` can fetch them.',
            default => "Alert raised: {$this->key}",
        };
    }
}
