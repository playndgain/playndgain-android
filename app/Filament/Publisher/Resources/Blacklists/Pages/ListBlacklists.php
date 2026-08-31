<?php

namespace App\Filament\Publisher\Resources\Blacklists\Pages;

use App\Enums\BlacklistType;
use App\Filament\Publisher\Resources\Blacklists\BlacklistResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * One tab per publisher-facing blacklist type — docs/08 §12.
 *
 * Tabs rather than the type dropdown they replace: the three kinds of block
 * are three different things a publisher comes here to do, and a filter they
 * have to open first hides two of them behind a click. Network is not a tab:
 * which suppliers we use is ours.
 */
class ListBlacklists extends ListRecords
{
    protected static string $resource = BlacklistResource::class;

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return collect(BlacklistType::publisherCases())
            ->mapWithKeys(fn (BlacklistType $type): array => [
                $type->value => Tab::make(Str::plural(Str::ucfirst($type->value)))
                    ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('type', $type)),
            ])
            ->all();
    }
}
