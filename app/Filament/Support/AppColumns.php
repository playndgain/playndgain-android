<?php

namespace App\Filament\Support;

use App\Enums\AppStatus;
use App\Filament\Tables\Columns\AppColumn;
use App\Models\App;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;

/**
 * The app row, defined once.
 *
 * It appears on the admin Apps resource, the publisher's own list, and the
 * apps tab on a publisher — a status pill that disagrees between those is
 * worse than a plain badge.
 */
final class AppColumns
{
    public static function identity(): AppColumn
    {
        return AppColumn::make('name')
            ->label('App')
            ->searchable(['name', 'app_id'])
            ->sortable();
    }

    /**
     * The same identity cell on a row that belongs to an app (conversion,
     * click, postback, ban). Load `AppColumns::eager()` on the query.
     */
    public static function show(): AppColumn
    {
        return AppColumn::make('app')
            ->label('App')
            ->searchable();
    }

    /** Columns the identity cell reads. Use as `with(AppColumns::eager())`. */
    public static function eager(string ...$extra): string
    {
        return 'app:'.implode(',', ['id', 'name', 'app_id', 'icon_path', 'url', ...$extra]);
    }

    public static function platform(): ViewColumn
    {
        // No alignCenter: it centres the header label only, while the pill in
        // the cell starts at the column's 12px inset — the header then sits
        // off its own content. Status is the pattern; these two follow it.
        return ViewColumn::make('platform')
            ->view('filament.tables.columns.platform-mark')
            ->grow(false)
            ->sortable();
    }

    public static function status(): ViewColumn
    {
        return ViewColumn::make('status')
            ->view('filament.tables.columns.status-pill')
            ->sortable();
    }

    public static function postback(): ViewColumn
    {
        return ViewColumn::make('postback_url')
            ->label('Postback')
            ->view('filament.tables.columns.postback-pill')
            ->grow(false);
    }

    /**
     * @return list<TextColumn>
     */
    public static function activity(string $moneyColumn, string $moneyLabel): array
    {
        return [
            TextColumn::make('clicks_7d')
                ->label('Clicks 7d')
                ->numeric()
                ->alignEnd()
                ->fontFamily(FontFamily::Mono)
                ->sortable(),
            TextColumn::make('conversions_7d')
                ->label('Conv 7d')
                ->numeric()
                ->alignEnd()
                ->fontFamily(FontFamily::Mono)
                ->sortable(),
            TextColumn::make($moneyColumn)
                ->label($moneyLabel.' 7d')
                ->money('USD')
                ->alignEnd()
                ->sortable()
                ->color(fn (mixed $state): string => (float) $state > 0 ? 'success' : 'gray'),
        ];
    }

    /** @return list<string> */
    public static function recordClasses(App $record): array
    {
        return match ($record->status) {
            AppStatus::Pending => ['ad-row--pending'],
            AppStatus::Paused => ['ad-row--paused'],
            AppStatus::Rejected => ['ad-row--rejected'],
            AppStatus::Active => [],
        };
    }
}
