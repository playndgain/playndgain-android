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
    private const PALETTE = ['#F5A524', '#3B82F6', '#10B981', '#EF4444', '#8B5CF6', '#EC4899', '#14B8A6', '#F97316'];

    protected static ?int $sort = 4;

    protected ?string $heading = 'Conversions by network — 7 days';

    protected int|string|array $columnSpan = 2;

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
