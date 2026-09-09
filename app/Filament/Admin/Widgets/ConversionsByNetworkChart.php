<?php

namespace App\Filament\Admin\Widgets;

use App\Models\Network;
use App\Services\Reporting\StatsQuery;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;

/**
 * Seven days, conversions stacked by network. A band that flattens is a network
 * that stopped delivering, which the totals alone would hide.
 */
class ConversionsByNetworkChart extends ChartWidget
{
    /** Chart.js default palette, one colour per band. */
    private const PALETTE = ['#DFC08A', '#93A1BA', '#6FD3A3', '#D98B8B', '#C9A6F0', '#E8D1A6', '#7FA394', '#38486A'];

    protected static ?int $sort = 4;

    protected ?string $heading = 'Conversions by network — 7 days';

    protected int|string|array $columnSpan = 1;

    protected ?string $maxHeight = '260px';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $to = CarbonImmutable::today();
        $from = $to->subDays(6);

        $series = StatsQuery::make()->between($from, $to)->dailySeriesBy('network_id', 'conversions', $from, $to);
        $names = Network::query()->whereIn('id', $series->keys())->pluck('name', 'id');

        $datasets = $series->values()->map(function (array $points, int $index) use ($series, $names): array {
            $networkId = (int) $series->keys()->get($index);
            $colour = self::PALETTE[$index % count(self::PALETTE)];

            return [
                'label' => $names[$networkId] ?? "Network {$networkId}",
                'data' => array_values($points),
                'borderColor' => $colour,
                'backgroundColor' => $colour.'33',
                'fill' => true,
                'tension' => 0.3,
            ];
        })->all();

        return [
            'datasets' => $datasets,
            'labels' => collect($series->first() ?? [])
                ->keys()
                ->map(fn (string $date): string => CarbonImmutable::parse($date)->format('M j'))
                ->all(),
        ];
    }

    protected function getOptions(): array
    {
        return ['scales' => ['y' => ['stacked' => true]]];
    }
}
