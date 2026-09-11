<?php

namespace App\Listeners;

use App\Identity\IdentityLedger;
use App\Models\User;
use Illuminate\Auth\Events\Login;

class RecordPanelIdentity
{
    public function __construct(private readonly IdentityLedger $ledger) {}

    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->ledger->recordPanel($event->user, request());
    }
}
