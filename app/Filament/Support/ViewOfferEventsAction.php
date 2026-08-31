<?php

namespace App\Filament\Support;

use App\Filament\Publisher\Support\CurrentPublisher;
use App\Filament\Publisher\Support\PublisherPayout;
use App\Models\App;
use App\Models\Offer;
use App\Models\Setting;
use App\Support\OfferEvents;
use App\Support\UserReward;
use Closure;
use Filament\Actions\Action;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

/**
 * The events behind an offer — admin and publisher, one slide-over.
 *
 * The wall already itemises `offers.goals`. The catalogue tables did not, so a
 * publisher deciding what to run (and an admin deciding what to feature) had
 * to open the tracking URL and guess. Both panels share this action so the
 * list cannot drift; only the payout column changes.
 *
 * Publisher-facing payouts go through PublisherPayout. The network figure is
 * what the next renewal would be negotiated against, and it must not appear
 * on that side of the glass.
 *
 * Points are the same figure the wall prints. The publisher side uses the
 * selected app. The admin side has no app, so it uses the platform example
 * (default rev share × 1,000 Coins / $1) rather than inventing one of theirs.
 */
final class ViewOfferEventsAction
{
    public static function make(bool $publisherFacing = false, ?Closure $resolveApp = null): Action
    {
        return Action::make('events')
            ->label('View events')
            ->icon(Heroicon::OutlinedListBullet)
            ->color('gray')
            ->slideOver()
            ->modalWidth(Width::Large)
            ->modalHeading(fn (Offer $record): string => $record->name)
            ->modalDescription($publisherFacing
                ? 'What the user has to do, what each event pays you, and what it pays them.'
                : 'What the user has to do, what each event pays us, and the example user reward.')
            ->modalContent(fn (Offer $record) => view(
                'filament.offers.events',
                self::viewData($record, $publisherFacing, self::app($resolveApp)),
            ))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->badge(fn (Offer $record): ?string => ($count = OfferEvents::count($record)) > 1
                ? (string) $count
                : null);
    }

    /**
     * @return array{offer: Offer, events: list<array{name: string, payout: string, points: ?string}>, publisherFacing: bool, rewardNote: ?string}
     */
    public static function viewData(Offer $record, bool $publisherFacing = false, ?App $app = null): array
    {
        return [
            'offer' => $record,
            'events' => self::rows($record, $publisherFacing, $app),
            'publisherFacing' => $publisherFacing,
            'rewardNote' => self::rewardNote($publisherFacing, $app),
        ];
    }

    /**
     * @return list<array{name: string, payout: string, points: ?string}>
     */
    private static function rows(Offer $record, bool $publisherFacing, ?App $app): array
    {
        // The slide-over can evaluate after Filament has dropped the session
        // publisher. The selected app still knows who owns it, so the share
        // comes from that row rather than CurrentPublisher alone — otherwise
        // every event prints an em dash and the "+—" prefix reads as "+-".
        $publisher = $publisherFacing
            ? (CurrentPublisher::get() ?? $app?->publisher)
            : null;

        $revShare = $publisherFacing
            ? $publisher?->rev_share
            : Setting::get('default_rev_share', config('advault.default_rev_share'));

        $rate = $app?->currency_rate;
        $currency = $app?->currency_name;

        if (! $publisherFacing && $app === null) {
            $rate = UserReward::PREVIEW_RATE;
            $currency = UserReward::PREVIEW_CURRENCY;
        }

        return array_map(
            function (array $event) use ($publisherFacing, $publisher, $revShare, $rate, $currency): array {
                $points = ($revShare !== null && $rate !== null && filled($currency))
                    ? UserReward::label($event['payout'], $revShare, $rate, $currency)
                    : null;

                return [
                    'name' => $event['name'],
                    'payout' => $publisherFacing
                        ? PublisherPayout::label($event['payout'], $publisher)
                        : '$'.number_format($event['payout'], 4),
                    'points' => $points,
                ];
            },
            OfferEvents::of($record),
        );
    }

    private static function rewardNote(bool $publisherFacing, ?App $app): ?string
    {
        if ($publisherFacing) {
            return $app === null
                ? null
                : 'User reward uses this app currency.';
        }

        return $app === null
            ? 'User reward is an example at the default rev share and 1,000 Coins / $1.'
            : null;
    }

    private static function app(?Closure $resolveApp): ?App
    {
        if ($resolveApp === null) {
            return null;
        }

        $app = $resolveApp();

        return $app instanceof App ? $app : null;
    }
}
