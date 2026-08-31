<?php

use App\Enums\BannedBy;
use App\Enums\ProxyType;
use App\Fraud\IpVerdict;
use App\Fraud\Rules\BannedEndUserRule;
use App\Fraud\Rules\ClickVelocityRule;
use App\Fraud\Rules\DatacenterIpRule;
use App\Fraud\Rules\GeoMismatchRule;
use App\Fraud\Rules\IpVelocityRule;
use App\Fraud\Rules\UaAnomalyRule;
use App\Fraud\Rules\VpnProxyIpRule;
use App\Models\BannedEndUser;
use Database\Factories\OfferClickFactory;

require_once __DIR__.'/FraudFixtures.php';

// --- banned_end_user ---------------------------------------------------------

it('banned_end_user triggers on an active ban for that publisher', function () {
    $click = fraudClick();

    BannedEndUser::query()->create([
        'publisher_id' => $click->publisher_id,
        'app_id' => null,
        'end_user_id' => $click->end_user_id,
        'banned_by' => BannedBy::Publisher,
        'reason' => 'chargebacks',
        'expires_at' => null,
    ]);

    $result = app(BannedEndUserRule::class)->evaluate(fraudContext($click), []);

    expect($result->triggered)->toBeTrue()
        ->and($result->context['ban_scope'])->toBe('publisher')
        ->and($result->context['banned_by'])->toBe('publisher');
});

it('banned_end_user does not trigger on a ban that has expired', function () {
    $click = fraudClick();

    BannedEndUser::query()->create([
        'publisher_id' => $click->publisher_id,
        'app_id' => null,
        'end_user_id' => $click->end_user_id,
        'banned_by' => BannedBy::System,
        'expires_at' => now()->subMinute(),
    ]);

    expect(app(BannedEndUserRule::class)->evaluate(fraudContext($click), [])->triggered)->toBeFalse();
});

// --- datacenter_ip -----------------------------------------------------------

it('datacenter_ip triggers on a hosting ASN', function () {
    $click = fraudClick();
    fakeIpVerdict(new IpVerdict(country: 'US', asn: 16509, organization: 'AMAZON-02', isDatacenter: true));

    $result = app(DatacenterIpRule::class)->evaluate(fraudContext($click), ['source' => 'geolite2_asn']);

    expect($result->triggered)->toBeTrue()
        ->and($result->context['asn'])->toBe(16509)
        ->and($result->context['matched'])->toBe('hosting_list');
});

it('datacenter_ip does not trigger on a consumer ASN', function () {
    $click = fraudClick();
    fakeIpVerdict(new IpVerdict(country: 'US', asn: 7922, organization: 'COMCAST'));

    expect(app(DatacenterIpRule::class)->evaluate(fraudContext($click), [])->triggered)->toBeFalse();
});

it('datacenter_ip picks up an ASN added to the rule config without a deploy', function () {
    $click = fraudClick();
    fakeIpVerdict(new IpVerdict(country: 'US', asn: 999999, organization: 'NEW-HOST'));

    $result = app(DatacenterIpRule::class)->evaluate(fraudContext($click), ['asns' => [999999]]);

    expect($result->triggered)->toBeTrue()
        ->and($result->context['matched'])->toBe('config_asns');
});

it('datacenter_ip triggers on a DCH proxy type no hosting ASN covers', function () {
    $click = fraudClick();
    fakeIpVerdict(new IpVerdict(asn: 4242, proxyType: ProxyType::Datacenter));

    $result = app(DatacenterIpRule::class)->evaluate(fraudContext($click), []);

    expect($result->triggered)->toBeTrue()
        ->and($result->context['matched'])->toBe('proxy_type');
});

// --- vpn_proxy_ip ------------------------------------------------------------

it('vpn_proxy_ip triggers on a known exit node', function () {
    $click = fraudClick();
    fakeIpVerdict(new IpVerdict(country: 'NL', asn: 9009, organization: 'M247', isProxy: true));

    $result = app(VpnProxyIpRule::class)->evaluate(fraudContext($click), ['provider' => 'ip2proxy_lite']);

    expect($result->triggered)->toBeTrue()
        ->and($result->context['provider'])->toBe('ip2proxy_lite');
});

it('vpn_proxy_ip does not trigger on an address the proxy database does not know', function () {
    $click = fraudClick();
    fakeIpVerdict(new IpVerdict(country: 'NL', asn: 1136));

    expect(app(VpnProxyIpRule::class)->evaluate(fraudContext($click), [])->triggered)->toBeFalse();
});

// --- geo_mismatch ------------------------------------------------------------

it('geo_mismatch triggers when the resolved country is outside the targeting', function () {
    $click = fraudClick(offer: ['countries' => ['US', 'CA']]);

    $result = app(GeoMismatchRule::class)->evaluate(fraudContext($click, country: 'de'), []);

    expect($result->triggered)->toBeTrue()
        ->and($result->context['resolved_country'])->toBe('DE')
        ->and($result->context['offer_countries'])->toBe(['US', 'CA']);
});

