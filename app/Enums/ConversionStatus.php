<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;

enum ConversionStatus: string implements HasColor
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Reversed = 'reversed';
    case Rejected = 'rejected';

    /**
     * Green settled, amber waiting, rose refused. A reversal is money going
     * back, so it keeps the money `loss` tone rather than the status one.
     */
    public function getColor(): string
    {
        return match ($this) {
            self::Approved => 'success',
            self::Pending => 'warning',
            self::Reversed => 'loss',
            self::Rejected => 'danger',
        };
    }
}
