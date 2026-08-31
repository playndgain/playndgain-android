<?php

namespace App\Providers;

use App\Fraud\ChainedIpIntelligence;
use App\Fraud\Contracts\IpIntelligence;
use App\Fraud\Contracts\Rule;
use App\Fraud\MaxMindIpIntelligence;
use App\Fraud\Providers\Contracts\IpProviderDriver;
use App\Fraud\Providers\IpProviderRegistry;
use App\Fraud\Providers\IpQualityScoreProvider;
use App\Fraud\Providers\ProxyCheckProvider;
use App\Fraud\RuleRegistry;
use App\Fraud\Rules\AppDailyCapRule;
use App\Fraud\Rules\AutoBanThresholdRule;
use App\Fraud\Rules\BannedEndUserRule;
use App\Fraud\Rules\ClickVelocityRule;
use App\Fraud\Rules\ConversionGeoMismatchRule;
use App\Fraud\Rules\DatacenterIpRule;
use App\Fraud\Rules\DuplicateConversionRule;
use App\Fraud\Rules\GeoMismatchRule;
use App\Fraud\Rules\ImpossibleSpeedRule;
use App\Fraud\Rules\IpRiskScoreRule;
use App\Fraud\Rules\IpVelocityRule;
use App\Fraud\Rules\NoClickMatchRule;
use App\Fraud\Rules\PublisherSpikeRule;
use App\Fraud\Rules\RepeatOfferCompletionRule;
use App\Fraud\Rules\ResidentialProxyIpRule;
use App\Fraud\Rules\TorIpRule;
use App\Fraud\Rules\UaAnomalyRule;
use App\Fraud\Rules\UserDailyCapRule;
use App\Fraud\Rules\UserDailyPayoutCapRule;
use App\Fraud\Rules\VpnProxyIpRule;
use Illuminate\Support\ServiceProvider;

/**
 * Every rule class, and every IP provider driver, registers itself here,
 * mirroring NetworkServiceProvider. Each carries its own key, so one is added
 * in exactly one place and the key can never drift from `fraud_rules.key` or
 * `ip_providers.key`.
 *
 * Rules are listed in `FraudRuleSeeder` order — the seeder is the contract,
 * and a diffable pair is how a missing rule is spotted.
 */
class FraudServiceProvider extends ServiceProvider
{
    /** @var list<class-string<Rule>> */
    private const RULES = [
        // --- Pre-click ------------------------------------------------------
        BannedEndUserRule::class,
        DatacenterIpRule::class,
        VpnProxyIpRule::class,
        TorIpRule::class,
        ResidentialProxyIpRule::class,
        IpRiskScoreRule::class,
        GeoMismatchRule::class,
        ClickVelocityRule::class,
        IpVelocityRule::class,
        UaAnomalyRule::class,

        // --- Post-conversion ------------------------------------------------
        DuplicateConversionRule::class,
        RepeatOfferCompletionRule::class,
        ImpossibleSpeedRule::class,
        NoClickMatchRule::class,
        ConversionGeoMismatchRule::class,
        AppDailyCapRule::class,
        UserDailyCapRule::class,
        UserDailyPayoutCapRule::class,
        PublisherSpikeRule::class,
        AutoBanThresholdRule::class,
    ];

    /** @var list<class-string<IpProviderDriver>> */
    private const PROVIDERS = [
        ProxyCheckProvider::class,
        IpQualityScoreProvider::class,
    ];

    public function register(): void
    {
        $this->app->singleton(IpProviderRegistry::class);

        // Wraps the reader rather than replacing it — the local databases still
        // answer every address they can, for free, before anything is spent.
        $this->app->bind(IpIntelligence::class, fn ($app): IpIntelligence => new ChainedIpIntelligence(
            $app->make(MaxMindIpIntelligence::class),
            $app->make(IpProviderRegistry::class),
        ));
    }

    public function boot(): void
    {
        $registry = $this->app->make(RuleRegistry::class);

        foreach (self::RULES as $rule) {
            $registry->register($rule);
        }

        $providers = $this->app->make(IpProviderRegistry::class);

        foreach (self::PROVIDERS as $provider) {
            $providers->register($provider);
        }
    }
}
