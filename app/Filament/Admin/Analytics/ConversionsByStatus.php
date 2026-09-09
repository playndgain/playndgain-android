<?php

namespace App\Filament\Admin\Analytics;

use App\Enums\ConversionStatus;
use App\Filament\Admin\Resources\Conversions\ConversionResource;
use LaBoiteACode\FilamentDashboardWidgets\Data\BreakdownItem;
use LaBoiteACode\FilamentDashboardWidgets\Widgets\BreakdownWidget;

class ConversionsByStatus extends BreakdownWidget
{
    use ReadsAnalyticsFilters;

    protected static bool $isLazy = false;

    protected static ?int $sort = 12;

    protected int|string|array $columnSpan = 2;

    protected ?string $heading = 'Conversions by status';

    protected function getItems(): array
    {
        $counts = $this->risk()->statusCounts();

        if (array_sum($counts) === 0) {
            return [];
        }

        return array_map(
            fn (ConversionStatus $status): BreakdownItem => BreakdownItem::make(ucfirst($status->value), $counts[$status->value])
                ->color($status->getColor())
                ->url(ConversionResource::getUrl('index', [
                    'tableFilters' => ['status' => ['value' => $status->value]],
                ])),
            ConversionStatus::cases(),
        );
    }

    protected function getViewMoreUrl(): ?string
    {
        return ConversionResource::getUrl('index');
    }
}
