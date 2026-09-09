<?php

namespace App\Filament\Admin\Analytics;

use App\Enums\SourceDimension;
use App\Models\App;

class RevenueByApp extends RevenueBreakdown
{
    protected static ?int $sort = 15;

    protected ?string $heading = 'Revenue by app';

    protected function dimension(): string
    {
        return 'app_id';
    }

    protected function labelFor(string $key): string
    {
        return App::query()->withoutGlobalScopes()->whereKey($key)->value('name') ?? "App {$key}";
    }

    protected function urlFor(string $key): ?string
    {
        return SourceLinks::url(SourceDimension::App, $key);
    }
}
