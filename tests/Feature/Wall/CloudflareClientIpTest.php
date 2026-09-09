<?php

use App\Enums\FraudAction;
use App\Enums\FraudStage;
use App\Enums\ProxyType;
use App\Fraud\Contracts\IpIntelligence;
use App\Fraud\IpVerdict;
use App\Models\FraudRule;
use App\Providers\FraudServiceProvider;

require_once __DIR__.'/WallFixtures.php';

/**
 * Production sits behind Cloudflare. Without restoring the visitor IP from
 * `CF-Connecting-IP`, WallGate asks ProxyCheck about the edge hop — which
 * answers "Cloudflare, Inc., not a VPN" — and the render gate never fires.
 */
function cloudflareVpnRule(): void
{
    FraudRule::query()->create([
        'key' => 'vpn_proxy_ip',
        'name' => 'vpn_proxy_ip',
        'description' => 'vpn_proxy_ip',
        'stage' => FraudStage::PreClick,
        'enabled' => true,
        'action' => FraudAction::Block,
        'score_weight' => 40,
        'config' => [],
    ]);
}

function cloudflareWallVerdicts(string $visitorIp, string $edgeIp): void
{
    app()->register(FraudServiceProvider::class);

    app()->instance(IpIntelligence::class, new class($visitorIp, $edgeIp) implements IpIntelligence
    {
        public function __construct(
            private readonly string $visitorIp,
            private readonly string $edgeIp,
        ) {}

        public function inspect(string $ip, bool $consented = false): IpVerdict
        {
            if ($ip === $this->visitorIp) {
                return new IpVerdict(country: 'US', isProxy: true, proxyType: ProxyType::Vpn);
            }

            // What ProxyCheck actually returns for a Cloudflare edge today.
            if ($ip === $this->edgeIp) {
                return new IpVerdict(
                    country: 'US',
                    asn: 13335,
                    organization: 'Cloudflare, Inc.',
                    isProxy: false,
                    source: 'proxycheck',
                );
            }

            return new IpVerdict(country: 'US');
        }
    });
}

it('blocks a VPN visitor when the TCP peer is a Cloudflare edge', function () {
    [$app] = wallFixture();
    cloudflareWallVerdicts(visitorIp: '203.0.113.50', edgeIp: '172.70.110.34');
    cloudflareVpnRule();

    test()->withServerVariables(['REMOTE_ADDR' => '172.70.110.34'])
        ->withHeaders([
            'User-Agent' => WALL_UA,
            'CF-Connecting-IP' => '203.0.113.50',
        ])
        ->get(wallUrl($app))
        ->assertForbidden()
        ->assertSee(__('wall.notice.blocked_proxy.title'));
});

it('ignores a spoofed CF-Connecting-IP when the peer is not Cloudflare', function () {
    [$app] = wallFixture();
    cloudflareWallVerdicts(visitorIp: '203.0.113.50', edgeIp: '172.70.110.34');
    cloudflareVpnRule();

    // Direct-to-origin client claiming to be a VPN via a forged header must
    // not be able to force (or dodge) the render gate — the peer IP wins.
    test()->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
        ->withHeaders([
            'User-Agent' => WALL_UA,
            'CF-Connecting-IP' => '203.0.113.50',
        ])
        ->get(wallUrl($app))
        ->assertOk()
        ->assertDontSee(__('wall.notice.blocked_proxy.title'));
});
