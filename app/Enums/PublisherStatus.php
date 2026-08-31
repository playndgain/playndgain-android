<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum PublisherStatus: string implements HasColor, HasIcon, HasLabel
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Suspended = 'suspended';
    case Banned = 'banned';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Suspended => 'Suspended',
            self::Banned => 'Banned',
        };
    }

    public function getColor(): string
    {
        // Amber for attention, steel/slate for state. Green and red are money
        // direction only — design.md §2.
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'info',
            self::Suspended => 'warning',
            self::Banned => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Pending => Heroicon::OutlinedClock,
            self::Approved => Heroicon::OutlinedCheckBadge,
            self::Suspended => Heroicon::OutlinedPauseCircle,
            self::Banned => Heroicon::OutlinedNoSymbol,
        };
    }
}
