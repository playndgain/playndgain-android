<?php

namespace App\Filament\Admin\Resources\Networks\Pages;

use App\Filament\Admin\Resources\Networks\NetworkResource;
use App\Filament\Admin\Resources\Networks\Pages\Concerns\LogsPostbackUrlCopy;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListNetworks extends ListRecords
{
    use LogsPostbackUrlCopy;

    protected static string $resource = NetworkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New network'),
        ];
    }
}
