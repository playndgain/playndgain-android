<?php

namespace App\Filament\Publisher\Resources\PaymentMethods\Pages;

use App\Filament\Publisher\Resources\PaymentMethods\PaymentMethodResource;
use Filament\Resources\Pages\ListRecords;

class ListPaymentMethods extends ListRecords
{
    protected static string $resource = PaymentMethodResource::class;
}
