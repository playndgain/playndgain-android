<?php

namespace App\Filament\Admin\Support;

use App\Enums\ConversionStatus;
use App\Filament\Support\AppColumns;
use App\Filament\Tables\Columns\AppColumn;
use App\Models\Conversion;
use App\Services\Money\Money;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Summarizers\Count;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use ZPMLabs\FilamentPopup\Enums\PopUpPosition;

/**
 * The conversion row, defined once.
 *
 * It appears on the Conversions resource, inside the publisher's view page and
 * in the review queue, and a profit column that disagrees between two of those
 * is worse than no profit column at all.
 */
final class ConversionColumns
{
    /** @return list<TextColumn|IconColumn|AppColumn> */
    public static function full(): array
    {
        return [
            TextColumn::make('trans_id')->label('TX')->searchable()
                ->copyable()
                ->copyableState(fn (?string $state): ?string => $state)
                ->limit(18)
                ->summarize(Count::make()->label('Conversions')),
            // Click the pub number for who they are, without leaving the
            // ledger you were reading. The full account is one more click
            // away inside the card.
            TextColumn::make('publisher.pub_id')->label('Pub')->prefix('#')->sortable()
                ->popup(PublisherPopup::class, PopUpPosition::BottomLeft, ['width' => 'sm', 'trigger' => 'click']),
            AppColumns::show(),
            ...self::forPublisherContext(),
        ];
    }

    /**
     * The same row minus publisher and app, for tables already scoped to one.
     *
     * @return list<TextColumn|IconColumn>
     */
    public static function forPublisherContext(): array
    {
        return [
            TextColumn::make('offer_name')->label('Offer')->limit(28)->searchable(),
            TextColumn::make('network.name')->label('Network'),
            TextColumn::make('revenue')->money('USD')->sortable()
                ->summarize(Sum::make()->money('USD')),
            TextColumn::make('payout')->money('USD')->sortable()
                ->summarize(Sum::make()->money('USD')),
            TextColumn::make('profit')
                ->money('USD')
                ->state(fn (Conversion $record): string => bcsub(
                    Money::format($record->revenue),
                    Money::format($record->payout),
                    Money::SCALE,
                ))
                ->color('primary')
                // `profit` is derived per row and has no column to sum, so the
                // summary is the subtraction done in SQL over the same window.
                // Summing the rendered values would only ever total the page.
                // Sum would add `sum(profit)` to the summary query and ask the
                // database for a column that does not exist. The base
                // summarizer emits no select of its own, so `using` is the
                // whole aggregate.
                ->summarize(Summarizer::make()
                    ->label('Sum')
                    ->using(fn (Builder $query): string => (string) $query->sum(DB::raw('revenue - payout')))
                    ->money('USD')),
            TextColumn::make('currency_amount')->label('User reward')->numeric(decimalPlaces: 2),
            TextColumn::make('status')->badge()->color(fn (ConversionStatus $state): string => match ($state) {
                ConversionStatus::Approved => 'success',
                ConversionStatus::Pending => 'warning',
                ConversionStatus::Reversed => 'danger',
                ConversionStatus::Rejected => 'gray',
            }),
            IconColumn::make('is_test')->label('Test')->boolean()->falseIcon(null),
            TextColumn::make('country')->badge()->placeholder('—'),
            TextColumn::make('created_at')->label('Created')->dateTime()->sortable(),
            TextColumn::make('clears_at')->label('Clears')->date()->placeholder('—'),
        ];
    }
}
