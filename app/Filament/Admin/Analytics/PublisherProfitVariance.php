<?php

namespace App\Filament\Admin\Analytics;

use App\Enums\SourceDimension;
use App\Models\Publisher;
use App\Services\Money\Money;
use LaBoiteACode\FilamentDashboardWidgets\Data\VarianceItem;
use LaBoiteACode\FilamentDashboardWidgets\Widgets\VarianceWidget;

class PublisherProfitVariance extends VarianceWidget
{
    use ReadsAnalyticsFilters;

    protected static bool $isLazy = false;

    protected static ?int $sort = 17;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Publisher profit vs previous';

    protected function getItems(): array
    {
        $window = $this->window();
        $current = $window->stats()->groupedBy('publisher_id', 10)->keyBy(fn (object $row): string => (string) $row->bucket);
        $previous = $window->previousStats()->groupedBy('publisher_id', 25)->keyBy(fn (object $row): string => (string) $row->bucket);

        $keys = $current->keys()->merge($previous->keys())->unique();

        $publishers = Publisher::query()
            ->withoutGlobalScopes()
            ->with('user:id,name')
            ->whereIn('id', $keys)
            ->get()
            ->keyBy('id');

        return $keys
            ->map(function (string $key) use ($current, $previous, $publishers): VarianceItem {
                $now = $current->get($key);
                $then = $previous->get($key);
                $nowProfit = $now === null ? 0.0 : (float) $now->revenue - (float) $now->payout;
                $thenProfit = $then === null ? 0.0 : (float) $then->revenue - (float) $then->payout;
                $publisher = $publishers->get((int) $key);
                $label = $publisher === null ? "#{$key}" : "#{$publisher->pub_id} {$publisher->user?->name}";

                return VarianceItem::make($label, $nowProfit)
                    ->previous($thenProfit)
                    ->formatUsing(fn (float $value): string => Money::display((string) $value))
                    ->url(SourceLinks::url(SourceDimension::Publisher, $key));
            })
            ->sortByDesc(fn (VarianceItem $item): float => abs($item->getChange()))
            ->take(10)
            ->values()
            ->all();
    }

    protected function shouldSortByChange(): bool
    {
        return false;
    }
}
