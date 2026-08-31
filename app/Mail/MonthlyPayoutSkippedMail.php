<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Why this month's automatic withdrawal request did not open.
 */
class MonthlyPayoutSkippedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $pubId,
        public readonly string $reason,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Monthly payout could not be requested',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.monthly-payout-skipped-html',
            text: 'mail.monthly-payout-skipped',
        );
    }
}
