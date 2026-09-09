<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;

enum OutgoingPostbackStatus: string implements HasColor
{
    case Queued = 'queued';
    case Success = 'success';
    case Retrying = 'retrying';
    case Failed = 'failed';

    /** Green delivered, amber still trying, rose given up, gray not yet sent. */
    public function getColor(): string
    {
        return match ($this) {
            self::Success => 'success',
            self::Retrying => 'warning',
            self::Failed => 'danger',
            self::Queued => 'gray',
        };
    }
}
