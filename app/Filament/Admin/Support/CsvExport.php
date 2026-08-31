<?php

namespace App\Filament\Admin\Support;

use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Contracts\HasTable;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV export on a list page — docs/07 §10.
 *
 * Streamed out of the table's own filtered, sorted query, so the file is what
 * the admin is looking at: an export that ignores the filters they set is one
 * nobody can reconcile against the screen. Same `streamDownload` as
 * ProfitHistory and no export package — a header row and one `fputcsv` per
 * record is the whole requirement.
 *
 * ponytail: synchronous, where docs/07 §10 asks for queued-and-emailed on large
 * ranges. Rows stream a chunk at a time so memory is not the limit; the request
 * timeout is. Move it to a job when a real range hits that.
 */
final class CsvExport
{
    /**
     * @param  array<string, Closure>  $columns  Header => a closure handed the record.
     */
    public static function action(HasTable $page, string $prefix, array $columns): Action
    {
        return Action::make('export')
            ->label('Export CSV')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->action(fn (): StreamedResponse => self::stream($page, $prefix, $columns));
    }

    /**
     * @param  array<string, Closure>  $columns
     */
    private static function stream(HasTable $page, string $prefix, array $columns): StreamedResponse
    {
        // Read when the button is pressed rather than when it is built: the
        // filters that matter are the ones set after the page loaded.
        $query = $page->getFilteredSortedTableQuery();
        $name = $prefix.'-'.CarbonImmutable::now()->toDateString().'.csv';

        return response()->streamDownload(function () use ($query, $columns): void {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, array_keys($columns));

            // `lazy()` rather than `get()` or `cursor()`: a year of conversions
            // does not have to fit in memory, and unlike a cursor a chunked walk
            // still runs the table's eager loads — otherwise every publisher
            // name in the file costs its own query.
            foreach ($query?->lazy() ?? [] as $record) {
                fputcsv($handle, array_map(fn (Closure $value): mixed => $value($record), $columns));
            }

            fclose($handle);
        }, $name, ['Content-Type' => 'text/csv']);
    }
}
