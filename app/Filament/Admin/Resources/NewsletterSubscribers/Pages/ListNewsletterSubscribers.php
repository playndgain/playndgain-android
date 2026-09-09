<?php

namespace App\Filament\Admin\Resources\NewsletterSubscribers\Pages;

use App\Filament\Admin\Resources\NewsletterSubscribers\NewsletterSubscriberResource;
use App\Filament\Admin\Support\CsvExport;
use App\Models\NewsletterSubscriber;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Livewire\Attributes\Url;

class ListNewsletterSubscribers extends ListRecords
{
    protected static string $resource = NewsletterSubscriberResource::class;

    /**
     * In the URL so a filtered view survives a reload and can be handed to
     * somebody — the same reason the ledger keeps its filters there.
     *
     * @var array<string, mixed>|null
     */
    #[Url]
    public ?array $tableFilters = null;

    /**
     * The export is the point of the page: the file goes into whatever sends
     * the release note. It carries the unsubscribe link per row, because a
     * mailing that goes out without one is the problem this whole change was
     * about, and the link has to be built here, where the signature can be.
     *
     * A row that left the list carries no link. CsvExport streams the table's
     * own filtered query, and the "Subscribed only" filter is a toggle an
     * operator can clear to see who left — so the file is not guaranteed to
     * hold only subscribers, and a link on one of those rows is a mailing
     * waiting to go to somebody who asked not to get it. `status` stays in the
     * file so the operator can still see which rows are which.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CsvExport::action($this, 'mailing-list', [
                'email' => fn (NewsletterSubscriber $record): string => $record->email,
                'status' => fn (NewsletterSubscriber $record): string => $record->isSubscribed() ? 'subscribed' : 'unsubscribed',
                'joined' => fn (NewsletterSubscriber $record): string => (string) $record->created_at,
                'unsubscribed' => fn (NewsletterSubscriber $record): string => (string) $record->unsubscribed_at,
                'source' => fn (NewsletterSubscriber $record): string => (string) $record->source,
                'unsubscribe_url' => fn (NewsletterSubscriber $record): string => $record->isSubscribed()
                    ? $record->unsubscribeUrl()
                    : '',
            ]),
        ];
    }
}
