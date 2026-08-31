<?php

namespace App\Filament\Admin\Resources\AdminUsers\Pages;

use App\Filament\Admin\Resources\AdminUsers\AdminUserResource;
use Filament\Resources\Pages\ListRecords;

class ListAdminUsers extends ListRecords
{
    protected static string $resource = AdminUserResource::class;
}
