<?php

namespace App\Filament\Admin\Resources\Announcements\Pages;

use App\Filament\Admin\Resources\Announcements\AnnouncementResource;
use Marcelodelgado\Announcements\Filament\Resources\AnnouncementResource\Pages\CreateAnnouncement as VendorCreateAnnouncement;

class CreateAnnouncement extends VendorCreateAnnouncement
{
    protected static string $resource = AnnouncementResource::class;
}
