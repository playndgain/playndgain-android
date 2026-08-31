<?php

namespace App\Filament\Admin\Resources\Conversions\Pages;

use App\Filament\Admin\Resources\Conversions\ConversionResource;
use App\Filament\Admin\Support\CsvExport;
use App\Models\Conversion;
use App\Services\Money\Money;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Livewire\Attributes\Url;

class ListConversions extends ListRecords
{
    protected static string $resource = ConversionResource::class;

    /**
     * Filament keeps table filters in Livewire state alone, so a reload drops
     * them and a filtered view cannot be handed to anyone. In the URL they
     * survive both — the link in a support thread opens the same rows the
     * person who sent it was looking at.
     *
     * @var array<string, mixed>|null
     */
    #[Url]
    public ?array $tableFilters = null;

    #[Url]
    public ?string $tableGrouping = null;

    /**
     * docs/07 §10. The columns are the table's own, plus the two IDs a support
     * thread is argued with — a spreadsheet of conversions with no `trans_id`
     * in it cannot be matched against anything a publisher sends us.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CsvExport::action($this, 'conversions', [
                'trans_id' => fn (Conversion $record): string => (string) $record->trans_id,
                'network_txid' => fn (Conversion $record): string => (string) $record->network_txid,
                'publisher' => fn (Conversion $record): string => '#'.$record->publisher?->pub_id,
                'app' => fn (Conversion $record): string => (string) $record->app?->name,
                'network' => fn (Conversion $record): string => (string) $record->network?->name,
                'offer' => fn (Conversion $record): string => (string) $record->offer_name,
                'revenue' => fn (Conversion $record): string => Money::format($record->revenue),
                'payout' => fn (Conversion $record): string => Money::format($record->payout),
                'profit' => fn (Conversion $record): string => bcsub(
                    Money::format($record->revenue),
                    Money::format($record->payout),
                    Money::SCALE,
                ),
                'user_reward' => fn (Conversion $record): string => (string) $record->currency_amount,
                'status' => fn (Conversion $record): string => $record->status->value,
                'country' => fn (Conversion $record): string => (string) $record->country,
                'is_test' => fn (Conversion $record): string => $record->is_test ? 'yes' : 'no',
                'created_at' => fn (Conversion $record): string => (string) $record->created_at,
                'clears_at' => fn (Conversion $record): string => (string) $record->clears_at,
            ]),
        ];
    }
}
