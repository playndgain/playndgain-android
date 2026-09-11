<?php

namespace App\Filament\Admin\Resources\AccountMatches\Pages;

use App\Filament\Admin\Resources\AccountMatches\AccountMatchResource;
use Filament\Resources\Pages\ListRecords;

class ListAccountMatches extends ListRecords
{
    protected static string $resource = AccountMatchResource::class;
}
