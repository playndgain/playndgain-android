<?php

namespace App\Filament\Admin\Support;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The date window every log and money table is read through, defined once.
 *
 * Three tables carried their own copy of a from/until pair, and every question
 * anyone actually asks of them — what did yesterday look like, is this month
 * worse than last — was two date pickers away. The presets are the answer to
 * that; the pickers stay for the window a preset does not name.
 */
final class DateRangeFilter
{
    /** @var array<string, string> */
    private const PRESETS = [
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
        'month' => 'This month',
        'last_month' => 'Last month',
    ];

    public static function make(string $column = 'created_at', string $name = 'created'): Filter
    {
        return Filter::make($name)
            ->schema([
                Select::make('preset')
                    ->label('Period')
                    ->options(self::PRESETS)
                    ->placeholder('Custom range')
                    ->native(false),
                DatePicker::make('from'),
                DatePicker::make('until'),
            ])
            ->query(function (Builder $query, array $data) use ($column): Builder {
                [$from, $until] = self::range($data);

                return $query
                    ->when($from, fn (Builder $q, string $date): Builder => $q->whereDate($column, '>=', $date))
                    ->when($until, fn (Builder $q, string $date): Builder => $q->whereDate($column, '<=', $date));
            })
            ->indicateUsing(function (array $data): ?string {
                $preset = $data['preset'] ?? null;

                if (filled($preset)) {
                    return self::PRESETS[$preset] ?? null;
                }

                [$from, $until] = [$data['from'] ?? null, $data['until'] ?? null];

                return match (true) {
                    filled($from) && filled($until) => "{$from} → {$until}",
                    filled($from) => "From {$from}",
                    filled($until) => "Until {$until}",
                    default => null,
                };
            });
    }

    /**
     * A preset wins over the pickers, so switching to one does not silently
     * intersect with a range left behind in them.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: ?string, 1: ?string}
     */
    private static function range(array $data): array
    {
        $today = Carbon::today();

        return match ($data['preset'] ?? null) {
            'today' => [$today->toDateString(), $today->toDateString()],
            'yesterday' => [
                $today->copy()->subDay()->toDateString(),
                $today->copy()->subDay()->toDateString(),
            ],
            // Inclusive of today. "Last 7 days" that stops at yesterday is a
            // window nobody asked for and every comparison is off by a day.
            '7d' => [$today->copy()->subDays(6)->toDateString(), $today->toDateString()],
            '30d' => [$today->copy()->subDays(29)->toDateString(), $today->toDateString()],
            'month' => [$today->copy()->startOfMonth()->toDateString(), $today->toDateString()],
            // subMonthNoOverflow, or "last month" on the 31st is this month.
            'last_month' => [
                $today->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(),
                $today->copy()->subMonthNoOverflow()->endOfMonth()->toDateString(),
            ],
            default => [$data['from'] ?? null, $data['until'] ?? null],
        };
    }
}
