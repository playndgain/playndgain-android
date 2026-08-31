<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum AppPlatform: string implements HasLabel
{
    case Web = 'web';
    case Android = 'android';
    case Ios = 'ios';
    case All = 'all';

    public function getLabel(): string
    {
        return match ($this) {
            self::Web => 'Web',
            self::Android => 'Android',
            self::Ios => 'iOS',
            self::All => 'All platforms',
        };
    }

    /**
     * Device marks under public/img/devices. Web borrows the desktop glyph —
     * there is no separate browser mark, and the three files already mean
     * "phone / phone / desktop" on the offers table.
     *
     * @return list<string>
     */
    public function deviceMarks(): array
    {
        return match ($this) {
            self::Web => ['desktop'],
            self::Android => ['android'],
            self::Ios => ['ios'],
            self::All => ['android', 'ios', 'desktop'],
        };
    }
}
