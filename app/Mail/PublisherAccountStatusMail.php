<?php

namespace App\Mail;

use App\Enums\PublisherStatus;
use App\Models\Publisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PublisherAccountStatusMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Publisher $publisher,
        public readonly PublisherStatus $status,
        public readonly ?string $reason = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: match ($this->status) {
                PublisherStatus::Approved => 'Your account is approved',
                PublisherStatus::Suspended => 'Your account is suspended',
                PublisherStatus::Banned => 'Your application was not approved',
                default => 'Account status update',
            },
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.publisher-account-status-html',
            text: 'mail.publisher-account-status',
        );
    }
}
