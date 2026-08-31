<?php

namespace App\Policies;

use App\Enums\PublisherStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * One policy for every publisher-owned model, because they all share one rule:
 * you may touch a row if your publisher owns it. Admins may touch anything.
 *
 * The admin allowance lives here rather than in a `Gate::before` hook so that a
 * subclass can still deny an action outright — see LedgerEntryPolicy, where
 * "nobody edits the ledger" has to hold for admins too.
 *
 * A suspended account is read-only — docs/08 §1. That rule is enforced here
 * rather than on each resource because every publisher-owned model routes
 * through this one policy: a per-resource `canCreate()` covers the resource
 * somebody remembered, and blacklists, banned users and payment methods stayed
 * writable for months on exactly that basis.
 */
class PublisherOwnedPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $this->publisherId($user) !== null;
    }

    public function view(User $user, Model $model): bool
    {
        return $this->owns($user, $model);
    }

    public function create(User $user): bool
    {
        return ($user->isAdmin() || $this->publisherId($user) !== null)
            && $this->mayWrite($user);
    }

    public function update(User $user, Model $model): bool
    {
        return $this->owns($user, $model) && $this->mayWrite($user);
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->owns($user, $model) && $this->mayWrite($user);
    }

    public function restore(User $user, Model $model): bool
    {
        return $this->owns($user, $model) && $this->mayWrite($user);
    }

    public function forceDelete(User $user, Model $model): bool
    {
        // Nothing publisher-owned is ever force-deleted through the panels.
        return false;
    }

    protected function owns(User $user, Model $model): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $publisherId = $this->publisherId($user);

        return $publisherId !== null
            && $model->getAttribute('publisher_id') === $publisherId;
    }

    protected function publisherId(User $user): ?int
    {
        return $user->isPublisher() ? $user->publisher?->id : null;
    }

    /**
     * Read-only, not locked out. A suspended publisher still needs to see the
     * data behind the balance we are holding — taking the reads away as well
     * turns a suspension into a dispute we cannot answer, and the banner that
     * explains the suspension is on a page they have to be able to open.
     */
    protected function mayWrite(User $user): bool
    {
        return $user->isAdmin()
            || $user->publisher?->status !== PublisherStatus::Suspended;
    }
}
