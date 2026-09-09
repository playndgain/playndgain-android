<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Marcelodelgado\Announcements\Traits\HasAnnouncements;
use SensitiveParameter;

/**
 * `MustVerifyEmail` is what makes the publisher panel's `->emailVerification()`
 * do anything: Filament's Register page sends the notification only to a user
 * that declares the contract, and the `verified` middleware only gates one.
 *
 * It is declared on the model rather than per panel because the admin panel
 * does not enable verification at all — admin accounts are seeded, not
 * self-registered, and the seeder marks them verified. Declaring it here costs
 * them nothing and would be the switch if that ever changes.
 */
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, MustVerifyEmail
{
    use HasAnnouncements;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'role' => UserRole::class,
        'two_factor_secret' => 'encrypted',
        'two_factor_recovery_codes' => 'encrypted:array',
        'two_factor_confirmed_at' => 'datetime',
        'last_login_at' => 'datetime',
    ];

    /**
     * @return HasOne<Publisher, $this>
     */
    public function publisher(): HasOne
    {
        return $this->hasOne(Publisher::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isPublisher(): bool
    {
        return $this->role === UserRole::Publisher;
    }

    public function hasConfirmedTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    /**
     * Admins reach both panels; publishers reach only their own.
     *
     * The role is the first gate. A banned publisher keeps the role, so the
     * panel has to refuse them here — Filament's login and Authenticate both
     * call this, which is how docs/08 §1's "login blocked" holds. Pending and
     * suspended still get in: one explores, the other is read-only.
     *
     * An admin in the publisher panel is looking at an empty one — PublisherScope
     * scopes by panel, so a viewer with no publisher row sees no rows. That is
     * the point: it lets an admin check the surface their customers use, and
     * pushes them to impersonation when they need to see real data.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'admin' => $this->isAdmin(),
            'publisher' => $this->isAdmin() || ($this->isPublisher() && ! $this->publisher?->isBanned() && ! $this->publisher?->isSuspended()),
            default => false,
        };
    }

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->two_factor_secret;
    }

    public function saveAppAuthenticationSecret(#[SensitiveParameter] ?string $secret): void
    {
        $this->two_factor_secret = $secret;
        $this->two_factor_confirmed_at = $secret === null ? null : now();
        $this->save();
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    /**
     * @return ?array<string>
     */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->two_factor_recovery_codes;
    }

    /**
     * @param  ?array<string>  $codes
     */
    public function saveAppAuthenticationRecoveryCodes(#[SensitiveParameter] ?array $codes): void
    {
        $this->two_factor_recovery_codes = $codes;
        $this->save();
    }
}
