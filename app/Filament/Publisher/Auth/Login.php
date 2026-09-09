<?php

namespace App\Filament\Publisher\Auth;

use App\Models\User;
use App\Support\Identity;
use Filament\Auth\Pages\Login as BaseLogin;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;

/**
 * Publisher login. Filament's page already refuses anyone `canAccessPanel`
 * rejects — including a banned publisher — but it does so with the generic
 * "credentials do not match" copy. Docs/08 §1 wants the ban said out loud.
 *
 * The flag is set only after the password has already validated, so a wrong
 * password on a banned email still looks like a wrong password.
 */
class Login extends BaseLogin
{
    private bool $rejectedAsBanned = false;

    private bool $rejectedAsSuspended = false;

    protected function isUserAllowedToAccessPanel(Authenticatable $user): bool
    {
        $allowed = parent::isUserAllowedToAccessPanel($user);

        if (! $allowed && $user instanceof User) {
            if ($user->publisher?->isBanned()) {
                $this->rejectedAsBanned = true;
            } elseif ($user->publisher?->isSuspended()) {
                $this->rejectedAsSuspended = true;
            }
        }

        return $allowed;
    }

    protected function throwFailureValidationException(): never
    {
        if ($this->rejectedAsBanned) {
            throw ValidationException::withMessages([
                'data.email' => __('This account has been banned. Contact :email if you believe this is a mistake.', [
                    'email' => Identity::supportEmail(),
                ]),
            ]);
        }

        if ($this->rejectedAsSuspended) {
            throw ValidationException::withMessages([
                'data.email' => __('This account has been suspended. Contact :email if you believe this is a mistake.', [
                    'email' => Identity::supportEmail(),
                ]),
            ]);
        }

        parent::throwFailureValidationException();
    }
}
