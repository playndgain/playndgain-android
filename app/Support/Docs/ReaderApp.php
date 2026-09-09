<?php

namespace App\Support\Docs;

use App\Enums\AppStatus;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * The app whose values pre-fill snippets for the reader of a docs page.
 *
 * The secret is NEVER returned here. Docs pages, the raw Markdown and the
 * copyable prompt are all cacheable and shareable; the reveal lives in the
 * panel.
 */
final class ReaderApp
{
    /**
     * @return array{app_id: string, app_name: string}|null
     */
    public static function credentials(): ?array
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return null;
        }

        $app = $user->publisher?->apps()->where('status', AppStatus::Active)->first();

        return $app ? [
            'app_id' => (string) $app->app_id,
            'app_name' => (string) $app->name,
        ] : null;
    }
}
