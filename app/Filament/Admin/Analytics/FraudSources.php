<?php

namespace App\Filament\Admin\Analytics;

use App\Filament\Admin\Resources\FraudEvents\FraudEventResource;
use LaBoiteACode\FilamentDashboardWidgets\Data\BreakdownItem;

class FraudSources extends SourcedBreakdownWidget
{
    protected static ?int $sort = 21;

    protected ?string $heading = 'Fraud by source';

    protected function getItems(): array
    {
        $dimension = $this->dimension();

        return array_map(
            fn (object $row): BreakdownItem => BreakdownItem::make($row->label, $row->count)
                ->color('warning')
                ->url(SourceLinks::url($dimension, $row->key, $row->label)),
            $this->risk()->fraudBy($dimension),
        );
    }

    protected function getViewMoreUrl(): ?string
    {
        return FraudEventResource::getUrl('index');
    }
}
