<?php

namespace App\Filament\Support;

use App\Http\Controllers\ImpersonationController;
use App\Models\AuditLog;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * Sign in as another user — docs/07 §5.
 *
 * One definition for every table that offers it. Impersonation swaps who the
 * session is, so the rules around it belong in one place rather than being
 * re-derived per resource:
 *
 *   - **Never nested.** The admin's own id lives in a single session key.
 *     Impersonating from inside an impersonated session would overwrite it and
 *     strand the original admin with no way back to their own account.
 *   - **Never yourself.** A no-op that still writes an audit row reads, later,
 *     as an admin having done something.
 *   - Always confirmed, always audited, always visibly banner-ed until stopped.
 */
final class ImpersonateAction
{
    public static function make(string $auditAction = 'user.impersonated'): Action
    {
        return Action::make('impersonate')
            ->label('Impersonate')
            ->icon(Heroicon::OutlinedUserCircle)
            ->requiresConfirmation()
            ->modalDescription('You will be signed in as this user. The session is audit-logged and shows a banner until you stop.')
            ->visible(fn (): bool => ! session()->has(ImpersonationController::SESSION_KEY))
            ->disabled(fn (User $record): bool => $record->is(Auth::user()))
            ->action(function (User $record) use ($auditAction) {
                if ($record->is(Auth::user()) || session()->has(ImpersonationController::SESSION_KEY)) {
                    return null;
                }

                AuditLog::record($auditAction, $record);

                session([ImpersonationController::SESSION_KEY => Auth::id()]);
                ImpersonationController::loginAs($record);

                return redirect($record->isAdmin() ? '/admin' : '/dashboard');
            });
    }
}
