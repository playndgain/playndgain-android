<?php

namespace App\Filament\Admin\Analytics;

use App\Enums\SourceDimension;
use App\Models\Publisher;

class RevenueByPublisher extends RevenueBreakdown
{
    protected static ?int $sort = 14;

    protected ?string $heading = 'Revenue by publisher';

    protected function dimension(): string
    {
        return 'publisher_id';
    }

    protected function labelFor(string $key): string
    {
        $publisher = Publisher::query()->withoutGlobalScopes()->with('user:id,name')->find((int) $key);

        return $publisher === null ? "#{$key}" : "#{$publisher->pub_id} {$publisher->user?->name}";
    }

    protected function urlFor(string $key): ?string
    {
        return SourceLinks::url(SourceDimension::Publisher, $key);
    }
}
