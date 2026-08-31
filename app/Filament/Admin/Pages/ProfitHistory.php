<?php

namespace App\Filament\Admin\Pages;

use App\Models\Network;
use App\Models\Publisher;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The P&L view — docs/07 §6.
 *
 * Plain aggregate rows rather than a Filament table: every row here is a
 * GROUP BY result with no model behind it, and a resource table over that
 * fights the framework for no gain.
 */
class ProfitHistory extends Page implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|\UnitEnum|null $navigationGroup = 'Money';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Profit history';

    protected string $view = 'filament.admin.pages.profit-history';

    /** day | month */
    public string $period = 'day';

    /** none | network | publisher */
    public string $groupBy = 'none';

    public int $days = 30;

    public function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Export CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action(fn (): StreamedResponse => $this->export()),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getRowsProperty(): array
    {
        $bucket = $this->period === 'month'
            ? "DATE_FORMAT(date, '%Y-%m')"
            : 'DATE(date)';

        $query = DB::table('daily_stats')
            ->selectRaw("{$bucket} AS bucket")
            ->selectRaw('SUM(conversions) AS conversions')
            ->selectRaw('SUM(reversals) AS reversals')
            ->selectRaw('SUM(revenue) AS revenue')
            ->selectRaw('SUM(payout) AS payout')
            ->where('date', '>=', CarbonImmutable::today()->subDays(max(1, $this->days))->toDateString())
            ->groupByRaw($bucket)
            ->orderByDesc('bucket');

        $dimension = match ($this->groupBy) {
            'network' => 'network_id',
            'publisher' => 'publisher_id',
            default => null,
        };

        if ($dimension !== null) {
            $query->addSelect($dimension)->groupBy($dimension);
        }

        $labels = $this->labels($dimension);

        return collect($query->get())->map(function (object $row) use ($dimension, $labels): array {
            $revenue = (string) $row->revenue;
            $payout = (string) $row->payout;
            $profit = bcsub($revenue, $payout, 6);
            $conversions = (int) $row->conversions;
            $reversals = (int) $row->reversals;

            return [
                'bucket' => (string) $row->bucket,
                'group' => $dimension === null ? '—' : ($labels[$row->{$dimension}] ?? '—'),
                'conversions' => $conversions,
                'reversals' => $reversals,
                'reversal_rate' => $conversions + $reversals === 0
                    ? '—'
                    : number_format($reversals / ($conversions + $reversals) * 100, 1).'%',
                'revenue' => number_format((float) $revenue, 2),
                'payout' => number_format((float) $payout, 2),
                'profit' => number_format((float) $profit, 2),
                'margin' => bccomp($revenue, '0', 6) === 0
                    ? '—'
                    : number_format((float) bcdiv(bcmul($profit, '100', 6), $revenue, 4), 1).'%',
            ];
        })->values()->all();
    }

    private function export(): StreamedResponse
    {
        $rows = $this->getRowsProperty();
        $name = 'profit-'.CarbonImmutable::today()->toDateString().'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['period', 'group', 'conversions', 'reversals', 'reversal_rate', 'revenue', 'payout', 'profit', 'margin']);

            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $name, ['Content-Type' => 'text/csv']);
    }

    /**
     * @return array<int, string>
     */
    private function labels(?string $dimension): array
    {
        return match ($dimension) {
            'network_id' => Network::query()->pluck('name', 'id')->all(),
            'publisher_id' => Publisher::query()
                ->with('user:id,name')
                ->get()
                ->mapWithKeys(fn (Publisher $publisher): array => [
                    $publisher->id => "#{$publisher->pub_id} {$publisher->user?->name}",
                ])
                ->all(),
            default => [],
        };
    }
}
