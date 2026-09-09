<?php

namespace App\Filament\Publisher\Pages;

use App\Filament\Publisher\Resources\Apps\AppResource;
use App\Filament\Publisher\Support\CurrentPublisher;
use App\Models\App;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * The dashboard header docs/08 §3 specifies and the stock page has none of:
 * a welcome line, an app selector, a live clock and a Live indicator.
 *
 * The selector is a page filter rather than a widget-local control, because
 * "which app am I looking at" has to mean the same thing to every card at once
 * — four widgets each with their own selector is four ways to be looking at
 * different things while believing you are not.
 */
class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static string|BackedEnum|null $navigationIcon = 'lucide-layout-dashboard';

    protected static string|\UnitEnum|null $navigationGroup = 'Overview';

    protected static ?int $navigationSort = 1;

    public function getTitle(): string|Htmlable
    {
        return 'Dashboard';
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            // A publisher with no apps yet gets the one action that makes the
            // selector meaningful, not a list that opens on "No options".
            Html::make(fn (): Htmlable => new HtmlString(view('filament.publisher.dashboard-no-apps', [
                // An admin looking in has no publisher account, so no app can
                // be created from here — the banner above already says so.
                'url' => CurrentPublisher::get() === null ? null : AppResource::getUrl('create'),
            ])->render()))
                ->hidden(fn (): bool => $this->hasApps()),
            Select::make('app_id')
                ->visible(fn (): bool => $this->hasApps())
                ->label('Viewing')
                ->inlineLabel()
                ->helperText('Every figure below sums across all your apps until you pick one.')
                ->options(fn (): array => App::query()
                    ->where('publisher_id', CurrentPublisher::id())
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                // Unlike the Offers page — where eligibility is per app and an
                // "all" view would be meaningless — every figure here sums
                // cleanly across placements, so all apps is the right default.
                ->placeholder('All apps')
                ->native(false),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            // One card carries the whole header: the greeting on the first
            // row, the app selector on the second — at the start of the line,
            // where the eye lands, not stranded in a second column.
            Grid::make(1)
                ->extraAttributes(['class' => 'pg-dash-head'])
                ->schema([
                    Html::make(fn (): Htmlable => $this->welcomeLine()),
                    $this->getFiltersFormContentComponent(),
                ]),
            $this->getWidgetsContentComponent(),
        ]);
    }

    protected function hasApps(): bool
    {
        return App::query()->where('publisher_id', CurrentPublisher::id())->exists();
    }

    /**
     * The clock ticks client-side in the publisher's own timezone — the same
     * one FilamentTimezone renders every other date in. A server-rendered
     * timestamp would be wrong one second after the page loaded, and a polling
     * Livewire clock would be a round trip per second for a decoration.
     */
    protected function welcomeLine(): Htmlable
    {
        $publisher = CurrentPublisher::get();
        $user = Filament::auth()->user();

        return new HtmlString(view('filament.publisher.dashboard-header', [
            'name' => $user instanceof User ? $user->name : 'there',
            'timezone' => $publisher === null ? (string) config('app.timezone') : $publisher->resolvedTimezone(),
        ])->render());
    }
}
