<?php

namespace App\Mail;

use App\Enums\AppStatus;
use App\Models\App;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PublisherAppStatusMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly App $app,
        public readonly AppStatus $status,
        public readonly ?string $reason = null,
    ) {}

    public function envelope(): Envelope
    {
        $appId = (string) $this->app->app_id;

        return new Envelope(
            subject: match ($this->status) {
                AppStatus::Active => "App #{$appId} is live",
                AppStatus::Rejected => "App #{$appId} was not approved",
                AppStatus::Paused => "App #{$appId} is paused",
                default => 'App status update',
            },
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.publisher-app-status-html',
            text: 'mail.publisher-app-status',
        );
    }
}
