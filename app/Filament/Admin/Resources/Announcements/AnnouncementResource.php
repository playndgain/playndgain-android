<?php

namespace App\Filament\Admin\Resources\Announcements;

use App\Filament\Admin\Resources\Announcements\Pages\CreateAnnouncement;
use App\Filament\Admin\Resources\Announcements\Pages\EditAnnouncement;
use App\Filament\Admin\Resources\Announcements\Pages\ListAnnouncements;
use Marcelodelgado\Announcements\Filament\Resources\AnnouncementResource as VendorAnnouncementResource;

/**
 * The vendor resource, placed. Announcements are written here and shown on
 * both dashboards; the plugin's own class would register the resource with
 * no navigation group, above Overview, so this subclass exists to put it in
 * System and to own its page classes.
 *
 * Access is the `manage-announcements` gate (config/announcements.php), which
 * is admin-only — the publisher panel never registers this resource at all.
 */
class AnnouncementResource extends VendorAnnouncementResource
{
    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 70;

    public static function getPages(): array
    {
        return [
            'index' => ListAnnouncements::route('/'),
            'create' => CreateAnnouncement::route('/create'),
            'edit' => EditAnnouncement::route('/{record}/edit'),
        ];
    }
}
