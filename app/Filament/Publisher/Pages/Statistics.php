<?php

namespace App\Filament\Publisher\Pages;

use App\Filament\Publisher\Support\CurrentPublisher;
use App\Models\App;
use App\Models\DailyStat;
use App\Services\Reporting\StatsQuery;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use Locale;

/**
 * Reporting — docs/08 §6.
 *
 * Reads `daily_stats`, never raw click rows. A publisher and an admin looking
 * at the same day have to see the same number, and that only holds while both
 * read the same table.
 */
class Statistics extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.publisher.pages.statistics';

    /** today | 7d | 30d | month | last_month */
    #[Url]
    public string $range = '7d';

    #[Url]
    public ?int $appId = null;

    /** @var list<string> ISO-2 codes; empty means every country. */
    #[Url]
    public array $countries = [];

    /**
     * The only Filament-schema field on this page — a multi-select needs search,
     * chips and keyboard handling that a native <select multiple> does not give.
     * The schema has no statePath, so the component binds straight to
     * $this->countries and keeps its #[Url] state.
     */
    public function countryFilter(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('countries')
                ->hiddenLabel()
                ->placeholder('All countries')
                ->options(fn (): array => $this->getCountryOptionsProperty())
                ->multiple()
                ->searchable()
                ->native(false)
                ->live(),
        ]);
    }

    /**
     * Every country this publisher has ever had a row for — deliberately not
     * narrowed by the current range or app, or picking one would delete its own
     * option the moment the range moved off it.
     *
     * @return array<string, string>
     */
    public function getCountryOptionsProperty(): array
    {
        return DailyStat::query()
            ->withoutGlobalScopes()
            ->where('publisher_id', CurrentPublisher::id())
            ->whereNotNull('country')
            ->distinct()
            ->orderBy('country')
            ->pluck('country')
            ->mapWithKeys(fn (string $code): array => [$code => self::countryLabel($code)])
            ->all();
    }

    /** "US — United States". ext-intl is not in composer's require, so guard it. */
    public static function countryLabel(string $code): string
    {
        $name = match (true) {
            // The user-assigned code the wall writes for unknown geo — docs/08 §6.
            $code === 'XX' => 'Other',
            class_exists(Locale::class) => Locale::getDisplayRegion("-{$code}", 'en'),
            default => $code,
        };

        return $name === $code ? $code : "{$code} — {$name}";
    }

    /** @return array<string, string> */
    public function getRangeOptionsProperty(): array
    {
        return [
            'today' => 'Today',
            '7d' => 'Last 7 days',
            '30d' => 'Last 30 days',
            'month' => 'This month',
            'last_month' => 'Last month',
        ];
    }

    /** @return array<int, string> */
    public function getAppOptionsProperty(): array
    {
        return App::query()
            ->where('publisher_id', CurrentPublisher::id())
            ->pluck('name', 'id')
            ->all();
    }

    /** @return array{clicks: int, conversions: int, reversals: int, revenue: string, payout: string, profit: string} */
    public function getTotalsProperty(): array
    {
        return $this->query()->totals();
    }

    /** @return list<array{date: string, clicks: int, conversions: int, earnings: string}> */
    public function getSeriesProperty(): array
    {
        [$from, $to] = $this->window();

        return $this->query()
            ->daily($from, $to)
            ->map(fn (object $row): array => [
                'date' => (string) $row->date,
                'clicks' => (int) $row->clicks,
                'conversions' => (int) $row->conversions,
                'earnings' => (string) $row->payout,
            ])
            ->values()
            ->all();
    }

    /**
     * Breakdowns by app and country. "By offer" and "by device" are both
     * deliberately absent: `daily_stats` carries neither dimension — its
     * unique key is (date, publisher, app, network, country) — and reaching
     * past it into `offer_clicks` to fake one is exactly the ad-hoc SUM
     * docs/06 §7 forbids. "By network" is absent on purpose: which supplier
     * paid a row is ours, not the publisher's.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function getBreakdownsProperty(): array
    {
        $apps = $this->getAppOptionsProperty();

        return [
            'By app' => $this->breakdown('app_id', fn (string $key): string => $apps[(int) $key] ?? "App {$key}"),
            'By country' => $this->breakdown('country', self::countryLabel(...)),
        ];
    }

    /**
     * @param  callable(string): string  $label
     * @return list<array<string, mixed>>
     */
    private function breakdown(string $column, callable $label): array
    {
        return $this->query()
            ->groupedBy($column, 25)
            ->map(function (object $row) use ($label): array {
                $clicks = (int) $row->clicks;
                $conversions = (int) $row->conversions;

                return [
                    'label' => $label((string) $row->bucket),
                    'clicks' => $clicks,
                    'conversions' => $conversions,
                    'cr' => $clicks === 0 ? '—' : number_format($conversions / $clicks * 100, 2).'%',
                    'earnings' => number_format((float) $row->payout, 2),
                ];
            })
            ->values()
            ->all();
    }

    private function query(): StatsQuery
    {
        [$from, $to] = $this->window();

        return StatsQuery::make()
            ->forPublisher(CurrentPublisher::id())
            ->forApp($this->appId)
            ->forCountries($this->countries)
            ->between($from, $to);
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function window(): array
    {
        $today = CarbonImmutable::today();

        return match ($this->range) {
            'today' => [$today, $today],
            '30d' => [$today->subDays(29), $today],
            'month' => [$today->startOfMonth(), $today],
            'last_month' => [$today->subMonth()->startOfMonth(), $today->subMonth()->endOfMonth()],
            default => [$today->subDays(6), $today],
        };
    }
}
