<?php

namespace App\Filament\Support;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Filament's stock provider fetches every avatar from ui-avatars.com, which
 * puts the account holder's name in a third-party URL on every panel page load
 * and paints it in Filament's own grey. Both are wrong here: names are not ours
 * to hand out, and the panels wear Ink & Amber (design.md §10).
 *
 * The chip itself is InitialsChip, shared with the offer and network icon
 * fallbacks. `.fi-avatar` adds the hairline ring, so the SVG stays a flat
 * circle and lets the stylesheet finish it.
 */
class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        // `getNameForDefaultAvatar` is what Filament itself resolves a display
        // name with, so a model that overrides it keeps working.
        return InitialsChip::for(Filament::getNameForDefaultAvatar($record));
    }
}
