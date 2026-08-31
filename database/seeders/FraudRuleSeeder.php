<?php

namespace Database\Seeders;

use App\Enums\FraudAction;
use App\Enums\FraudStage;
use App\Models\FraudRule;
use Illuminate\Database\Seeder;

/**
 * The day-1 rule set from docs/05-fraud-engine.md §3.
 *
 * Most rules start at `flag` rather than `block` on purpose: early on, a
 * blocked legitimate user churns the publisher, while a fraudulent conversion
 * costs one payout. Promote to `block` once the data supports it.
 *
 * Re-running this seeder inserts missing rules and leaves existing rows alone,
 * so it never resets a threshold an operator tuned in the admin panel.
 */
class FraudRuleSeeder extends Seeder
{
    /**
     * @var list<array{key: string, name: string, description: string, stage: FraudStage, action: FraudAction, score_weight: int, config: array<string, mixed>}>
     */
    private const RULES = [
        // --- Pre-click ------------------------------------------------------
        [
            'key' => 'banned_end_user',
            'name' => 'Banned end user',
            'description' => 'User banned by the publisher or auto-banned by us.',
            'stage' => FraudStage::PreClick,
            'action' => FraudAction::Block,
            'score_weight' => 40,
            'config' => [],
        ],
        [
            'key' => 'datacenter_ip',
            'name' => 'Datacenter IP',
            'description' => 'Click from AWS/GCP/Azure/OVH/Hetzner. A datacenter IP on a mobile install offer is a bot with near-certainty.',
            'stage' => FraudStage::PreClick,
            'action' => FraudAction::Block,
            'score_weight' => 40,
            'config' => ['source' => 'geolite2_asn'],
        ],
        [
            'key' => 'vpn_proxy_ip',
            'name' => 'VPN or proxy IP',
            'description' => 'Consumer VPN or unclassified proxy exit node. Blocks at wall render: the user is told they are on a VPN, the fix is one toggle, and a flagged VPN conversion is paid out and then charged back.',
            'stage' => FraudStage::PreClick,
            'action' => FraudAction::Block,
            'score_weight' => 40,
            'config' => ['provider' => 'ip2proxy_lite'],
        ],
        [
            'key' => 'tor_ip',
            'name' => 'Tor exit node',
            'description' => 'Click originates from a Tor exit node. Nobody completes a mobile install offer over Tor by accident.',
            'stage' => FraudStage::PreClick,
            'action' => FraudAction::Block,
            'score_weight' => 45,
            'config' => [],
        ],
        [
            'key' => 'residential_proxy_ip',
            'name' => 'Residential proxy',
            'description' => 'Rented access to somebody else\'s home connection — the standard transport for an install farm, because it defeats both the hosting-ASN list and a country check.',
            'stage' => FraudStage::PreClick,
            'action' => FraudAction::Block,
            'score_weight' => 40,
            'config' => [],
        ],
        [
            'key' => 'ip_risk_score',
            'name' => 'IP risk score',
            'description' => 'A third-party risk score over the threshold. Flags rather than blocks until the score has been calibrated against this platform\'s own traffic.',
            'stage' => FraudStage::PreClick,
            'action' => FraudAction::Flag,
            'score_weight' => 25,
            'config' => ['threshold' => 85],
        ],
        [
            'key' => 'geo_mismatch',
            'name' => 'Geo mismatch',
            'description' => "Resolved country is not in the offer's targeting.",
            'stage' => FraudStage::PreClick,
            'action' => FraudAction::Block,
            'score_weight' => 30,
            'config' => [],
        ],
        [
            'key' => 'click_velocity',
            'name' => 'Click velocity',
            'description' => 'Too many clicks from one end user inside a window.',
            'stage' => FraudStage::PreClick,
            'action' => FraudAction::Flag,
            'score_weight' => 15,
            'config' => ['max' => 20, 'window_minutes' => 5],
        ],
        [
            'key' => 'ip_velocity',
            'name' => 'IP velocity',
            'description' => 'Too many distinct end users from one IP.',
            'stage' => FraudStage::PreClick,
            'action' => FraudAction::Flag,
            'score_weight' => 15,
            'config' => ['max_users' => 10, 'window_hours' => 24],
        ],
        [
            'key' => 'ua_anomaly',
            'name' => 'User agent anomaly',
            'description' => 'Missing, malformed, or known-bot user agent.',
            'stage' => FraudStage::PreClick,
            'action' => FraudAction::Block,
            'score_weight' => 35,
            'config' => ['bot_signatures' => ['curl', 'wget', 'python-requests', 'headlesschrome', 'phantomjs', 'scrapy', 'bot', 'spider']],
        ],

        // --- Post-conversion ------------------------------------------------
        [
            'key' => 'duplicate_conversion',
            'name' => 'Duplicate conversion',
            'description' => 'Same network transaction ID. The database constraint already stops the double payment; this rule exists so the attempt is recorded and explainable.',
            'stage' => FraudStage::PostConversion,
            'action' => FraudAction::Block,
            'score_weight' => 25,
            'config' => [],
        ],
        [
            'key' => 'repeat_offer_completion',
            'name' => 'Repeat offer completion',
            'description' => 'Same end user completing a non-multi-reward offer twice.',
            'stage' => FraudStage::PostConversion,
            'action' => FraudAction::Block,
            'score_weight' => 30,
            'config' => [],
        ],
        [
            'key' => 'impossible_speed',
            'name' => 'Impossible conversion speed',
            'description' => 'Conversion arriving implausibly fast after the click. 15s is deliberately low — some legitimate offers convert in twenty seconds, so this flags and the operator raises it per offer type once they see their own data.',
            'stage' => FraudStage::PostConversion,
            'action' => FraudAction::Flag,
            'score_weight' => 20,
            'config' => ['min_seconds' => 15],
        ],
        [
            'key' => 'no_click_match',
            'name' => 'No matching click',
            'description' => 'Conversion with no resolvable click.',
            'stage' => FraudStage::PostConversion,
            'action' => FraudAction::Flag,
            'score_weight' => 20,
            'config' => [],
        ],
        [
            'key' => 'conversion_geo_mismatch',
            'name' => 'Conversion geo mismatch',
            'description' => 'Conversion IP country differs from the click IP country.',
            'stage' => FraudStage::PostConversion,
            'action' => FraudAction::Flag,
            'score_weight' => 15,
            'config' => [],
        ],
        [
            'key' => 'app_daily_cap',
            'name' => 'App daily conversion cap',
            'description' => 'App exceeded its configured daily conversion cap. Threshold comes from apps.daily_conversion_cap.',
            'stage' => FraudStage::PostConversion,
            'action' => FraudAction::Block,
            'score_weight' => 25,
            'config' => [],
        ],
        [
            'key' => 'user_daily_cap',
            'name' => 'End user daily conversion cap',
            'description' => 'One end user over the daily conversion limit.',
            'stage' => FraudStage::PostConversion,
            'action' => FraudAction::Flag,
            'score_weight' => 20,
            'config' => ['max' => 25],
        ],
        [
            'key' => 'user_daily_payout_cap',
            'name' => 'End user daily payout cap',
            'description' => 'One end user over a daily USD ceiling.',
            'stage' => FraudStage::PostConversion,
            'action' => FraudAction::Flag,
            'score_weight' => 25,
            'config' => ['max_usd' => 50],
        ],
        [
            'key' => 'publisher_spike',
            'name' => 'Publisher conversion spike',
            'description' => "Publisher's conversion rate far above their own 7-day baseline.",
            'stage' => FraudStage::PostConversion,
            'action' => FraudAction::Flag,
            'score_weight' => 20,
            'config' => ['multiplier' => 5, 'min_conversions' => 50],
        ],
        [
            'key' => 'auto_ban_threshold',
            'name' => 'Auto-ban threshold',
            'description' => 'End user accumulated too many blocking events. Bans expire after 7 days — a permanent automatic ban on a shared mobile IP eventually bans a real user forever and nobody finds out why.',
            'stage' => FraudStage::PostConversion,
            'action' => FraudAction::Block,
            'score_weight' => 50,
            'config' => ['blocks' => 5, 'window_hours' => 24],
        ],
    ];

    public function run(): void
    {
        foreach (self::RULES as $rule) {
            // firstOrCreate, not updateOrCreate: re-seeding must never reset a
            // threshold the operator tuned in the admin panel.
            FraudRule::query()->firstOrCreate(
                ['key' => $rule['key']],
                [
                    'name' => $rule['name'],
                    'description' => $rule['description'],
                    'stage' => $rule['stage'],
                    'enabled' => true,
                    'action' => $rule['action'],
                    'score_weight' => $rule['score_weight'],
                    'config' => $rule['config'],
                ],
            );
        }
    }
}
