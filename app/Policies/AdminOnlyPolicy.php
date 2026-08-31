<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Platform-owned models — networks, offers, publishers, fraud config, logs.
 *
 * Filament calls `canViewAny` through the policy, so a model with no policy at
 * all would fail *open*. Registering this one against every admin-panel model
 * is what makes the panel's authorization actually policy-driven rather than
 * middleware-driven (docs/07 §10).
 */
class AdminOnlyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Model $model): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Model $model): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->isAdmin();
    }

    public function restore(User $user, Model $model): bool
    {
        return $user->isAdmin();
    }

    /** Nothing on this platform is force-deleted through a panel. */
    public function forceDelete(User $user, Model $model): bool
    {
        return false;
    }
}