it('geo_mismatch does not trigger on a worldwide offer', function () {
    $click = fraudClick(offer: ['countries' => []]);

    expect(app(GeoMismatchRule::class)->evaluate(fraudContext($click, country: 'DE'), [])->triggered)->toBeFalse();
});

it('geo_mismatch does not trigger on an address the databases cannot place', function () {
    $click = fraudClick(offer: ['countries' => ['US']]);
    fakeIpVerdict(IpVerdict::unknown());

    expect(app(GeoMismatchRule::class)->evaluate(fraudContext($click, country: null), [])->triggered)->toBeFalse();
});

// --- click_velocity ----------------------------------------------------------

it('click_velocity triggers once the window is full', function () {
    $click = fraudClick();

    OfferClickFactory::new()->count(4)->create([
        'app_id' => $click->app_id,
        'publisher_id' => $click->publisher_id,
        'offer_id' => $click->offer_id,
        'network_id' => $click->network_id,
        'end_user_id' => $click->end_user_id,
    ]);

    $result = app(ClickVelocityRule::class)
        ->evaluate(fraudContext($click), ['max' => 5, 'window_minutes' => 5]);

    expect($result->triggered)->toBeTrue()
        ->and($result->context['clicks_in_window'])->toBe(5)
        ->and($result->context['max'])->toBe(5);
});

it('click_velocity does not count clicks outside the window', function () {
    $click = fraudClick(['created_at' => now()->subHour()]);

    OfferClickFactory::new()->count(9)->create([
        'app_id' => $click->app_id,
        'publisher_id' => $click->publisher_id,
        'offer_id' => $click->offer_id,
        'network_id' => $click->network_id,
        'end_user_id' => $click->end_user_id,
        'created_at' => now()->subHour(),
    ]);

    expect(app(ClickVelocityRule::class)
        ->evaluate(fraudContext($click), ['max' => 5, 'window_minutes' => 5])->triggered)->toBeFalse();
});

// --- ip_velocity -------------------------------------------------------------

it('ip_velocity triggers when one IP carries more distinct users than the maximum', function () {
    $click = fraudClick(['ip' => '203.0.113.9']);

    foreach (range(1, 3) as $n) {
        OfferClickFactory::new()->create([
            'app_id' => $click->app_id,
            'publisher_id' => $click->publisher_id,
            'offer_id' => $click->offer_id,
            'network_id' => $click->network_id,
            'end_user_id' => "other_{$n}",
            'ip' => '203.0.113.9',
        ]);
    }

    $result = app(IpVelocityRule::class)
        ->evaluate(fraudContext($click), ['max_users' => 3, 'window_hours' => 24]);

    expect($result->triggered)->toBeTrue()
        ->and($result->context['distinct_users'])->toBe(4);
});

it('ip_velocity does not trigger at exactly the maximum', function () {
    $click = fraudClick(['ip' => '203.0.113.9']);

    foreach (range(1, 2) as $n) {
        OfferClickFactory::new()->create([
            'app_id' => $click->app_id,
            'publisher_id' => $click->publisher_id,
            'offer_id' => $click->offer_id,
            'network_id' => $click->network_id,
            'end_user_id' => "other_{$n}",
            'ip' => '203.0.113.9',
        ]);
    }

    expect(app(IpVelocityRule::class)
        ->evaluate(fraudContext($click), ['max_users' => 3, 'window_hours' => 24])->triggered)->toBeFalse();
});

// --- ua_anomaly --------------------------------------------------------------

it('ua_anomaly triggers on a configured bot signature', function () {
    $click = fraudClick(['user_agent' => 'python-requests/2.31.0 automated harvester']);

    $result = app(UaAnomalyRule::class)
        ->evaluate(fraudContext($click), ['bot_signatures' => ['curl', 'python-requests']]);

    expect($result->triggered)->toBeTrue()
        ->and($result->context['anomaly'])->toBe('bot_signature')
        ->and($result->context['matched_signature'])->toBe('python-requests');
});

it('ua_anomaly triggers on a missing user agent', function () {
    $click = fraudClick(['user_agent' => null]);

    expect(app(UaAnomalyRule::class)->evaluate(fraudContext($click), [])->context['anomaly'])->toBe('missing');
});

it('ua_anomaly does not trigger on an ordinary browser agent', function () {
    $click = fraudClick(['user_agent' => 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/126.0 Mobile Safari/537.36']);

    expect(app(UaAnomalyRule::class)->evaluate(fraudContext($click), [
        // The seeded list verbatim — a real device agent must survive it.
        'bot_signatures' => ['curl', 'wget', 'python-requests', 'headlesschrome', 'phantomjs', 'scrapy', 'bot', 'spider'],
    ])->triggered)->toBeFalse();
});
