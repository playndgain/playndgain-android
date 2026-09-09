<?php

namespace App\Filament\Admin\Analytics;

use App\Filament\Admin\Resources\FraudEvents\FraudEventResource;
use LaBoiteACode\FilamentDashboardWidgets\Data\BreakdownItem;
use LaBoiteACode\FilamentDashboardWidgets\Widgets\BreakdownWidget;

class FraudByRule extends BreakdownWidget
{
    use ReadsAnalyticsFilters;

    protected static bool $isLazy = false;

    protected static ?int $sort = 20;

    protected int|string|array $columnSpan = 2;

    protected ?string $heading = 'Fraud by rule';

    protected function getItems(): array
    {
        return array_map(
            fn (object $row): BreakdownItem => BreakdownItem::make($row->key, $row->count)
                ->color('warning')
                ->url(FraudEventResource::getUrl('index', [
                    'tableFilters' => ['rule_key' => ['value' => $row->key]],
                ])),
            $this->risk()->fraudByRule(),
        );
    }

    protected function getViewMoreUrl(): ?string
    {
        return FraudEventResource::getUrl('index');
    }
}
