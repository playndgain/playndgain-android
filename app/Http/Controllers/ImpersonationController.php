<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Ends an admin's impersonation session — docs/07 §5.
 *
 * The admin's own id is held in the session for the duration, so stopping is a
 * plain re-login rather than anything that needs a second credential. The route
 * is only reachable while that key is set.
 */
class ImpersonationController extends Controller
{
    public const SESSION_KEY = 'impersonator_id';

    /**
     * Sign the session in as someone else, both directions of impersonation.
     *
     * AuthenticateSession pins a session to the password hash of whoever signed
     * in, and Livewire re-pins it *before* an action runs rather than after — so
     * swapping users mid-session leaves the previous user's hash in place and
     * the next request through a panel reads the session as hijacked, flushes
     * it, and drops the admin at the login screen. Forgetting the key lets the
     * middleware pin to whoever we just became, exactly as it does after a
     * fresh login. The key is the auth factory's default driver, not the
     * panel's guard — that is what the middleware itself reads.
     */
    public static function loginAs(User $user): void
    {
        Auth::login($user);

        session()->forget('password_hash_'.Auth::getDefaultDriver());
    }

    public function __invoke(): RedirectResponse
    {
        $impersonatorId = session(self::SESSION_KEY);

        if ($impersonatorId === null) {
            throw new NotFoundHttpException;
        }

        $admin = User::query()->find($impersonatorId);

        session()->forget(self::SESSION_KEY);

        if ($admin === null) {
            // The admin account went away mid-session. Signing out is the only
            // safe end state — staying signed in as the publisher is not.
            Auth::logout();
            session()->invalidate();

            return redirect('/admin/login');
        }

        self::loginAs($admin);

        AuditLog::record('publisher.impersonation_ended', $admin, null, null, $admin);

        return redirect('/admin');
    }
}
