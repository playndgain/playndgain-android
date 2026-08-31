<?php

namespace App\Filament\Admin\Widgets;

use App\Models\Publisher;
use App\Services\Reporting\StatsQuery;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;

/** Top 10 publishers by revenue, last 30 days. */
class TopPublishersChart extends ChartWidget
{
    protected static ?int $sort = 6;

    protected ?string $heading = 'Top publishers by revenue — 30 days';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $to = CarbonImmutable::today();
        $rows = StatsQuery::make()->between($to->subDays(29), $to)->groupedBy('publisher_id');

        $publishers = Publisher::query()
            ->with('user:id,name')
            ->whereIn('id', $rows->map(fn (object $row): int => (int) $row->bucket))
            ->get()
            ->keyBy('id');

        return [
            'datasets' => [[
                'label' => 'Revenue',
                'data' => $rows->map(fn (object $row): float => (float) $row->revenue)->all(),
                'backgroundColor' => '#3B82F6',
            ]],
            'labels' => $rows->map(function (object $row) use ($publishers): string {
                $publisher = $publishers->get((int) $row->bucket);

                return $publisher === null ? "#{$row->bucket}" : "#{$publisher->pub_id} {$publisher->user?->name}";
            })->all(),
        ];
    }

    protected function getOptions(): array
    {
        return ['indexAxis' => 'y', 'plugins' => ['legend' => ['display' => false]]];
    }
}
