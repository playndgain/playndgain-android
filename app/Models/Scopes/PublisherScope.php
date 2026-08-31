<?php

namespace App\Models\Scopes;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Constrains every publisher-owned model to the authenticated publisher.
 *
 * Scoping is by **panel first, role second**. The publisher panel is a
 * single-tenant UI: its tables, totals and balances all mean "yours". An admin
 * browsing it as themselves has no publisher row, and letting role alone decide
 * would hand them every publisher's rows merged into a view with no tenant
 * context — while the resources that scope through CurrentPublisher would show
 * nothing. Two mechanisms disagreeing about whose data is on screen is worse
 * than either answer.
 *
 * So inside the publisher panel the scope always applies, and a viewer with no
 * publisher row sees nothing. Admins reach a publisher's real view through
 * impersonation, which is audited.
 *
 * Outside it — the admin panel, queue workers, console commands, the offerwall
 * — nothing is scoped: those have no session to scope against, or are the place
 * platform-wide data belongs.
 */
class PublisherScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return;
        }

        $inPublisherPanel = Filament::getCurrentPanel()?->getId() === 'publisher';

        if (! $inPublisherPanel && ! $user->isPublisher()) {
            return;
        }

        $publisherId = $user->publisher?->id;

        if ($publisherId === null) {
            // No publisher row means no rows, never every row.
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('publisher_id'), $publisherId);
    }
}
