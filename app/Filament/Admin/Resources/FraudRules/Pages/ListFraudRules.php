<?php

namespace App\Filament\Admin\Resources\FraudRules\Pages;

use App\Filament\Admin\Resources\FraudRules\FraudRuleResource;
use Filament\Resources\Pages\ListRecords;

class ListFraudRules extends ListRecords
{
    protected static string $resource = FraudRuleResource::class;
}
