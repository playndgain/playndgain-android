<?php

namespace App\Filament\Admin\Support;

use App\Filament\Admin\Resources\Publishers\PublisherResource;
use App\Models\Conversion;
use App\Models\Publisher;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * The card behind a pub number in a conversion table: who the publisher is,
 * where they stand, and a way through to the account.
 *
 * The popup host binds the *conversion* to the schema after this runs, so
 * every entry reads through the `publisher` relation rather than the schema
 * being re-pointed at the publisher — a `->record()` set here is overwritten.
 */
final class PublisherPopup
{
    public static function make(Schema $schema, mixed $record): Schema
    {
        $publisher = $record instanceof Conversion ? $record->publisher : null;

        if (! $publisher instanceof Publisher) {
            return $schema->components([
                TextEntry::make('missing')->hiddenLabel()->state('No publisher on this row.'),
            ]);
        }

        return $schema->components([
            TextEntry::make('publisher.company_name')
                ->hiddenLabel()
                ->weight('bold')
                ->size('lg'),
            Grid::make(2)->schema([
                TextEntry::make('publisher.pub_id')->label('Pub')->prefix('#'),
                TextEntry::make('publisher.status')->label('Status')->badge(),
                TextEntry::make('publisher.user.email')->label('Email')->copyable()->columnSpanFull(),
                TextEntry::make('publisher.balance')->label('Balance')->money('USD')->color('gain'),
                TextEntry::make('publisher.created_at')->label('Joined')->since(),
            ]),
            Actions::make([
                Action::make('openPublisher')
                    ->label('Open account')
                    ->icon(Heroicon::OutlinedArrowRight)
                    ->iconPosition('after')
                    ->link()
                    ->url(PublisherResource::getUrl('view', ['record' => $publisher])),
            ]),
        ]);
    }
}
