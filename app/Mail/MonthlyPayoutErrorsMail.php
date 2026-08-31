<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Ops copy of every publisher the monthly run could not request for.
 *
 * @phpstan-type Failure array{pub_id: int, reason: string}
 */
class MonthlyPayoutErrorsMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  list<Failure>  $failures
     */
    public function __construct(public readonly array $failures) {}

    public function envelope(): Envelope
    {
        $count = count($this->failures);

        return new Envelope(
            subject: "Monthly payout: {$count} publisher(s) failed the rules",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.monthly-payout-errors-html',
            text: 'mail.monthly-payout-errors',
        );
    }
}
