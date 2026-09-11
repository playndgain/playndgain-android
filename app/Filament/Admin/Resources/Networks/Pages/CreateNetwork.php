<?php

namespace App\Filament\Admin\Resources\Networks\Pages;

use App\Enums\NetworkIntegration;
use App\Enums\NetworkStatus;
use App\Filament\Admin\Resources\Networks\NetworkResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * This page is the only way to make a network. `NetworkSeeder` is a local
 * fixture and a no-op in production, so an account that should exist is
 * added here from the connector cards.
 *
 * It deliberately does not offer `status`: a network is switched on by pasting
 * credentials and hitting Test connection, and a row that arrives active with
 * empty credentials is a live money endpoint that cannot work. The select is
 * disabled on this page and shows the value the row will be saved with.
 */
class CreateNetwork extends CreateRecord
{
    protected static string $resource = NetworkResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Same derivation as EditNetwork: `hosts_wall` is kept in step with
        // `integration` on save rather than inferred from `wall_url`, so the
        // wall fields can be typed before the URL exists.
        $mode = NetworkIntegration::fromForm($data['integration'] ?? null);
        $data['integration'] = $mode;
        $data['hosts_wall'] = $mode->hostsWall();

        // Enforced here as well as disabled in the form: the field is not
        // dehydrated, so without this the column falls back to whatever the
        // database default is.
        $data['status'] = NetworkStatus::Disabled;

        // Blank means "not set yet", never an empty credential — the same rule
        // the edit form applies when merging over stored values.
        $data['credentials'] = array_filter(
            $data['credentials'] ?? [],
            fn (mixed $value): bool => filled($value),
        );

        return $data;
    }
}
