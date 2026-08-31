<?php

namespace App\Filament\Publisher\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Panel;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

/**
 * The screen that closes registration — docs/08 §15, "On completion".
 *
 * It exists for exactly one reason: `apps.secret_key` is a shown-in-full-once
 * value (docs/00 §8), and the moment it is created is the only moment the
 * publisher can copy it. Without this page the sole route to a secret is the
 * Regenerate action, which invalidates every signature they may already have
 * embedded — a destructive action as the first step of onboarding.
 *
 * The payload is handed over in the session by Register and pulled here, so a
 * reload shows nothing rather than re-displaying a secret in a browser cache.
 */
class Welcome extends Page
{
    /** Where Register leaves the one-time payload. */
    public const SESSION_KEY = 'publisher.registration';

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.publisher.pages.welcome';

    /** @var array{app_id: int, secret: string, app_name: string}|null */
    public ?array $credentials = null;

    /**
     * The one page in this panel that an unverified account has to reach.
     *
     * Verification is required everywhere else (docs/08 §1), and a registrant
     * is by definition unverified for the seconds between submitting the form
     * and opening their inbox. Gating this page would mean the secret is
     * displayed once, to nobody.
     */
    public static function isEmailVerificationRequired(Panel $panel): bool
    {
        return false;
    }

    public function mount(): RedirectResponse|Redirector|null
    {
        $payload = session()->pull(self::SESSION_KEY);

        // Reached by typing the URL, or reloaded after the pull. There is
        // nothing to show and nothing to regenerate — send them home.
        if (! is_array($payload)) {
            return redirect(filament()->getUrl());
        }

        /** @var array{app_id: int, secret: string, app_name: string} $payload */
        $this->credentials = $payload;

        return null;
    }

    public function getTitle(): string|Htmlable
    {
        return 'Your application is in';
    }

    public function getHeading(): string|Htmlable
    {
        return 'Your application is in';
    }

    public function getSubheading(): ?string
    {
        return 'A person reads step 2 and approves — usually within a day.';
    }
}
