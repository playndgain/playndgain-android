<?php

namespace App\Filament\Admin\Audience;

use Carbon\CarbonImmutable;
use LaBoiteACode\FilamentDashboardWidgets\Data\ChartSeries;
use LaBoiteACode\FilamentDashboardWidgets\Widgets\ComparisonChartWidget;

/**
 * Fixed at the 30 complete days plus today, whatever the range filter says —
 * the same days the average DAU card is taken over.
 */
class DailyActiveChart extends ComparisonChartWidget
{
    use ReadsAudienceFilters;

    protected static bool $isLazy = false;

    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Daily unique users';

    /** The home dashboard charts' height, so the page fits one screenshot. */
    protected ?string $maxHeight = '260px';

    public function getDescription(): ?string
    {
        return 'Last 30 complete days and today, days in '.$this->audienceWindow()->today->format('T');
    }

    /**
     * Counted from zero in whole users: a floor at the lowest day would
     * exaggerate the swings, and an all-zero series would draw negative ticks.
     *
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'suggestedMax' => 1,
                    'ticks' => ['precision' => 0],
                ],
            ],
        ];
    }

    protected function getSeries(): array
    {
        return [
            ChartSeries::make('Unique users')
                ->values($this->audience()->daily()->map(fn (int $users): float => (float) $users)->values()->all())
                ->color('primary')
                ->filled(),
        ];
    }

    protected function getLabels(): array
    {
        return $this->audience()->daily()
            ->keys()
            ->map(fn (string $date): string => CarbonImmutable::parse($date)->format('M j'))
            ->values()
            ->all();
    }
}
