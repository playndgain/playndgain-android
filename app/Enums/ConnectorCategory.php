<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * What kind of thing is behind a driver — not what state it is in.
 *
 * The distinction an admin needs when picking a driver is "does this row stand
 * for one account we hold, or for one tenant on a platform many partners run
 * their own tenant on". A *platform* connector is reusable: several networks
 * rows share the driver class and differ only by credentials and callback
 * identity. A *direct* connector is one integration with one partner, so a
 * second row would be a duplicate. *Internal* is us — the house channel and
 * the test double.
 *
 * This is metadata, never a status, so it stays off the status palette:
 * `success`/`warning`/`danger` are reserved for live/waiting/refused and money
 * keeps `gain`/`loss`. The three tones here are the panel's neutral ones.
 */
enum ConnectorCategory: string implements HasColor, HasLabel
{
    case Platform = 'platform';
    case Direct = 'direct';
    case Internal = 'internal';

    public function getLabel(): string
    {
        return match ($this) {
            self::Platform => 'Platform',
            self::Direct => 'Direct',
            self::Internal => 'Internal',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Platform => 'primary',
            self::Direct => 'gray',
            self::Internal => 'info',
        };
    }

    /** Heading this category gets in the admin driver select. */
    public function groupLabel(): string
    {
        return match ($this) {
            self::Platform => 'Platform connectors',
            self::Direct => 'Direct connectors',
            self::Internal => 'Internal',
        };
    }

    /** Heroicon on the create-page connector cards. */
    public function icon(): string
    {
        return match ($this) {
            self::Platform => 'heroicon-m-squares-2x2',
            self::Direct => 'heroicon-m-link',
            self::Internal => 'heroicon-m-home',
        };
    }

    /** One line under the connector name on those cards. */
    public function cardDescription(): string
    {
        return match ($this) {
            self::Platform => 'One account per partner.',
            self::Direct => 'One account.',
            self::Internal => 'House inventory.',
        };
    }
}
