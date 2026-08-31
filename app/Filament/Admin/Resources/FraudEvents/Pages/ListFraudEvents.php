<?php

namespace App\Filament\Admin\Resources\FraudEvents\Pages;

use App\Filament\Admin\Resources\FraudEvents\FraudEventResource;
use Filament\Resources\Pages\ListRecords;

class ListFraudEvents extends ListRecords
{
    protected static string $resource = FraudEventResource::class;
}
