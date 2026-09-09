<?php

namespace App\Console\Commands;

use App\Http\Controllers\ContactController;
use App\Mail\ContactMessage;
use App\Mail\CreditNoteMail;
use App\Mail\MonthlyPayoutErrorsMail;
use App\Mail\MonthlyPayoutSkippedMail;
use App\Models\User;
use App\Notifications\AdminAlertNotification;
use App\Services\Invoices\CreditNote;
use App\Support\Identity;
use Exception;
use Filament\Auth\MultiFactor\Email\Notifications\VerifyEmailAuthentication;
use Filament\Auth\Notifications\NoticeOfEmailChangeRequest;
use Filament\Auth\Notifications\ResetPassword;
use Filament\Auth\Notifications\VerifyEmail;
use Filament\Auth\Notifications\VerifyEmailChange;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupWasSuccessful;
use Spatie\Backup\Events\CleanupHasFailed;
use Spatie\Backup\Events\CleanupWasSuccessful;
use Spatie\Backup\Events\HealthyBackupWasFound;
use Spatie\Backup\Events\UnhealthyBackupWasFound;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification;

/**
 * Drops one of every mail the app can send into the configured mailer.
 *
 * Local only. Mailpit is the reason this exists — opening each real flow
 * (signup, reset, a failed backup) just to look at chrome is a waste of time.
 */
class PreviewMailCommand extends Command
{
    protected $signature = 'mail:preview
        {--to=preview@playndgain.test : Address Mailpit will show}';

    protected $description = 'Send one of every email type to Mailpit (local only)';

    public function handle(): int
    {
        if (! $this->laravel->environment('local')) {
            $this->error('mail:preview is local only.');

            return self::FAILURE;
        }

        $to = (string) $this->option('to');
        $recipient = new User(['name' => 'Mail preview', 'email' => $to]);

        $this->send('Contact form', fn () => Mail::to($to)->send(new ContactMessage(
            senderName: 'Dana Okonkwo',
            senderEmail: 'dana@justrewards.app',
            topic: ContactController::topics()['supply'],
            body: "We run a network with 400 live offers and a subid macro on every tracking URL.\n\nCan you take inventory?",
        )));

        $this->send('Credit note', fn () => Mail::to($to)->sendNow(new CreditNoteMail($this->sampleNote())));

        $this->send('Monthly payout skipped', fn () => Mail::to($to)->sendNow(new MonthlyPayoutSkippedMail(
            pubId: '10042',
            reason: 'Add a payment method before requesting a withdrawal.',
        )));

        $this->send('Monthly payout errors', fn () => Mail::to($to)->sendNow(new MonthlyPayoutErrorsMail([
            ['pub_id' => 10042, 'reason' => 'Add a payment method before requesting a withdrawal.'],
            ['pub_id' => 10087, 'reason' => 'Set a default payment method for the monthly payout.'],
        ])));

        $this->send('Admin alert (page)', fn () => Notification::route('mail', $to)->notifyNow(
            new AdminAlertNotification('ledger.drift', true, 'Publisher #10042', [
                'cached' => '12.400000',
                'derived' => '11.950000',
            ]),
        ));

        $this->send('Admin alert (email)', fn () => Notification::route('mail', $to)->notifyNow(
            new AdminAlertNotification('network.catalogue_collapse', false, 'OGAds', [
                'offers_before' => 1840,
                'offers_returned' => 12,
            ]),
        ));

        $this->send('Verify email', function () use ($recipient): void {
            $mail = new VerifyEmail;
            $mail->url = url('/publisher/email-verification/verify/preview');
            $recipient->notifyNow($mail);
        });

        $this->send('Reset password', function () use ($recipient): void {
            $mail = new ResetPassword('preview-token');
            $mail->url = url('/publisher/password-reset/reset/preview');
            $recipient->notifyNow($mail);
        });

        $this->send('Verify email change', function () use ($recipient): void {
            $mail = new VerifyEmailChange;
            $mail->url = url('/publisher/email-change/preview');
            $recipient->notifyNow($mail);
        });

        $this->send('Notice of email change', fn () => $recipient->notifyNow(
            new NoticeOfEmailChangeRequest('new-address@justrewards.app', url('/publisher/email-change/block/preview')),
        ));

        $this->send('Email 2FA code', fn () => $recipient->notifyNow(
            new VerifyEmailAuthentication('482917', 10),
        ));

        $failed = new Exception('Disk local ran out of space while writing the zip.');

        $this->send('Backup failed', fn () => Notification::route('mail', $to)->notifyNow(
            new BackupHasFailedNotification(new BackupHasFailed($failed)),
        ));

        $this->send('Backup succeeded', fn () => Notification::route('mail', $to)->notifyNow(
            new BackupWasSuccessfulNotification(new BackupWasSuccessful('', '')),
        ));

        $this->send('Cleanup failed', fn () => Notification::route('mail', $to)->notifyNow(
            new CleanupHasFailedNotification(new CleanupHasFailed($failed)),
        ));

        $this->send('Cleanup succeeded', fn () => Notification::route('mail', $to)->notifyNow(
            new CleanupWasSuccessfulNotification(new CleanupWasSuccessful('', '')),
        ));

        $this->send('Backup healthy', fn () => Notification::route('mail', $to)->notifyNow(
            new HealthyBackupWasFoundNotification(new HealthyBackupWasFound('', '')),
        ));

        $this->send('Backup unhealthy', fn () => Notification::route('mail', $to)->notifyNow(
            new UnhealthyBackupWasFoundNotification(new UnhealthyBackupWasFound('', '', collect([
                ['check' => 'MaximumAgeInDays', 'message' => 'The newest backup is older than 1 day.'],
            ]))),
        ));

        $this->info("Sent to {$to}. Open Mailpit.");

        return self::SUCCESS;
    }

    /**
     * @param  callable(): void  $send
     */
    private function send(string $label, callable $send): void
    {
        $send();
        $this->line("  {$label}");
    }

    private function sampleNote(): CreditNote
    {
        return new CreditNote(
            number: 'CN-12435',
            publisherId: '15969',
            issueDate: '2026-08-20',
            dueDate: '2026-09-19',
            periodStart: '2026-03-01',
            periodEnd: '2026-03-31',
            billingEmail: 'admin@example.com',
            payeeName: 'Mail Preview',
            payeeAddress: '',
            payeeCityLine: '',
            payeeCountry: '',
            payeeVat: '',
            lines: [
                ['description' => 'Publisher Earnings 03-2026 (#16890 Preview)', 'amount' => '32.80'],
            ],
            subtotal: '32.80',
            vatRate: '0.00',
            vatAmount: '0.00',
            total: '32.80',
            paymentInstruction: 'PayPal: preview@example.com',
            issuer: Identity::invoiceIssuer(),
        );
    }
}
