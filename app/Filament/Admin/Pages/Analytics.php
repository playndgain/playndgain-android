<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Analytics\ChargebackCountMetric;
use App\Filament\Admin\Analytics\ChargebackPayoutMetric;
use App\Filament\Admin\Analytics\ChargebackSources;
use App\Filament\Admin\Analytics\ClickConversionFunnel;
use App\Filament\Admin\Analytics\ClicksMetric;
use App\Filament\Admin\Analytics\ConversionRateMetric;
use App\Filament\Admin\Analytics\ConversionsByStatus;
use App\Filament\Admin\Analytics\ConversionsMetric;
use App\Filament\Admin\Analytics\FraudByAction;
use App\Filament\Admin\Analytics\FraudByRule;
use App\Filament\Admin\Analytics\FraudSources;
use App\Filament\Admin\Analytics\LatestFraudEvents;
use App\Filament\Admin\Analytics\PayoutMetric;
use App\Filament\Admin\Analytics\ProfitMetric;
use App\Filament\Admin\Analytics\PublisherProfitVariance;
use App\Filament\Admin\Analytics\RevenueByApp;
use App\Filament\Admin\Analytics\RevenueByCountry;
use App\Filament\Admin\Analytics\RevenueByNetwork;
use App\Filament\Admin\Analytics\RevenueByPublisher;
use App\Filament\Admin\Analytics\RevenueMetric;
use App\Filament\Admin\Analytics\RevenueVsPayoutChart;
use App\Filament\Admin\Analytics\ReversalRateMetric;
use App\Filament\Publisher\Pages\Statistics;
use App\Models\App;
use App\Models\DailyStat;
use App\Models\Network;
use App\Models\Publisher;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;
use UnitEnum;

class Analytics extends Dashboard
{
    use HasFiltersForm;

    protected static string $routePath = '/analytics';

    protected static ?string $title = 'Analytics';

    protected static ?string $navigationLabel = 'Analytics';

    protected static string|UnitEnum|null $navigationGroup = 'Overview';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    public function persistsFiltersInSession(): bool
    {
        return false;
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('range')
                ->label('Range')
                ->options([
                    'today' => 'Today',
                    '7d' => 'Last 7 days',
                    '30d' => 'Last 30 days',
                    'month' => 'This month',
                    'last_month' => 'Last month',
                ])
                ->default('7d')
                ->selectablePlaceholder(false)
                ->native(false),
            Select::make('publisher_id')
                ->label('Publisher')
                ->options(fn (): array => Publisher::query()
                    ->withoutGlobalScopes()
                    ->with('user:id,name')
                    ->orderBy('pub_id')
                    ->get()
                    ->mapWithKeys(fn (Publisher $publisher): array => [
                        $publisher->id => "#{$publisher->pub_id} {$publisher->user?->name}",
                    ])
                    ->all())
                ->searchable()
                ->placeholder('All publishers')
                ->native(false),
            Select::make('network_id')
                ->label('Network')
                ->options(fn (): array => Network::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->placeholder('All networks')
                ->native(false),
            Select::make('app_id')
                ->label('App')
                ->options(fn (): array => App::query()
                    ->withoutGlobalScopes()
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->placeholder('All apps')
                ->native(false),
            Select::make('countries')
                ->label('Country')
                ->options(fn (): array => DailyStat::query()
                    ->withoutGlobalScopes()
                    ->whereNotNull('country')
                    ->distinct()
                    ->orderBy('country')
                    ->pluck('country')
                    ->mapWithKeys(fn (string $code): array => [$code => Statistics::countryLabel($code)])
                    ->all())
                ->multiple()
                ->searchable()
                ->placeholder('All countries')
                ->native(false),
        ]);
    }

    /**
     * @return array<class-string<Widget> | WidgetConfiguration>
     */
    public function getWidgets(): array
    {
        return [
            RevenueMetric::class,
            PayoutMetric::class,
            ProfitMetric::class,
            ClicksMetric::class,
            ConversionsMetric::class,
            ConversionRateMetric::class,
            ReversalRateMetric::class,
            ChargebackCountMetric::class,
            ChargebackPayoutMetric::class,
            RevenueVsPayoutChart::class,
            ClickConversionFunnel::class,
            ConversionsByStatus::class,
            RevenueByNetwork::class,
            RevenueByPublisher::class,
            RevenueByApp::class,
            RevenueByCountry::class,
            PublisherProfitVariance::class,
            ChargebackSources::class,
            FraudByAction::class,
            FraudByRule::class,
            FraudSources::class,
            LatestFraudEvents::class,
        ];
    }

    /**
     * @return int | array<string, ?int>
     */
    public function getColumns(): int|array
    {
        return [
            'default' => 1,
            'md' => 2,
            'xl' => 4,
        ];
    }
}
