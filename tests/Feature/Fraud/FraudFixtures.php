<?php

use App\Enums\FraudAction;
use App\Enums\FraudActionTaken;
use App\Enums\FraudStage;
use App\Fraud\Contracts\IpIntelligence;
use App\Fraud\FraudContext;
use App\Fraud\IpVerdict;
use App\Models\App;
use App\Models\Conversion;
use App\Models\FraudEvent;
use App\Models\FraudRule;
use App\Models\Offer;
use App\Models\OfferClick;
use App\Models\Publisher;
use App\Providers\FraudServiceProvider;
use Database\Factories\OfferClickFactory;

/**
 * Shared arrangement for the fraud suite.
 *
 * Pest only auto-loads `tests/Pest.php`, so each file in this directory
 * `require_once`s this one — same pattern as the money suite.
 */

/**
 * FraudServiceProvider is not in `bootstrap/providers.php` yet (orchestrator
 * TODO), so nothing registers the rule classes. Registering it here is exactly
 * what that one-line addition will do in production.
 */
function fraudBoot(): void
{
    app()->register(FraudServiceProvider::class);
}

/**
 * A publisher, an app, an offer and the click a rule is judging.
 *
 * @param  array<string, mixed>  $click
 * @param  array<string, mixed>  $app
 * @param  array<string, mixed>  $offer
 */
function fraudClick(array $click = [], array $app = [], array $offer = []): OfferClick
{
    fraudBoot();

    $owner = Publisher::factory()->create();
    $placement = App::factory()->create(['publisher_id' => $owner->id, ...$app]);
    $target = Offer::factory()->create($offer);

    return OfferClickFactory::new()->create([
        'app_id' => $placement->id,
        'publisher_id' => $owner->id,
        'offer_id' => $target->id,
        'network_id' => $target->network_id,
        ...$click,
    ]);
}

/**
 * The conversion that click produced.
 *
 * @param  array<string, mixed>  $attributes
 */
function fraudConversion(OfferClick $click, array $attributes = []): Conversion
{
    return Conversion::factory()->create([
        'app_id' => $click->app_id,
        'publisher_id' => $click->publisher_id,
        'offer_id' => $click->offer_id,
        'network_id' => $click->network_id,
        'offer_click_id' => $click->id,
        'click_id' => $click->click_id,
        'end_user_id' => $click->end_user_id,
        'country' => $click->country,
        'ip' => $click->ip,
        ...$attributes,
    ]);
}

/** The context a pre-click or post-conversion rule sees for that click. */
function fraudContext(OfferClick $subject, mixed ...$overrides): FraudContext
{
    $app = App::query()->withoutGlobalScopes()->findOrFail($subject->app_id);

    // Spread as one array, not named arguments plus a spread: a duplicated
    // name would be a fatal, and overriding a default is the whole point.
    return new FraudContext(...[
        'app' => $app,
        'endUserId' => $subject->end_user_id,
        'ip' => $subject->ip,
        'country' => $subject->country,
        'userAgent' => $subject->user_agent,
        'offer' => Offer::query()->find($subject->offer_id),
        'click' => $subject,
        ...$overrides,
    ]);
}

/**
 * One rule row. Tests create only the rows they need — seeding all seventeen
 * would have `ua_anomaly` blocking every fixture click and earlier blocks
 * polluting `auto_ban_threshold`'s window.
 *
 * @param  array<string, mixed>  $config
 */
function fraudRule(
    string $key,
    FraudStage $stage = FraudStage::PostConversion,
    FraudAction $action = FraudAction::Flag,
    array $config = [],
    int $weight = 10,
    bool $enabled = true,
): FraudRule {
    return FraudRule::query()->create([
        'key' => $key,
        'name' => $key,
        'description' => $key,
        'stage' => $stage,
        'enabled' => $enabled,
        'action' => $action,
        'score_weight' => $weight,
        'config' => $config,
    ]);
}

/** Blocking fraud events already sitting inside the auto-ban window. */
function fraudSeedBlocks(OfferClick $click, int $count): void
{
    $placement = App::query()->withoutGlobalScopes()->findOrFail($click->app_id);

    foreach (range(1, $count) as $n) {
        FraudEvent::query()->create([
            'rule_key' => "rule_{$n}",
            'stage' => FraudStage::PostConversion,
            'subject_type' => $click->getMorphClass(),
            'subject_id' => $click->getKey(),
            'app_id' => $placement->id,
            'publisher_id' => $placement->publisher_id,
            'end_user_id' => $click->end_user_id,
            'action_taken' => FraudActionTaken::Blocked,
            'context' => [],
            'created_at' => now()->subMinutes($n),
        ]);
    }
}

/** Swap the IP databases for a fixed verdict — they are not on disk anywhere. */
function fakeIpVerdict(IpVerdict $verdict): void
{
    app()->instance(IpIntelligence::class, new class($verdict) implements IpIntelligence
    {
        public function __construct(private readonly IpVerdict $verdict) {}

        public function inspect(string $ip, bool $consented = false): IpVerdict
        {
            return $this->verdict;
        }
    });
}

/**
 * As `fakeIpVerdict`, but a different answer per address.
 *
 * The post-conversion IP rules compare the click's address with the
 * conversion's, so a fake that returns one verdict for every IP cannot express
 * the case they exist for.
 *
 * @param  array<string, IpVerdict>  $byIp
 */
function fakeIpVerdicts(array $byIp, ?IpVerdict $default = null): void
{
    app()->instance(IpIntelligence::class, new class($byIp, $default ?? IpVerdict::unknown()) implements IpIntelligence
    {
        /** @param array<string, IpVerdict> $byIp */
        public function __construct(private readonly array $byIp, private readonly IpVerdict $default) {}

        public function inspect(string $ip, bool $consented = false): IpVerdict
        {
            return $this->byIp[$ip] ?? $this->default;
        }
    });
}
