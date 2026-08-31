<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The ledger is append-only. Nobody edits or deletes an entry — not a
 * publisher, not an admin. Corrections are `adjustment` entries.
 *
 * The model throws on update/delete as well; this is the same rule stated at
 * the authorization layer so the UI never renders a button that cannot work.
 */
class LedgerEntryPolicy extends PublisherOwnedPolicy
{
    public function create(User $user): bool
    {
        // Entries are written by the money path, never by a user action.
        return false;
    }

    public function update(User $user, Model $model): bool
    {
        return false;
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function restore(User $user, Model $model): bool
    {
        return false;
    }
}
