<?php

namespace App\Filament\Support;

use App\Models\User;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Auth;

/**
 * The user-menu entry that moves an admin between the two panels.
 *
 * Only admins see it. A publisher has one panel, and a menu entry pointing at a
 * door they cannot open is worse than no entry at all.
 */
final class PanelSwitcher
{
    /**
     * @return array<string, Action>
     */
    public static function items(string $currentPanel): array
    {
        $user = Auth::user();

        if (! $user instanceof User || ! $user->isAdmin()) {
            return [];
        }

        return [
            'switch-panel' => $currentPanel === 'admin'
                ? self::item('Publisher panel', '/dashboard', 'heroicon-m-arrow-top-right-on-square')
                : self::item('Admin panel', '/admin', 'heroicon-m-shield-check'),
        ];
    }

    private static function item(string $label, string $url, string $icon): Action
    {
        return Action::make('switch-panel')->label($label)->url($url)->icon($icon);
    }
}
