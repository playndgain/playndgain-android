<?php

namespace App\Mail;

use App\Services\Invoices\CreditNote;
use App\Services\Invoices\CreditNotePdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The payout PDF, emailed once the withdrawal is marked paid.
 */
class CreditNoteMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly CreditNote $note) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Credit note '.$this->note->number,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.credit-note-html',
            text: 'mail.credit-note',
        );
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(
                fn (): string => app(CreditNotePdf::class)->render($this->note),
                $this->note->filename(),
            )->withMime('application/pdf'),
        ];
    }
}
