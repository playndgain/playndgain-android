<?php

namespace App\Filament\Admin\Resources\BannedEndUsers\Pages;

use App\Filament\Admin\Resources\BannedEndUsers\BannedEndUserResource;
use Filament\Resources\Pages\ListRecords;

class ListBannedEndUsers extends ListRecords
{
    protected static string $resource = BannedEndUserResource::class;
}
