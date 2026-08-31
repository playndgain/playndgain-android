<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * One message from the public contact form.
 *
 * HTML uses the same published mail chrome as every other MailMessage. The
 * text part stays unescaped — `{{ }}` would turn apostrophes into entities
 * on a mailbox that has no HTML to protect.
 *
 * The subject is built from the allowlisted topic label, never from anything
 * the sender typed. `replyTo` carries their address so hitting reply reaches
 * the person rather than the sending mailbox.
 */
class ContactMessage extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $senderName,
        public readonly string $senderEmail,
        public readonly string $topic,
        public readonly string $body,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Contact form — '.$this->topic,
            replyTo: [new Address($this->senderEmail, $this->senderName)],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.contact-html',
            text: 'mail.contact',
        );
    }
}
