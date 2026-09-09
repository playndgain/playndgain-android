<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\ConversionStatus;
use App\Filament\Admin\Resources\Conversions\ConversionResource;
use App\Models\Conversion;
use Carbon\CarbonImmutable;
use LaBoiteACode\FilamentDashboardWidgets\Data\BreakdownItem;
use LaBoiteACode\FilamentDashboardWidgets\Widgets\BreakdownWidget;

/**
 * The last seven days of conversions split by what happened to them. A
 * pending share that grows is a review queue nobody is clearing; a reversed
 * share that grows is a chargeback problem. Both are invisible in totals.
 *
 * Colours are the status colours the enum already declares — green settled,
 * amber waiting, rose reversed — so the bars match the badges on the table
 * a click away.
 */
class ConversionsByStatus extends BreakdownWidget
{
    protected static ?int $sort = 9;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Conversions by status — 7 days';

    protected ?string $headingDescription = 'Share of every conversion recorded in the last seven days.';

    /** @return list<BreakdownItem> */
    protected function getItems(): array
    {
        $counts = Conversion::query()
            ->withoutGlobalScopes()
            ->where('created_at', '>=', CarbonImmutable::today()->subDays(6))
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return array_map(
            fn (ConversionStatus $status): BreakdownItem => BreakdownItem::make(ucfirst($status->value), (int) ($counts[$status->value] ?? 0))
                ->color($status->getColor())
                ->url(ConversionResource::getUrl('index', ['filters' => ['status' => ['value' => $status->value]]])),
            ConversionStatus::cases(),
        );
    }

    protected function getViewMoreUrl(): ?string
    {
        return ConversionResource::getUrl('index');
    }
}
