<?php

namespace App\Filament\Publisher\Support;

use App\Models\Publisher;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * The publisher behind the current session.
 *
 * Every resource and widget in this panel resolves through here rather than
 * reaching for `auth()->user()->publisher` in a dozen places — one definition
 * of "whose data is this" is one thing to get wrong.
 */
final class CurrentPublisher
{
    public static function get(): ?Publisher
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return null;
        }

        // Queried rather than read off the relation: a publisher user whose row
        // was soft-deleted has to come back null here, not throw downstream.
        return Publisher::query()->where('user_id', $user->id)->first();
    }

    /** For query scoping, where "no publisher" has to mean "no rows", not "all rows". */
    public static function id(): int
    {
        $publisher = self::get();

        return $publisher === null ? 0 : $publisher->id;
    }
}
