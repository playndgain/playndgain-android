<?php

namespace App\Filament\Admin\Resources\Withdrawals\Pages;

use App\Filament\Admin\Resources\Withdrawals\WithdrawalResource;
use App\Filament\Admin\Support\CsvExport;
use App\Models\Withdrawal;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListWithdrawals extends ListRecords
{
    protected static string $resource = WithdrawalResource::class;

    /**
     * docs/07 §10. The destination is masked here exactly as it is in the
     * table: a spreadsheet of payout addresses is the one artefact of this
     * panel that is worth stealing on its own.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CsvExport::action($this, 'withdrawals', [
                'id' => fn (Withdrawal $record): string => (string) $record->id,
                'publisher' => fn (Withdrawal $record): string => '#'.$record->publisher?->pub_id,
                'publisher_name' => fn (Withdrawal $record): string => (string) $record->publisher?->user?->name,
                'amount' => fn (Withdrawal $record): string => (string) $record->amount,
                'method' => fn (Withdrawal $record): string => $record->method->value,
                'destination' => fn (Withdrawal $record): string => WithdrawalResource::maskDestination($record),
                'status' => fn (Withdrawal $record): string => $record->status->value,
                'tx_reference' => fn (Withdrawal $record): string => (string) $record->tx_reference,
                'requested_at' => fn (Withdrawal $record): string => (string) $record->requested_at,
                'paid_at' => fn (Withdrawal $record): string => (string) $record->paid_at,
            ]),
        ];
    }
}
