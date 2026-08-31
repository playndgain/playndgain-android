<?php

namespace App\Filament\Admin\Resources\IncomingPostbacks\Pages;

use App\Filament\Admin\Resources\IncomingPostbacks\IncomingPostbackResource;
use Filament\Resources\Pages\ListRecords;
use Livewire\Attributes\Url;

class ListIncomingPostbacks extends ListRecords
{
    protected static string $resource = IncomingPostbackResource::class;

    /**
     * Filament keeps table filters in Livewire state alone, so a reload drops
     * them and a filtered view cannot be handed to anyone. In the URL they
     * survive both — the link in a support thread opens the same rows the
     * person who sent it was looking at.
     *
     * @var array<string, mixed>|null
     */
    #[Url]
    public ?array $tableFilters = null;

    #[Url]
    public ?string $tableGrouping = null;
}
