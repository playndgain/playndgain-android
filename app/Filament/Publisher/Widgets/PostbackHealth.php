<?php

namespace App\Filament\Publisher\Widgets;

use App\Enums\OutgoingPostbackStatus;
use App\Filament\Publisher\Support\CurrentPublisher;
use App\Models\OutgoingPostback;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

/**
 * 24-hour delivery health — docs/08 §9.
 *
 * Amber below 95%, red below 80%. A publisher whose endpoint is quietly
 * failing should see it here before they see it in a balance they cannot
 * reconcile.
 */
class PostbackHealth extends StatsOverviewWidget
{
    /**
     * Belongs to the postback reports page and nowhere else. Discovery would
     * also mount it on the Dashboard, whose rows docs/08 §3 spells out and
     * which this is not one of.
     */
    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Postback health — last 24 hours';

    protected function getStats(): array
    {
        $attempts = $this->query()->count();

        if ($attempts === 0) {
            return [
                Stat::make('Attempts', '0')->description('Nothing sent in the last 24 hours'),
            ];
        }

        $succeeded = (clone $this->query())->where('status', OutgoingPostbackStatus::Success)->count();
        $failed = (clone $this->query())
            ->whereIn('status', [OutgoingPostbackStatus::Failed, OutgoingPostbackStatus::Retrying])
            ->count();

        $rate = $succeeded / $attempts * 100;
        $average = (int) round((float) (clone $this->query())->avg('duration_ms'));

        return [
            Stat::make('Success rate', number_format($rate, 1).'%')
                ->color(match (true) {
                    $rate < 80 => 'danger',
                    $rate < 95 => 'warning',
                    default => 'success',
                })
                ->description("{$succeeded} of {$attempts} attempts"),
            Stat::make('Failures', number_format($failed))
                ->color($failed > 0 ? 'danger' : 'gray'),
            Stat::make('Average response', $average.' ms')
                ->description($average > 5000 ? 'Slow — we time out at 15s' : 'Within budget')
                ->color($average > 5000 ? 'warning' : 'gray'),
        ];
    }

    /** @return Builder<OutgoingPostback> */
    private function query(): Builder
    {
        return OutgoingPostback::query()
            ->whereHas('app', fn (Builder $query) => $query->where('publisher_id', CurrentPublisher::id()))
            ->where('created_at', '>=', now()->subDay());
    }
}
