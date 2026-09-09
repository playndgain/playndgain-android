<?php

namespace App\Enums;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * A statement is money owed for one calendar month. It is `pending` until the
 * withdrawal that settles it is paid, `carried` while it is too small (or
 * negative) to pay on its own, and `paid` once the admin has marked the
 * withdrawal paid. There is no `rejected`: a rejection belongs to the payment
 * attempt, not to the period — the statement goes back to `pending`.
 */
enum PayoutStatementStatus: string implements HasIcon, HasLabel
{
    case Pending = 'pending';
    case Carried = 'carried';
    case Paid = 'paid';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Carried => 'Carried forward',
            self::Paid => 'Paid',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Pending => Heroicon::OutlinedClock,
            self::Carried => Heroicon::OutlinedArrowUturnRight,
            self::Paid => Heroicon::OutlinedCheckBadge,
        };
    }
}
