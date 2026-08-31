<?php

namespace App\Filament\Admin\Resources\Offers\Pages;

use App\Filament\Admin\Resources\Offers\OfferResource;
use App\Models\AuditLog;
use App\Models\Offer;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

class ListOffers extends ListRecords
{
    protected static string $resource = OfferResource::class;

    /**
     * Manual offers — a direct advertiser deal, or something we sell ourselves.
     * They live on the seeded `direct` network so that clicks, postbacks, fraud
     * checks and the ledger treat them exactly like a network's offer.
     *
     * @return array<int, CreateAction>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Add offer')
                ->icon(Heroicon::OutlinedPlus)
                ->modalHeading('Add a direct offer')
                ->modalDescription('For an advertiser we deal with directly. Give them the postback URL from the house network once it is saved.')
                ->visible(fn (): bool => OfferResource::directNetwork() !== null)
                ->mutateDataUsing(function (array $data): array {
                    $now = now();

                    return [
                        ...$data,
                        'network_id' => OfferResource::directNetwork()?->id,
                        // Unique with network_id, and the advertiser has no id
                        // of their own to give us.
                        'external_id' => 'direct-'.Str::lower(Str::random(12)),
                        // The table sorts on last_seen_at; a null sinks a new
                        // offer to the bottom of five thousand synced rows.
                        'first_seen_at' => $now,
                        'last_seen_at' => $now,
                    ];
                })
                ->after(fn (Offer $record) => AuditLog::record('offer.created', $record)),
        ];
    }
}
