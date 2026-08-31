<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum NetworkIntegration: string implements HasColor, HasIcon, HasLabel
{
    case Api = 'api';
    case Embed = 'embed';
    case Both = 'both';

    public function hostsWall(): bool
    {
        return $this !== self::Api;
    }

    public function usesCatalogue(): bool
    {
        return $this !== self::Embed;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Api => 'API catalogue only',
            self::Embed => 'Embedded wall only',
            self::Both => 'API and embedded wall',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Api => 'gray',
            self::Embed => 'info',
            self::Both => 'primary',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Api => Heroicon::OutlinedCircleStack,
            self::Embed => Heroicon::OutlinedWindow,
            self::Both => Heroicon::OutlinedSquaresPlus,
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Api => 'Pulls offers from their catalogue API',
            self::Embed => 'Frames their hosted offer wall',
            self::Both => 'Catalogue API and an embedded wall',
        };
    }

    public static function fromForm(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        return self::tryFrom((string) $value) ?? self::Api;
    }
}
