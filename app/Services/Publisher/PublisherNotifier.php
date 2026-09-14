<?php

namespace App\Services\Publisher;

use App\Enums\AppStatus;
use App\Enums\PublisherStatus;
use App\Mail\PublisherAccountStatusMail;
use App\Mail\PublisherAppStatusMail;
use App\Models\App;
use App\Models\Publisher;
use Illuminate\Support\Facades\Mail;

/**
 * Transactional mail when a publisher account or app moves review state.
 */
class PublisherNotifier
{
    public function publisherStatusChanged(
        Publisher $publisher,
        PublisherStatus $from,
        PublisherStatus $to,
        ?string $reason = null,
    ): void {
        if ($from === $to || ! $this->notifiesPublisher($publisher, 'account_status')) {
            return;
        }

        if (! in_array($to, [PublisherStatus::Approved, PublisherStatus::Suspended, PublisherStatus::Banned], true)) {
            return;
        }

        $email = $this->recipient($publisher);

        if ($email === null) {
            return;
        }

        Mail::to($email)->queue(
            new PublisherAccountStatusMail($publisher, $to, $reason),
        );
    }

    public function appStatusChanged(
        App $app,
        AppStatus $from,
        AppStatus $to,
        ?string $reason = null,
    ): void {
        if ($from === $to) {
            return;
        }

        $publisher = $app->publisher;

        if (! $publisher instanceof Publisher || ! $this->notifiesPublisher($publisher, 'app_status')) {
            return;
        }

        if (! in_array($to, [AppStatus::Active, AppStatus::Rejected, AppStatus::Paused], true)) {
            return;
        }

        $email = $this->recipient($publisher);

        if ($email === null) {
            return;
        }

        Mail::to($email)->queue(
            new PublisherAppStatusMail($app, $to, $reason),
        );
    }

    private function notifiesPublisher(Publisher $publisher, string $type): bool
    {
        return $publisher->wantsNotification($type);
    }

    private function recipient(Publisher $publisher): ?string
    {
        $email = $publisher->user?->email;

        if (! is_string($email) || $email === '') {
            return null;
        }

        return $email;
    }
}
