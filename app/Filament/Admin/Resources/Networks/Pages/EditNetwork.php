<?php

namespace App\Filament\Admin\Resources\Networks\Pages;

use App\Enums\NetworkIntegration;
use App\Filament\Admin\Resources\Networks\NetworkResource;
use App\Filament\Admin\Resources\Networks\Pages\Concerns\LogsPostbackUrlCopy;
use App\Jobs\SyncNetworkOffersJob;
use App\Models\AuditLog;
use App\Models\Network;
use App\Networks\OfferCacheFlusher;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditNetwork extends EditRecord
{
    use LogsPostbackUrlCopy;

    protected static string $resource = NetworkResource::class;

    /**
     * Set in `mutateFormDataBeforeSave` against the row as it was, because
     * `afterSave` sees the persisted model and `getChanges()` is already empty.
     */
    private bool $resyncCatalogue = false;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reveal')
                ->label('Reveal credentials')
                ->icon('heroicon-o-eye')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Reading these is written to the audit log.')
                ->action(function (): void {
                    /** @var Network $network */
                    $network = $this->getRecord();

                    AuditLog::record('network.credentials_revealed', $network);

                    $lines = collect($network->credentials ?? [])
                        ->map(fn (mixed $value, string $key): string => "{$key}: {$value}")
                        ->implode("\n");

                    Notification::make()
                        ->title("Credentials for {$network->name}")
                        ->body($lines === '' ? 'None stored.' : $lines)
                        ->persistent()
                        ->warning()
                        ->send();
                }),
            ActionGroup::make(NetworkResource::managementActions()),
        ];
    }

    /**
     * `Network::$hidden` keeps credentials out of `attributesToArray()`, so
     * Filament would otherwise leave every credential field empty.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Network $network */
        $network = $this->getRecord();

        $data['credentials'] = $network->credentials ?? [];

        return $data;
    }

    /**
     * A blank secret means "unchanged", not "clear it". Without this merge the
     * first save through the form would silently wipe every key the driver
     * needs and the network would stop syncing with no visible cause.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Network $network */
        $network = $this->getRecord();

        $mode = NetworkIntegration::fromForm($data['integration'] ?? null);
        $data['integration'] = $mode;
        $data['hosts_wall'] = $mode->hostsWall();

        $data['credentials'] = [
            ...($network->credentials ?? []),
            ...array_filter($data['credentials'] ?? [], fn (mixed $value): bool => filled($value)),
        ];

        $this->resyncCatalogue = $this->catalogueSourceChanged($network, $data);

        return $data;
    }

    protected function afterSave(): void
    {
        /** @var Network $network */
        $network = $this->getRecord();

        OfferCacheFlusher::flushForNetwork($network);

        if ($this->resyncCatalogue && $network->syncsCatalogue()) {
            SyncNetworkOffersJob::dispatch($network);

            Notification::make()->title('Sync queued')->success()->send();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function catalogueSourceChanged(Network $network, array $data): bool
    {
        return $data['integration'] !== ($network->integration ?? NetworkIntegration::Api)
            || ($data['credentials'] ?? []) != ($network->credentials ?? [])
            || collect($data['unskipped_external_ids'] ?? [])->map(fn (mixed $id): string => trim((string) $id))->filter()->unique()->values()->all()
                !== $network->unskippedExternalIds();
    }
}
