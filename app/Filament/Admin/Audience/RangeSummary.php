<?php

namespace App\Filament\Admin\Audience;

use LaBoiteACode\FilamentDashboardWidgets\Data\Detail;
use LaBoiteACode\FilamentDashboardWidgets\Widgets\DetailListWidget;

class RangeSummary extends DetailListWidget
{
    use ReadsAudienceFilters;

    protected static bool $isLazy = false;

    protected static ?int $sort = 8;

    protected int|string|array $columnSpan = ['default' => 1, 'md' => 2, 'xl' => 2];

    protected ?string $heading = 'Selected range';

    public function getHeadingDescription(): ?string
    {
        $window = $this->audienceWindow();
        $last = $window->rangeUntil->subSecond();

        return $window->rangeFrom->isSameDay($last)
            ? $window->rangeFrom->format('j M Y')
            : $window->rangeFrom->format('j M').' – '.$last->format('j M Y');
    }

    protected function getDetails(): array
    {
        $audience = $this->audience();
        $count = fn (int $value): string => number_format($value);

        return [
            Detail::make('Unique users', $audience->rangeActive())->formatUsing($count),
            Detail::make('With a known country', $audience->countries()->sum())->formatUsing($count),
            Detail::make('Unknown country', $audience->unknownCountry())->formatUsing($count),
            Detail::make('Countries', $audience->countries()->count())->formatUsing($count),
        ];
    }
}
