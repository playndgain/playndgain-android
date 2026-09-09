<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;

enum WithdrawalStatus: string implements HasColor
{
    case Requested = 'requested';
    case Processing = 'processing';
    case Paid = 'paid';
    case Rejected = 'rejected';

    /** Green paid, amber in flight, rose refused. */
    public function getColor(): string
    {
        return match ($this) {
            self::Paid => 'success',
            self::Requested => 'warning',
            self::Processing => 'warning',
            self::Rejected => 'danger',
        };
    }
}
