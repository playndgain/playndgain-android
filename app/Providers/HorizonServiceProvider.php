<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Admins who already signed into /admin. Same rule as Log Viewer.
     * Horizon's package default is local-only, which is why /horizon 403'd
     * in production with nobody on the allow-list.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (?User $user): bool => (bool) $user?->isAdmin());
    }
}
