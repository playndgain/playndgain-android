<?php

namespace App\Fraud\Rules;

use App\Enums\FraudStage;
use App\Fraud\Contracts\IpIntelligence;
use App\Fraud\Contracts\RenderGateRule;
use App\Fraud\FraudContext;
use App\Fraud\RuleResult;

/**
 * A third-party risk score over a configured threshold.
 *
 * The only rule here with no local signal behind it: the LITE IP2Proxy file
 * carries no fraud score, so this fires exactly when a provider answered and
 * scored the address. A null score is "nobody scored it", which is neither
 * zero nor clean — an address nobody rated must not be treated as rated well.
 *
 * Seeded at `flag`, deliberately. It depends entirely on a vendor's number,
 * and blocking on a number nobody has calibrated against this platform's own
 * traffic is blocking revenue. The Protection page shows the distribution.
 */
class IpRiskScoreRule implements RenderGateRule
{
    private const DEFAULT_THRESHOLD = 85;

    public function __construct(private readonly IpIntelligence $intelligence) {}

    public static function key(): string
    {
        return 'ip_risk_score';
    }

    public static function stage(): FraudStage
    {
        return FraudStage::PreClick;
    }

    /** A score over threshold is always conclusive — no fallback path to withhold a render for. */
    public function noticeKey(RuleResult $result): ?string
    {
        return 'blocked_risk';
    }

    public function evaluate(FraudContext $context, array $config): RuleResult
    {
        if (blank($context->ip)) {
            return RuleResult::pass();
        }

        // Reuse the verdict the render gate already resolved for this
        // request rather than asking again — see FraudContext::$verdict.
        $verdict = $context->verdict ?? $this->intelligence->inspect($context->ip);
        $threshold = is_numeric($config['threshold'] ?? null)
            ? (int) $config['threshold']
            : self::DEFAULT_THRESHOLD;

        if ($verdict->riskScore === null || $verdict->riskScore < $threshold) {
            return RuleResult::pass();
        }

        return RuleResult::trigger('third-party risk score over threshold', [
            'ip' => $context->ip,
            'score' => $verdict->riskScore,
            'threshold' => $threshold,
            'country' => $verdict->country,
            'source' => $verdict->source,
        ]);
    }
}
