<?php

use App\Models\Conversion;
use App\Services\Money\Money;
use App\Services\Wall\OfferFeed;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/MoneyFixtures.php';
require_once __DIR__.'/../Wall/WallFixtures.php';

/**
 * The wall quotes a reward before the conversion exists; the postback credits
 * one after. Two places computing the same formula is two places for it to
 * drift, and the user only ever sees the gap.
 *
 * Two divergences this deliberately does not pin, because neither is a bug:
 * the network can report a payout that is not the catalogue's, and the app's
 * coin rate can change between the render and the credit.
 */
it('credits exactly the reward the wall quoted', function (float $payout, float $revShare, float $rate) {
    Queue::fake();

    $click = moneyFixture(app: ['currency_rate' => $rate], publisher: ['rev_share' => $revShare]);

    $quoted = app(OfferFeed::class)->convert(
        partnerContext($click->app, $click->end_user_id),
        $payout,
    );

    firePostback(postbackDto($click, payout: $payout))->assertOk();

    expect(Money::format($quoted, Money::CURRENCY_SCALE))
        ->toBe(Conversion::query()->withoutGlobalScopes()->sole()->currency_amount);
})->with([
    // The clean case from docs/03: $0.13 at 70% and 1000 coins to the dollar.
    'round numbers' => [0.13, 70.00, 1000.0],
    // Where float arithmetic and bcmath part company. `round($p * $s/100 * $r, 2)`
    // quotes 38.89 here and the ledger credits 38.8850.
    'sub-cent payout' => [0.07, 55.55, 1000.0],
    'thirds' => [0.0035, 33.33, 100.0],
    // A coin worth more than a dollar, so the rounding lands in the other
    // direction.
    'rate below one' => [12.345, 68.75, 1.0],
]);
