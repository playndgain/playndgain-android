<?php

namespace App\Filament\Admin\Analytics;

use App\Enums\SourceDimension;
use LaBoiteACode\FilamentDashboardWidgets\Widgets\BreakdownWidget;
use Livewire\Attributes\Url;

abstract class SourcedBreakdownWidget extends BreakdownWidget
{
    use ReadsAnalyticsFilters;

    protected static bool $isLazy = false;

    #[Url]
    public string $source = 'publisher';

    protected int|string|array $columnSpan = 'full';

    /**
     * @var view-string
     */
    protected string $view = 'filament.admin.analytics.sourced-breakdown';

    protected function dimension(): SourceDimension
    {
        return SourceDimension::fromFilter($this->source);
    }

    /**
     * @return array<string, string>
     */
    public function sourceOptions(): array
    {
        return collect(SourceDimension::cases())
            ->mapWithKeys(fn (SourceDimension $dimension): array => [$dimension->value => $dimension->label()])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            ...parent::getViewData(),
            'source' => $this->dimension()->value,
            'sourceOptions' => $this->sourceOptions(),
        ];
    }
}
