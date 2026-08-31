<?php

namespace App\Fraud\Rules;

use App\Enums\FraudStage;
use App\Fraud\Contracts\Rule;
use App\Fraud\FraudContext;
use App\Fraud\RuleResult;
use App\Fraud\Rules\Concerns\CountsConversions;
use App\Models\OfferClick;
use Carbon\CarbonInterface;

/**
 * Publisher's conversion rate far above their own 7-day baseline — docs/05 §3.
 *
 * Rate, not volume. A publisher who genuinely tripled their traffic is not
 * fraud; a publisher whose conversion rate went from two percent to forty is,
 * and only the ratio separates them. The baseline is the publisher's own —
 * offerwall conversion rates differ by an order of magnitude between a survey
 * app and a game, so a platform-wide baseline would flag whole verticals.
 */
class PublisherSpikeRule implements Rule
{
    use CountsConversions;

    public static function key(): string
    {
        return 'publisher_spike';
    }

    public static function stage(): FraudStage
    {
        return FraudStage::PostConversion;
    }

    public function evaluate(FraudContext $context, array $config): RuleResult
    {
        $multiplier = (float) ($config['multiplier'] ?? 5);
        $minConversions = (int) ($config['min_conversions'] ?? 50);
        $baselineDays = (int) ($config['baseline_days'] ?? 7);

        $publisherId = $context->publisherId();
        $todayStart = now()->startOfDay();
        $baselineStart = $todayStart->copy()->subDays($baselineDays);

        $todayConversions = $this->conversions($context, $publisherId, $todayStart, null);

        // Below the floor there is no rate, only noise. Fifty conversions is
        // the seeded floor precisely so a publisher's first ten do not look
        // like a hundredfold spike.
        if ($todayConversions < $minConversions) {
            return RuleResult::pass();
        }

        $todayClicks = $this->clicks($publisherId, $todayStart, null);
        $baselineConversions = $this->conversions($context, $publisherId, $baselineStart, $todayStart);
        $baselineClicks = $this->clicks($publisherId, $baselineStart, $todayStart);

        // No history and no traffic to divide by. A publisher with no baseline
        // has nothing to be "far above", and flagging their first day is how
        // you churn a publisher who did nothing wrong.
        if ($todayClicks === 0 || $baselineClicks === 0 || $baselineConversions === 0) {
            return RuleResult::pass();
        }

        $todayRate = $todayConversions / $todayClicks;
        $baselineRate = $baselineConversions / $baselineClicks;

        if ($todayRate <= $baselineRate * $multiplier) {
            return RuleResult::pass();
        }

        return RuleResult::trigger('publisher conversion rate far above their own baseline', [
            'today_clicks' => $todayClicks,
            'today_conversions' => $todayConversions,
            'today_rate' => round($todayRate, 6),
            'baseline_clicks' => $baselineClicks,
            'baseline_conversions' => $baselineConversions,
            'baseline_rate' => round($baselineRate, 6),
            'multiplier' => $multiplier,
            'baseline_days' => $baselineDays,
            'min_conversions' => $minConversions,
            'publisher_id' => $publisherId,
        ]);
    }

    private function conversions(FraudContext $context, int $publisherId, CarbonInterface $from, ?CarbonInterface $to): int
    {
        return $this->countable($context)
            ->where('publisher_id', $publisherId)
            ->where('created_at', '>=', $from)
            ->when($to !== null, fn ($query) => $query->where('created_at', '<', $to))
            ->count();
    }

    private function clicks(int $publisherId, CarbonInterface $from, ?CarbonInterface $to): int
    {
        return OfferClick::query()
            ->withoutGlobalScopes()
            ->where('publisher_id', $publisherId)
            ->where('is_test', false)
            ->where('created_at', '>=', $from)
            ->when($to !== null, fn ($query) => $query->where('created_at', '<', $to))
            ->count();
    }
}
