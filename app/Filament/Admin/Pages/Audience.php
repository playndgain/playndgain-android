<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Audience\AverageDauMetric;
use App\Filament\Admin\Audience\DailyActiveChart;
use App\Filament\Admin\Audience\MauMetric;
use App\Filament\Admin\Audience\RangeSummary;
use App\Filament\Admin\Audience\StickinessMetric;
use App\Filament\Admin\Audience\TodayDauMetric;
use App\Filament\Admin\Audience\TopCountries;
use App\Filament\Admin\Audience\YesterdayDauMetric;
use App\Models\App;
use App\Models\Publisher;
use App\Services\Reporting\AudienceWindow;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;
use UnitEnum;

/**
 * Unique end users from the live wall identity ledger — docs for the numbers
 * are on AudienceQuery. Deliberately no network filter: audience is a wall
 * fact, and this page stays readable in a screenshot without naming one.
 */
class Audience extends Dashboard
{
    use HasFiltersForm;

    protected static string $routePath = '/audience';

    protected static ?string $title = 'Audience';

    protected static ?string $navigationLabel = 'Audience';

    protected static string|UnitEnum|null $navigationGroup = 'Overview';

    protected static ?int $navigationSort = 3;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    public function persistsFiltersInSession(): bool
    {
        return false;
    }

    public function getSubheading(): string
    {
        $now = CarbonImmutable::now();

        return 'Unique end users per app from live wall sessions. Last updated '.$now->format('j M Y, H:i T').'.';
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('range')
                ->label('Range')
                ->helperText('Moves the countries and range summary only.')
                ->options(AudienceWindow::RANGES)
                ->default('30d')
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
            Select::make('app_id')
                ->label('App')
                ->options(fn (Get $get): array => App::query()
                    ->withoutGlobalScopes()
                    ->when(is_numeric($get('publisher_id')), fn ($query) => $query->where('publisher_id', (int) $get('publisher_id')))
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->placeholder('All apps')
                ->native(false),
        ]);
    }

    /**
     * @return array<class-string<Widget> | WidgetConfiguration>
     */
    public function getWidgets(): array
    {
        return [
            TodayDauMetric::class,
            YesterdayDauMetric::class,
            AverageDauMetric::class,
            MauMetric::class,
            StickinessMetric::class,
            DailyActiveChart::class,
            TopCountries::class,
            RangeSummary::class,
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
            'xl' => 5,
        ];
    }
}
