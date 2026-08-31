<?php

namespace App\Filament\Admin\Resources\OutgoingPostbacks\Pages;

use App\Filament\Admin\Resources\OutgoingPostbacks\OutgoingPostbackResource;
use Filament\Resources\Pages\ListRecords;

class ListOutgoingPostbacks extends ListRecords
{
    protected static string $resource = OutgoingPostbackResource::class;
}
