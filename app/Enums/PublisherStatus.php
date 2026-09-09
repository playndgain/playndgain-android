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
        // Green settled, amber waiting, rose refused, gray for the rest.
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'success',
            self::Suspended => 'warning',
            self::Banned => 'danger',
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
