<?php

namespace App\Filament\Admin\Widgets;

use App\Services\Reporting\StatsQuery;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;

/** Top 10 countries by revenue, last 30 days. */
class TopCountriesChart extends ChartWidget
{
    protected static ?int $sort = 5;

    protected ?string $heading = 'Top countries by revenue — 30 days';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $to = CarbonImmutable::today();
        $rows = StatsQuery::make()->between($to->subDays(29), $to)->groupedBy('country');

        return [
            'datasets' => [[
                'label' => 'Revenue',
                'data' => $rows->map(fn (object $row): float => (float) $row->revenue)->all(),
                'backgroundColor' => '#DFC08A',
            ]],
            'labels' => $rows->map(fn (object $row): string => (string) $row->bucket)->all(),
        ];
    }

    protected function getOptions(): array
    {
        return ['indexAxis' => 'y', 'plugins' => ['legend' => ['display' => false]]];
    }
}
