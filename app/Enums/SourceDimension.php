<?php

namespace App\Enums;

enum SourceDimension: string
{
    case Publisher = 'publisher';
    case App = 'app';
    case Offer = 'offer';
    case Country = 'country';
    case Ip = 'ip';
    case EndUser = 'end_user';

    public function label(): string
    {
        return match ($this) {
            self::Publisher => 'Publisher',
            self::App => 'App',
            self::Offer => 'Offer',
            self::Country => 'Country',
            self::Ip => 'IP',
            self::EndUser => 'End user',
        };
    }

    public static function fromFilter(mixed $value): self
    {
        return self::tryFrom(is_string($value) ? $value : '') ?? self::Publisher;
    }
}
