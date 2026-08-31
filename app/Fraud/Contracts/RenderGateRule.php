<?php

namespace App\Fraud\Contracts;

use App\Fraud\RuleResult;

/**
 * A rule whose only input is the `IpVerdict`, and which may therefore refuse a
 * wall render rather than waiting for a click.
 *
 * The marker exists so the render gate cannot accidentally run a rule that
 * needs a click. `click_velocity` at render would count tab opens as clicks;
 * `banned_end_user` at render would duplicate a check `WallGate` already does.
 * Implementing this interface is the assertion that neither applies.
 *
 * The notice key is the screen the end user sees. Several rules share one on
 * purpose — the screen is a category, not a verdict, and telling a user which
 * rule caught them is what the fraud engine spends its effort not doing.
 */
interface RenderGateRule extends Rule
{
    /**
     * A `wall.notice.*` key, or null to let the render through even though
     * the rule triggered.
     *
     * Takes the rule's own `RuleResult` because only the rule knows how it
     * matched — the gate cannot tell a conclusive trigger from a fallback one
     * without re-deriving the verdict itself, which defeats the point of
     * asking the rule at all. `VpnProxyIpRule` is the reason this takes a
     * parameter: it still triggers on an unclassified proxy (a stale
     * database, a terse provider) because that is worth a `fraud_event` and a
     * click-time flag, but null there so the render is not refused on a
     * signal too weak to act on at that cost. A key here never contains the
     * rule key.
     */
    public function noticeKey(RuleResult $result): ?string;
}
