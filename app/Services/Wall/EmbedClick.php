<?php

namespace App\Services\Wall;

use App\Enums\FraudStage;
use App\Fraud\FraudPipeline;
use App\Models\Network;
use App\Models\OfferClick;

/**
 * The click row behind an embedded partner wall — docs/03 §2, steps 10–11.
 *
 * There is no offer to click, but `ConversionProcessor` attributes a
 * conversion entirely off a click: app, publisher, end user, country, IP,
 * is_test. So the row is what a partner's postback resolves back to, and its
 * `click_id` is the sub-id we hand the partner.
 *
 * One row per (app, partner, end user, is_test), reused on every later open in
 * the same mode. Three reasons: `offer_clicks` stays append-only, the partner
 * keeps a stable sub-id across sessions, and the busiest table we own does not
 * gain a row every time someone taps between tabs.
 *
 * `is_test` is part of that identity rather than a value written once, because
 * it is the flag that decides whether a conversion writes a ledger entry. A row
 * first opened live and then reused after the publisher flips `test_mode` would
 * keep earning real money from an overridden country; a row first opened during
 * a publisher's test would stop a real user on the same end_user_id from ever
 * earning again. At most two rows per tuple is the price, and the sub-id stays
 * stable within each mode.
 *
 * The rule is best effort, not a constraint — no unique index can cover that
 * tuple while real clicks share the table, so two simultaneous first opens race
 * into two rows. Harmless: attribution keys on whichever click_id the partner
 * echoes back, and both point at the same app and end user.
 */
final class EmbedClick
{
    public function __construct(private readonly FraudPipeline $pipeline) {}

    /**
     * @return array{OfferClick, bool} The row, and whether this open is blocked.
     */
    public function open(WallContext $context, Network $network): array
    {
        // Evaluated on every open, not only on the first: the row is a log of
        // first contact, the verdict is about now.
        $decision = $this->pipeline->evaluate(FraudStage::PreClick, $context->fraudContext());

        // `withoutGlobalScopes()` because `OfferClick` carries
        // `BelongsToPublisher`, and the wall is stateless — there is no
        // authenticated publisher for that scope to resolve against.
        // `ConversionProcessor` resolves clicks the same way.
        $click = OfferClick::query()->withoutGlobalScopes()->firstOrCreate(
            [
                'app_id' => $context->app->id,
                'network_id' => $network->id,
                'end_user_id' => $context->endUserId,
                'offer_id' => null,
                // Matched on, not merely written: this is the flag
                // `ConversionProcessor` copies onto the conversion to decide
                // whether a ledger entry is written, so a row must never carry
                // one mode's answer into the other. The reasoning from
                // ClickController applies unchanged for the value itself — the
                // country override is honoured only in test mode, but test mode
                // is a publisher-editable toggle on a live app and the parameter
                // is readable off the iframe URL. Without it, an app left in
                // test mode lets anyone earn real money from a country the
                // targeting never allowed. There is no test offer on this path,
                // so the override is the whole rule.
                'is_test' => $context->testMode && $context->countryOverridden,
            ],
            [
                // `click_id` is left to the model — OfferClick::booted() mints
                // the UUID on create.
                'publisher_id' => $context->app->publisher_id,
                'ip' => $context->app->anonymize_ip ? IpAnonymizer::mask($context->ip) : $context->ip,
                'country' => $context->country,
                'user_agent' => $context->userAgent,
                'device_type' => $context->device,
                'os' => $context->os,
                'browser' => $context->browser,
                'referrer' => $context->referrer,
                'risk_score' => $decision->score,
                'blocked' => $decision->blocked,
                'blocked_reason' => $decision->reason,
            ],
        );

        return [$click, $decision->blocked];
    }
}
