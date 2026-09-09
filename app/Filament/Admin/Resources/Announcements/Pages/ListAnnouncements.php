<?php

namespace App\Filament\Admin\Resources\Announcements\Pages;

use App\Filament\Admin\Resources\Announcements\AnnouncementResource;
use Marcelodelgado\Announcements\Filament\Resources\AnnouncementResource\Pages\ListAnnouncements as VendorListAnnouncements;

class ListAnnouncements extends VendorListAnnouncements
{
    protected static string $resource = AnnouncementResource::class;
}
