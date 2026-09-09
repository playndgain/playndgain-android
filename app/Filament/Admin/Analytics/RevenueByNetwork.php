<?php

namespace App\Filament\Admin\Analytics;

use App\Models\Network;

class RevenueByNetwork extends RevenueBreakdown
{
    protected static ?int $sort = 13;

    protected ?string $heading = 'Revenue by network';

    protected function dimension(): string
    {
        return 'network_id';
    }

    protected function labelFor(string $key): string
    {
        return Network::query()->whereKey($key)->value('name') ?? "Network {$key}";
    }
}
