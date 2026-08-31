<?php

namespace App\Filament\Publisher\Resources\BannedEndUsers\Pages;

use App\Filament\Publisher\Resources\BannedEndUsers\BannedEndUserResource;
use Filament\Resources\Pages\ListRecords;

class ListBannedEndUsers extends ListRecords
{
    protected static string $resource = BannedEndUserResource::class;
}
