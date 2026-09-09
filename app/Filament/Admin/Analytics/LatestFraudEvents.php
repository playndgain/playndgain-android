<?php

namespace App\Filament\Admin\Analytics;

use App\Enums\FraudActionTaken;
use App\Filament\Admin\Resources\FraudEvents\FraudEventResource;
use App\Models\FraudEvent;
use LaBoiteACode\FilamentDashboardWidgets\Data\RecentItem;
use LaBoiteACode\FilamentDashboardWidgets\Widgets\RecentItemsWidget;

class LatestFraudEvents extends RecentItemsWidget
{
    use ReadsAnalyticsFilters;

    protected static bool $isLazy = false;

    protected static ?int $sort = 22;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Latest fraud events';

    protected function getItems(): array
    {
        return array_map(
            function (FraudEvent $event): RecentItem {
                $reason = is_string($event->context['reason'] ?? null) ? $event->context['reason'] : null;

                return RecentItem::make($event->rule_key, $reason)
                    ->meta($event->created_at?->diffForHumans())
                    ->badge(ucfirst($event->action_taken->value))
                    ->badgeColor(match ($event->action_taken) {
                        FraudActionTaken::Blocked => 'danger',
                        FraudActionTaken::Flagged => 'warning',
                        FraudActionTaken::Scored => 'gray',
                    })
                    ->url(FraudEventResource::getUrl('index'));
            },
            $this->risk()->latestFraud(),
        );
    }

    protected function getViewAllUrl(): ?string
    {
        return FraudEventResource::getUrl('index');
    }
}
