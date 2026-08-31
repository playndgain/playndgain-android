<?php

namespace App\Policies;

use App\Models\App;
use App\Models\OutgoingPostback;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Both panels read this table, so it is neither admin-only nor publisher-owned.
 *
 * `outgoing_postbacks` carries no `publisher_id` — ownership is one hop away,
 * through the app — which is why it needs its own policy rather than reusing
 * PublisherOwnedPolicy.
 */
class OutgoingPostbackPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isPublisher();
    }

    public function view(User $user, Model $model): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if (! $model instanceof OutgoingPostback) {
            return false;
        }

        $publisherId = $user->publisher?->id;

        return $publisherId !== null
            && App::query()->withoutGlobalScopes()
                ->whereKey($model->app_id)
                ->where('publisher_id', $publisherId)
                ->exists();
    }

    /** Written by the postback job, never by a person. */
    public function create(User $user): bool
    {
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
}
