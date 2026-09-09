<?php

namespace App\Filament\Publisher\Widgets;

use App\Filament\Publisher\Support\CurrentPublisher;
use App\Filament\Publisher\Widgets\Concerns\FiltersBySelectedApp;
use App\Services\Reporting\StatsQuery;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;

/** Where the clicks come from, last 30 days — docs/08 §3. */
class TopCountriesTable extends ChartWidget
{
    use FiltersBySelectedApp;

    protected static ?int $sort = 4;

    protected ?string $heading = 'Top countries by clicks — 30 days';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $to = CarbonImmutable::today();

        $rows = StatsQuery::make()
            ->forPublisher(CurrentPublisher::id())
            ->forApp($this->selectedAppId())
            ->between($to->subDays(29), $to)
            ->groupedBy('country');

        return [
            'datasets' => [[
                'label' => 'Clicks',
                'data' => $rows->map(fn (object $row): int => (int) $row->clicks)->all(),
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
