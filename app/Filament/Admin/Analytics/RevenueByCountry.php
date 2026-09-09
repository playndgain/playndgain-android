<?php

namespace App\Filament\Admin\Analytics;

use App\Enums\SourceDimension;
use App\Filament\Publisher\Pages\Statistics;

class RevenueByCountry extends RevenueBreakdown
{
    protected static ?int $sort = 16;

    protected ?string $heading = 'Revenue by country';

    protected function dimension(): string
    {
        return 'country';
    }

    protected function labelFor(string $key): string
    {
        return Statistics::countryLabel($key);
    }

    protected function urlFor(string $key): ?string
    {
        return SourceLinks::url(SourceDimension::Country, $key);
    }
}
