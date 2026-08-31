<?php

namespace App\Filament\Publisher\Resources\Transactions\Pages;

use App\Filament\Publisher\Resources\Transactions\TransactionResource;
use Filament\Resources\Pages\ListRecords;

class ListTransactions extends ListRecords
{
    protected static string $resource = TransactionResource::class;
}
