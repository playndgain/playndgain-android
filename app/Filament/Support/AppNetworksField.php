<?php

namespace App\Filament\Support;

use App\Models\App;
use App\Support\AppNetworks;
use Filament\Forms\Components\CheckboxList;

/**
 * Enable / disable each active network on a placement. Admin only —
 * publishers do not see which suppliers we use.
 */
final class AppNetworksField
{
    public static function make(): CheckboxList
    {
        return CheckboxList::make('enabled_networks')
            ->label('Networks')
            ->options(fn (): array => AppNetworks::options())
            ->descriptions(function (?App $record): array {
                if ($record === null) {
                    return [];
                }

                $copy = [];

                foreach (AppNetworks::publisherWideBlockedIds((int) $record->publisher_id) as $id) {
                    $copy[(string) $id] = 'Blocked for every app this publisher owns. Remove the all-apps blacklist row to turn it back on here.';
                }

                return $copy;
            })
            ->disableOptionWhen(function (mixed $value, ?App $record): bool {
                if ($record === null) {
                    return false;
                }

                return in_array((int) $value, AppNetworks::publisherWideBlockedIds((int) $record->publisher_id), true);
            })
            ->default(fn (): array => AppNetworks::enabledIds(null))
            ->afterStateHydrated(function (CheckboxList $component, mixed $state, ?App $record): void {
                if (is_array($state)) {
                    return;
                }

                $component->state(AppNetworks::enabledIds($record));
            })
            ->bulkToggleable()
            ->columns(2)
            ->dehydrated(false)
            ->helperText('This app only. Off hides that network\'s offers and partner wall from this placement. A network we activate later starts on.')
            ->columnSpanFull();
    }
}
