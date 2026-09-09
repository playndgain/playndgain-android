<?php

namespace App\Filament\Admin\Analytics;

use App\Services\Money\Money;
use LaBoiteACode\FilamentDashboardWidgets\Data\BreakdownItem;

class ChargebackSources extends SourcedBreakdownWidget
{
    protected static ?int $sort = 18;

    protected ?string $heading = 'Chargebacks by source';

    protected function getItems(): array
    {
        $dimension = $this->dimension();

        return array_map(
            fn (object $row): BreakdownItem => BreakdownItem::make($row->label, (float) $row->payout)
                ->formatUsing(fn (float $value): string => Money::display((string) $value))
                ->description($row->count === 1 ? '1 reversal' : number_format($row->count).' reversals')
                ->color('loss')
                ->url(SourceLinks::url($dimension, $row->key, $row->label)),
            $this->risk()->chargebacksBy($dimension),
        );
    }

    protected function getViewMoreUrl(): ?string
    {
        return SourceLinks::reversedConversions();
    }
}
