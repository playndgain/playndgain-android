<?php

namespace App\Filament\Admin\Resources\LedgerEntries\Pages;

use App\Filament\Admin\Resources\LedgerEntries\LedgerEntryResource;
use App\Filament\Admin\Support\CsvExport;
use App\Models\LedgerEntry;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Livewire\Attributes\Url;

class ListLedgerEntries extends ListRecords
{
    protected static string $resource = LedgerEntryResource::class;

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
     * docs/07 §10. Read-only stays read-only: an export takes rows out, and
     * `balance_after` goes with them — a ledger without its running balance is
     * a list of amounts nobody can check against the publisher's balance.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CsvExport::action($this, 'ledger', [
                'date' => fn (LedgerEntry $record): string => (string) $record->created_at,
                'publisher' => fn (LedgerEntry $record): string => '#'.$record->publisher?->pub_id,
                'publisher_name' => fn (LedgerEntry $record): string => (string) $record->publisher?->user?->name,
                'type' => fn (LedgerEntry $record): string => $record->type->value,
                'amount' => fn (LedgerEntry $record): string => (string) $record->amount,
                'balance_after' => fn (LedgerEntry $record): string => (string) $record->balance_after,
                'reference' => fn (LedgerEntry $record): string => $record->reference_type === null
                    ? ''
                    : class_basename($record->reference_type).' #'.$record->reference_id,
                'description' => fn (LedgerEntry $record): string => (string) $record->description,
                'created_by' => fn (LedgerEntry $record): string => (string) ($record->creator?->name ?? 'system'),
            ]),
        ];
    }
}
