<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum IncomingPostbackResult: string implements HasColor, HasIcon, HasLabel
{
    case Accepted = 'accepted';
    case Duplicate = 'duplicate';
    case Rejected = 'rejected';
    case Error = 'error';
    case UnknownClick = 'unknown_click';

    public function getLabel(): string
    {
        return match ($this) {
            self::Accepted => 'Accepted',
            self::Duplicate => 'Duplicate',
            self::Rejected => 'Rejected',
            self::Error => 'Error',
            self::UnknownClick => 'Unknown click',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Accepted => 'success',
            self::Duplicate => 'gray',
            self::Rejected => 'danger',
            self::Error => 'danger',
            self::UnknownClick => 'warning',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Accepted => Heroicon::OutlinedCheckCircle,
            self::Duplicate => Heroicon::OutlinedDocumentDuplicate,
            self::Rejected => Heroicon::OutlinedNoSymbol,
            self::Error => Heroicon::OutlinedExclamationTriangle,
            self::UnknownClick => Heroicon::OutlinedQuestionMarkCircle,
        };
    }
}
