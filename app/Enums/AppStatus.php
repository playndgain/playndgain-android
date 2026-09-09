<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum AppStatus: string implements HasColor, HasIcon, HasLabel
{
    case Pending = 'pending';
    case Active = 'active';
    case Paused = 'paused';
    case Rejected = 'rejected';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Active => 'Active',
            self::Paused => 'Paused',
            self::Rejected => 'Rejected',
        };
    }

    public function getColor(): string
    {
        // Green settled, amber waiting, rose refused, gray for the rest.
        return match ($this) {
            self::Pending => 'warning',
            self::Active => 'success',
            self::Paused => 'gray',
            self::Rejected => 'danger',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Pending => Heroicon::OutlinedClock,
            self::Active => Heroicon::OutlinedCheckBadge,
            self::Paused => Heroicon::OutlinedPauseCircle,
            self::Rejected => Heroicon::OutlinedNoSymbol,
        };
    }
}
