<?php

namespace App\Filament\Admin\Resources\Announcements\Pages;

use App\Filament\Admin\Resources\Announcements\AnnouncementResource;
use Marcelodelgado\Announcements\Filament\Resources\AnnouncementResource\Pages\EditAnnouncement as VendorEditAnnouncement;

class EditAnnouncement extends VendorEditAnnouncement
{
    protected static string $resource = AnnouncementResource::class;
}
